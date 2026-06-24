<?php

/**
 * Translates the project's SQLite-dialect SQL into MySQL/MariaDB dialect.
 *
 * The whole schema (db/db.sql + db/migrations/*) is authored once in SQLite
 * dialect. Rather than maintaining a parallel MySQL schema, {@see MysqlDriver}
 * runs every DDL/DML statement through this translator, so a single source of
 * truth stays in sync across backends.
 *
 * The translator is stateful on purpose: SQLite happily indexes TEXT columns,
 * but MySQL cannot index a TEXT/BLOB column without a prefix length and refuses
 * foreign keys on them. So any TEXT column that participates in an index, a
 * UNIQUE/PRIMARY/FOREIGN constraint becomes VARCHAR(191); every other TEXT
 * column becomes LONGTEXT (to hold large JSON). When a table and its indexes
 * are created in separate statements (migrations), columns first emitted as
 * LONGTEXT are widened back with an ALTER … MODIFY before the index is created.
 */
class SqlDialect
{
    private const VARCHAR = 'VARCHAR(191)';

    /** table => [column => "<modifiers>"] for columns emitted as LONGTEXT. */
    private array $longtext = [];

    /** table => [column => true] columns known to participate in an index/key. */
    private array $indexed = [];

    /**
     * Translate one or more SQLite statements into MySQL.
     *
     * Accepts a single statement (migrations) or a multi-statement script
     * (db/db.sql). Returns statements joined by ";\n"; callers that need to run
     * them must split on ";" (see {@see MysqlDriver::runStatements()}).
     */
    public function toMysql(string $sql): string
    {
        $statements = $this->split($sql);

        // Pass 1: learn every (table,column) referenced by an index/constraint
        // in this batch so CREATE TABLE in the same batch can size them right.
        foreach ($statements as $stmt) {
            $this->collectIndexed($stmt);
        }

        $out = [];
        foreach ($statements as $stmt) {
            $translated = $this->translateStatement($stmt);
            if (trim($translated) !== '') {
                $out[] = $translated;
            }
        }

        return implode(";\n", $out);
    }

    /**
     * Split a script into statements, dropping SQLite-only PRAGMA and explicit
     * transaction-control lines (the driver manages transactions itself).
     */
    private function split(string $sql): array
    {
        $lines = preg_split('/\r?\n/', $sql) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(PRAGMA|BEGIN|COMMIT|END\s+TRANSACTION|END;?\s*$)/i', $line)) {
                continue;
            }
            $kept[] = $line;
        }
        $clean = implode("\n", $kept);

        $parts = explode(';', $clean);
        $statements = [];
        foreach ($parts as $part) {
            if (trim($part) !== '') {
                $statements[] = trim($part);
            }
        }
        return $statements;
    }

    /** Record columns referenced by a CREATE INDEX or inline table constraints. */
    private function collectIndexed(string $stmt): void
    {
        if (preg_match('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?\S+\s+ON\s+(\S+)\s*\(([^)]*)\)/i', $stmt, $m)) {
            $table = $this->ident($m[1]);
            foreach ($this->columnList($m[2]) as $col) {
                $this->indexed[$table][$col] = true;
            }
            return;
        }

        if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\S+)\s*\((.*)\)\s*$/is', $stmt, $m)) {
            $table = $this->ident($m[1]);
            foreach ($this->splitColumns($m[2]) as $part) {
                if (preg_match('/^\s*(?:PRIMARY\s+KEY|UNIQUE|FOREIGN\s+KEY)\s*\(([^)]*)\)/i', $part, $cm)) {
                    foreach ($this->columnList($cm[1]) as $col) {
                        $this->indexed[$table][$col] = true;
                    }
                } elseif (preg_match('/^\s*\S+\s+.*\b(PRIMARY\s+KEY|UNIQUE)\b/i', $part)) {
                    $col = $this->ident(preg_split('/\s+/', trim($part))[0]);
                    $this->indexed[$table][$col] = true;
                }
            }
        }
    }

    private function translateStatement(string $stmt): string
    {
        if (preg_match('/^\s*CREATE\s+TABLE\s+/i', $stmt)) {
            return $this->translateCreateTable($stmt);
        }
        if (preg_match('/^\s*CREATE\s+(UNIQUE\s+)?INDEX\s+/i', $stmt)) {
            return $this->translateCreateIndex($stmt);
        }
        return $this->translateGeneric($stmt);
    }

    private function translateCreateTable(string $stmt): string
    {
        if (!preg_match('/^(\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)(\S+)\s*\((.*)\)\s*$/is', $stmt, $m)) {
            return $this->translateGeneric($stmt);
        }
        $head = $m[1];
        $rawTable = $m[2];
        $table = $this->ident($rawTable);
        $parts = $this->splitColumns($m[3]);

        $rendered = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(PRIMARY\s+KEY|UNIQUE|FOREIGN\s+KEY|CONSTRAINT|CHECK)\b/i', $part)) {
                $rendered[] = $this->translateConstraint($part);
                continue;
            }
            $rendered[] = $this->translateColumn($table, $part);
        }

        $body = implode(",\n  ", $rendered);
        return $head . $rawTable . " (\n  " . $body . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private function translateColumn(string $table, string $part): string
    {
        $tokens = preg_split('/\s+/', $part, 2);
        $name = $this->ident($tokens[0]);
        $rest = $tokens[1] ?? '';

        // Type is the first token of $rest (TEXT, INTEGER, NUMERIC, REAL ...).
        if (!preg_match('/^(\S+)(.*)$/s', $rest, $tm)) {
            return $part;
        }
        $type = strtoupper($tm[1]);
        $modifiers = trim($tm[2]);
        $modifiers = $this->translateModifiers($modifiers);

        $newType = match ($type) {
            'INTEGER', 'INT' => 'BIGINT',
            'NUMERIC', 'DECIMAL' => 'DECIMAL(20,6)',
            'REAL', 'DOUBLE', 'FLOAT' => 'DOUBLE',
            'TEXT' => $this->textType($table, $name, $modifiers),
            default => $type,
        };

        $line = $tokens[0] . ' ' . $newType;
        if ($modifiers !== '') {
            $line .= ' ' . $modifiers;
        }
        return $line;
    }

    /** Decide VARCHAR vs LONGTEXT for a TEXT column and remember LONGTEXT ones. */
    private function textType(string $table, string $col, string $modifiers): string
    {
        if (!empty($this->indexed[$table][$col])) {
            unset($this->longtext[$table][$col]);
            return self::VARCHAR;
        }
        $this->longtext[$table][$col] = $modifiers;
        return 'LONGTEXT';
    }

    private function translateConstraint(string $part): string
    {
        // AUTOINCREMENT never appears in constraints; just normalise spacing.
        return preg_replace('/\s+/', ' ', trim($part)) ?? $part;
    }

    private function translateModifiers(string $modifiers): string
    {
        $modifiers = preg_replace('/\bAUTOINCREMENT\b/i', 'AUTO_INCREMENT', $modifiers) ?? $modifiers;
        $modifiers = preg_replace('/\bCOLLATE\s+NOCASE\b/i', '', $modifiers) ?? $modifiers;
        return trim(preg_replace('/\s+/', ' ', $modifiers) ?? $modifiers);
    }

    private function translateCreateIndex(string $stmt): string
    {
        if (!preg_match('/^(\s*CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?)(\S+)\s+ON\s+(\S+)\s*\(([^)]*)\)/is', $stmt, $m)) {
            return $this->translateGeneric($stmt);
        }
        $table = $this->ident($m[3]);
        $cols = $this->columnList($m[4]);

        $alters = [];
        foreach ($cols as $col) {
            if (isset($this->longtext[$table][$col])) {
                $modifiers = $this->longtext[$table][$col];
                unset($this->longtext[$table][$col]);
                $def = self::VARCHAR . ($modifiers !== '' ? ' ' . $modifiers : '');
                $alters[] = "ALTER TABLE {$m[3]} MODIFY {$col} {$def}";
            }
            $this->indexed[$table][$col] = true;
        }

        $index = $m[1] . $m[2] . ' ON ' . $m[3] . ' (' . implode(', ', $cols) . ')';
        if ($alters === []) {
            return $index;
        }
        return implode(";\n", $alters) . ";\n" . $index;
    }

    /** DML / ALTER fallthrough: dialect keyword swaps only. */
    private function translateGeneric(string $stmt): string
    {
        $stmt = preg_replace('/\bINSERT\s+OR\s+IGNORE\b/i', 'INSERT IGNORE', $stmt) ?? $stmt;

        if (preg_match('/^\s*(CREATE|ALTER)\b/i', $stmt)) {
            $stmt = preg_replace('/\bAUTOINCREMENT\b/i', 'AUTO_INCREMENT', $stmt) ?? $stmt;
            $stmt = preg_replace('/\bINTEGER\b/i', 'BIGINT', $stmt) ?? $stmt;
            $stmt = preg_replace('/\bNUMERIC\b/i', 'DECIMAL(20,6)', $stmt) ?? $stmt;
            $stmt = preg_replace('/\bREAL\b/i', 'DOUBLE', $stmt) ?? $stmt;
            $stmt = preg_replace('/\bTEXT\b/i', 'LONGTEXT', $stmt) ?? $stmt;
        }
        return $stmt;
    }

    /** @return list<string> bare column names from a comma list (drops ASC/DESC). */
    private function columnList(string $csv): array
    {
        $cols = [];
        foreach (explode(',', $csv) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            $cols[] = $this->ident(preg_split('/\s+/', $piece)[0]);
        }
        return $cols;
    }

    /** Split a CREATE TABLE body on top-level commas (respecting parentheses). */
    private function splitColumns(string $body): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            }
            if ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') {
            $parts[] = $current;
        }
        return $parts;
    }

    private function ident(string $name): string
    {
        return strtolower(trim($name, " \t\n\r`\"'[]"));
    }
}
