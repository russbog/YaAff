<?php

/**
 * Pure serialization helpers for report data. Used by scheduled/CLI exports and
 * the dashboard "download" actions. No I/O: callers decide where bytes go.
 */
class ReportExporter
{
    /**
     * Flatten the nested tree returned by Db::get_statistics into flat rows,
     * one per leaf, carrying each grouping level as a column.
     *
     * @param array<int,array<string,mixed>> $tree
     * @param array<int,string> $groupBy ordered grouping field names
     * @return array<int,array<string,mixed>>
     */
    public static function flattenTree(array $tree, array $groupBy): array
    {
        $out = [];
        self::walk($tree, $groupBy, 0, [], $out);
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @param array<int,string> $groupBy
     * @param array<string,mixed> $carried
     * @param array<int,array<string,mixed>> $out
     */
    private static function walk(array $nodes, array $groupBy, int $level, array $carried, array &$out): void
    {
        foreach ($nodes as $node) {
            $row = $carried;
            if (isset($groupBy[$level]) && array_key_exists('group', $node)) {
                $row[$groupBy[$level]] = $node['group'];
            }
            if (!empty($node['_children']) && is_array($node['_children'])) {
                self::walk($node['_children'], $groupBy, $level + 1, $row, $out);
                continue;
            }
            foreach ($node as $k => $v) {
                if ($k === '_children' || $k === 'group') {
                    continue;
                }
                $row[$k] = $v;
            }
            $out[] = $row;
        }
    }

    /**
     * Render rows as CSV. Columns default to the union of keys across rows.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string>|null $columns
     */
    public static function toCsv(array $rows, ?array $columns = null): string
    {
        if ($columns === null) {
            $columns = self::columns($rows);
        }
        $lines = [self::csvLine($columns)];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $col) {
                $cells[] = self::scalar($row[$col] ?? '');
            }
            $lines[] = self::csvLine($cells);
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public static function toJson(array $rows): string
    {
        return (string)json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,string>
     */
    public static function columns(array $rows): array
    {
        $cols = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $k) {
                $cols[$k] = true;
            }
        }
        return array_keys($cols);
    }

    /**
     * @param array<int,mixed> $cells
     */
    private static function csvLine(array $cells): string
    {
        return implode(',', array_map([self::class, 'csvCell'], $cells));
    }

    private static function csvCell(mixed $value): string
    {
        $s = self::scalar($value);
        if (preg_match('/[",\r\n]/', $s)) {
            return '"' . str_replace('"', '""', $s) . '"';
        }
        return $s;
    }

    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return (string)json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        return (string)$value;
    }
}
