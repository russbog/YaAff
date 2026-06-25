<?php

require_once __DIR__ . "/../cookies.php";
require_once __DIR__ . "/../logging.php";
require_once __DIR__ . "/../settings.php";
require_once __DIR__ . "/../paths.php";
require_once __DIR__ . "/drivers/SqliteDriver.php";
require_once __DIR__ . "/drivers/MysqlDriver.php";
require_once __DIR__ . "/Migrator.php";
require_once __DIR__ . "/../domains/DomainMatcher.php";
require_once __DIR__ . "/../entities/Domain.php";

class Db
{
    private DbDriver $driver;

    public function __construct(?DbDriver $driver = null, ?string $dbPath = null)
    {
        if ($driver !== null) {
            $this->driver = $driver;
            $this->ensure_schema_migrations();
            $this->run_migrations();
            return;
        }

        global $cloSettings;

        if (($cloSettings['dbDriver'] ?? 'sqlite') === 'mysql') {
            $this->driver = new MysqlDriver($cloSettings['mysql'] ?? []);
            if (empty($this->driver->tableColumns('campaigns'))) {
                if (!$this->create_new_db())
                    die("Couldn't initialize the MySQL database! Read logs for additional info.");
            }
            $this->ensure_schema_migrations();
            $this->run_migrations();
            return;
        }

        $path = $dbPath ?? __DIR__ . '/' . $cloSettings['dbConnection'];
        $needsCreate = !file_exists($path);
        $this->driver = new SqliteDriver($path);
        if ($needsCreate) {
            if (!$this->create_new_db())
                die("Couldn't create the SQLite database! Read logs for additional info.");
        }
        $this->ensure_schema_migrations();
        $this->run_migrations();
    }

    /**
     * Apply any pending versioned migrations (Phase 1+ entity tables, etc.).
     * Non-fatal: a migration failure is logged but never blocks tracker boot.
     */
    private function run_migrations(): void
    {
        try {
            (new Migrator($this->driver))->migrate();
        } catch (Throwable $e) {
            add_log("errors", "Migration run failed: " . $e->getMessage());
        }
    }

    /** The database driver currently in use (SQLite by default). */
    public function driver(): DbDriver
    {
        return $this->driver;
    }

    private function ensure_schema_migrations(): void
    {
        $columns = $this->driver->tableColumns('clicks');
        if (!empty($columns) && !in_array('events', $columns, true)) {
            $this->driver->exec("ALTER TABLE clicks ADD COLUMN events TEXT DEFAULT '{}'");
        }

        $this->driver->exec(
            "CREATE TABLE IF NOT EXISTS click_event_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                clickid TEXT NOT NULL,
                time INTEGER NOT NULL,
                step_index INTEGER NOT NULL,
                event_name TEXT NOT NULL,
                event_value NUMERIC NOT NULL,
                FOREIGN KEY (clickid) REFERENCES clicks (clickid) ON DELETE CASCADE
            )"
        );
        $this->driver->exec('CREATE INDEX IF NOT EXISTS idx_event_clickid_time ON click_event_log (clickid,time)');
        $this->driver->exec('CREATE INDEX IF NOT EXISTS idx_event_name_time ON click_event_log (event_name,time)');
    }

    private static function decode_click_row(array &$click): void
    {
        if (array_key_exists('path', $click) && is_string($click['path']) && $click['path'] !== '') {
            $decodedPath = json_decode($click['path'], true);
            $click['path'] = is_array($decodedPath) ? $decodedPath : [];
        }

        foreach (['params', 'events'] as $jsonField) {
            if (!array_key_exists($jsonField, $click)) {
                continue;
            }
            if (empty($click[$jsonField])) {
                $click[$jsonField] = [];
                continue;
            }
            $decoded = json_decode($click[$jsonField], true);
            $click[$jsonField] = is_array($decoded) ? $decoded : [];
        }
    }

    private function create_new_db(): bool
    {
        try {
            $createTableSQL = @file_get_contents(__DIR__ . "/db.sql");
            if ($createTableSQL === false) {
                throw new Exception("Failed to read database schema file");
            }

            $settingsJson = @file_get_contents(__DIR__ . '/common.json');
            if ($settingsJson === false) {
                throw new Exception("Failed to read common settings file");
            }

            if ($this->driver->exec($createTableSQL) === false) {
                throw new Exception("Failed to execute database schema");
            }

            $this->driver->execute(
                "INSERT INTO common (settings) VALUES (:settings)",
                [':settings' => [$settingsJson, DbDriver::TEXT]]
            );

            add_log("trace", "Successfully initialized database with schema and common settings");
            return true;
        } catch (Throwable $e) {
            add_log("errors", "Failed to initialize database: " . $e->getMessage());
            return false;
        }
    }

    public function get_trafficback_clicks($startdate, $enddate): array
    {
        $query = "SELECT * FROM trafficback WHERE time BETWEEN :startDate AND :endDate ORDER BY time DESC";
        $clicks = $this->exec_read_query($query, [$startdate => DbDriver::INT, $enddate => DbDriver::INT]);
        foreach ($clicks as &$click) {
            if (empty($click['params']))
                continue;
            $click['params'] = json_decode($click['params'], true);
            if ($click['params'] === null && json_last_error() !== JSON_ERROR_NONE) {
                add_log("errors", "Failed to parse trafficback params JSON for row " . $click['id'] . ": " . json_last_error_msg());
                $click['params'] = [];
            }
        }
        return $clicks;
    }

    private function get_campaign_clicks(int $startdate, int $enddate, int $campId, bool $blocked = false): array
    {
        $query = "SELECT * FROM " . ($blocked ? "blocked" : "clicks") . " WHERE time BETWEEN :startDate AND :endDate AND campaign_id = :campid ORDER BY time DESC";
        $clicks = $this->exec_read_query($query, [$startdate => DbDriver::INT, $enddate => DbDriver::INT, $campId => DbDriver::INT]);
        foreach ($clicks as &$click) {
            if (!$blocked) {
                self::decode_click_row($click);
            } elseif (!empty($click['params'])) {
                $click['params'] = json_decode($click['params'], true);
                if ($click['params'] === null && json_last_error() !== JSON_ERROR_NONE) {
                    add_log("errors", "Failed to parse trafficback params JSON for row " . $click['id'] . ": " . json_last_error_msg());
                    $click['params'] = [];
                }
            }
        }
        return $clicks;
    }

    public function get_white_clicks(int $startdate, int $enddate, int $campId): array
    {
        return $this->get_campaign_clicks($startdate, $enddate, $campId, true);
    }
    public function get_black_clicks(int $startdate, int $enddate, int $campId): array
    {
        return $this->get_campaign_clicks($startdate, $enddate, $campId, false);
    }

    public function get_clicks_paginated(string $filter, int $startdate, int $enddate, ?int $campId, int $page, int $size, string $sortField = 'time', string $sortDir = 'desc', array $filters = [], array $paramColumns = [], string $searchTerm = ''): array
    {
        $allowedSort = ['id','time','ip','country','lang','os','osver','client','clientver','device','brand','model','isp','ua','userid','clickid','flow','path','step','status','payout','reason'];
        // Support sorting by param.* fields via json_extract
        $sortExpr = 'time';
        if (in_array($sortField, $allowedSort)) {
            $sortExpr = $sortField;
        } elseif (str_starts_with($sortField, 'param.')) {
            $key = substr($sortField, 6);
            if (preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                $sortExpr = $this->driver->jsonExtract('params', $key);
            }
        }
        $sortDir = strtolower($sortDir) === 'asc' ? 'ASC' : 'DESC';
        $offset = ($page - 1) * $size;

        switch ($filter) {
            case 'blocked':
                $table = 'blocked';
                $where = "time BETWEEN ? AND ? AND campaign_id = ?";
                $bindParams = [$startdate => DbDriver::INT, $enddate => DbDriver::INT, $campId => DbDriver::INT];
                break;
            case 'leads':
                $table = 'clicks';
                $where = "time BETWEEN ? AND ? AND campaign_id = ? AND status IS NOT NULL";
                $bindParams = [$startdate => DbDriver::INT, $enddate => DbDriver::INT, $campId => DbDriver::INT];
                break;
            case 'trafficback':
                $table = 'trafficback';
                $where = "time BETWEEN ? AND ?";
                $bindParams = [$startdate => DbDriver::INT, $enddate => DbDriver::INT];
                break;
            default: // allowed
                $table = 'clicks';
                $where = "time BETWEEN ? AND ? AND campaign_id = ?";
                $bindParams = [$startdate => DbDriver::INT, $enddate => DbDriver::INT, $campId => DbDriver::INT];
                break;
        }
        $tableFilterFields = match ($table) {
            'blocked' => ['country', 'lang', 'os', 'osver', 'brand', 'model', 'device', 'isp', 'client', 'clientver', 'reason'],
            'trafficback' => ['country', 'lang', 'os', 'osver', 'brand', 'model', 'device', 'isp', 'client', 'clientver'],
            default => ['country', 'lang', 'os', 'osver', 'brand', 'model', 'device', 'isp', 'client', 'clientver', 'flow', 'step', 'path', 'status'],
        };

        // Build filter WHERE clauses (positional ? placeholders)
        $filterWhere = '';
        // Ordered list of [value, type] for all bind params
        $bindList = [];
        foreach ($bindParams as $val => $type) $bindList[] = [$val, $type];

        if (!empty($filters) && !empty($filters['rules']) && is_array($filters['rules'])) {
            $filterParts = [];
            foreach ($filters['rules'] as $rule) {
                $field = $rule['field'] ?? '';
                $op = $rule['operator'] ?? '';
                $value = $rule['value'] ?? '';
                if (!str_starts_with($field, 'param.') && !in_array($field, $tableFilterFields, true)) {
                    continue;
                }

                $sqlField = $this->resolveFilterField($field);
                if ($sqlField === null || !in_array($op, self::FILTER_OPERATORS)) {
                    continue;
                }

                switch ($op) {
                    case '=':
                        $filterParts[] = "$sqlField = ?";
                        $bindList[] = [$value, DbDriver::TEXT];
                        break;
                    case '!=':
                        $filterParts[] = "$sqlField != ?";
                        $bindList[] = [$value, DbDriver::TEXT];
                        break;
                    case 'in':
                        $vals = is_array($value) ? $value : array_map('trim', explode(',', $value));
                        $filterParts[] = "$sqlField IN (" . implode(',', array_fill(0, count($vals), '?')) . ")";
                        foreach ($vals as $v) $bindList[] = [$v, DbDriver::TEXT];
                        break;
                    case 'not_in':
                        $vals = is_array($value) ? $value : array_map('trim', explode(',', $value));
                        $filterParts[] = "$sqlField NOT IN (" . implode(',', array_fill(0, count($vals), '?')) . ")";
                        foreach ($vals as $v) $bindList[] = [$v, DbDriver::TEXT];
                        break;
                    case 'is_null':
                        $filterParts[] = "($sqlField IS NULL OR $sqlField = '')";
                        break;
                    case 'is_not_null':
                        $filterParts[] = "($sqlField IS NOT NULL AND $sqlField != '')";
                        break;
                }
            }
            $condition = ($filters['condition'] ?? 'AND') === 'OR' ? ' OR ' : ' AND ';
            if (!empty($filterParts)) {
                $filterWhere = ' AND (' . implode($condition, $filterParts) . ')';
            }
        }

        $searchTerm = trim($searchTerm);
        $searchWhere = '';
        if ($searchTerm !== '' && in_array($filter, ['allowed', 'leads'], true)) {
            $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchTerm);
            $likePattern = '%' . $escapedSearch . '%';
            $searchWhere = " AND (userid LIKE ? ESCAPE '\\' OR clickid LIKE ? ESCAPE '\\')";
            $bindList[] = [$likePattern, DbDriver::TEXT];
            $bindList[] = [$likePattern, DbDriver::TEXT];
        }

        $countQuery = "SELECT COUNT(*) as total FROM $table WHERE $where$filterWhere$searchWhere";
        $countResult = $this->exec_bind_list_query($countQuery, $bindList, true);
        $total = (int)($countResult['total'] ?? 0);

        $dataQuery = "SELECT * FROM $table WHERE $where$filterWhere$searchWhere ORDER BY $sortExpr " . $this->driver->caseInsensitiveCollation() . " $sortDir LIMIT $size OFFSET $offset";
        $clicks = $this->exec_bind_list_query($dataQuery, $bindList);
        foreach ($clicks as &$click) {
            self::decode_click_row($click);
            // Extract requested param columns
            foreach ($paramColumns as $key) {
                $click["param.$key"] = $click['params'][$key] ?? null;
            }
        }

        return [
            'last_page' => max(1, (int)ceil($total / $size)),
            'data' => $clicks,
        ];
    }

    public function get_click_by_clickid(string $clickid): array
    {
        if (empty($clickid)) {
            add_log("trace", "Skipping click retrieval - empty clickid provided");
            return [];
        }

        $query = "SELECT * FROM clicks WHERE clickid = :clickid ORDER BY time DESC LIMIT 1";
        $clicks = $this->exec_read_query($query, [$clickid => DbDriver::TEXT]);
        foreach ($clicks as &$click) {
            self::decode_click_row($click);
        }
        return $clicks[0] ?? [];
    }

    public function get_clicks_by_userid(string $userid, int $campId = 0): array
    {
        if (empty($userid)) {
            add_log("trace", "Skipping clicks retrieval - empty userid provided");
            return [];
        }

        $query = "SELECT * FROM clicks WHERE userid = :userid";
        $params = [$userid => DbDriver::TEXT];
        if ($campId > 0) {
            $query .= " AND campaign_id = :cid";
            $params[$campId] = DbDriver::INT;
        }
        $query .= " ORDER BY time DESC LIMIT 1";
        $clicks = $this->exec_read_query($query, $params);
        foreach ($clicks as &$click) {
            self::decode_click_row($click);
        }
        return $clicks[0] ?? [];
    }

    public function count_clicks(int $campId, string $flow = '', int $sinceTs = 0): int
    {
        $query = "SELECT COUNT(*) AS c FROM clicks WHERE campaign_id = :cid";
        $params = [':cid' => [$campId, DbDriver::INT]];
        if ($flow !== '') {
            $query .= " AND flow = :flow";
            $params[':flow'] = [$flow, DbDriver::TEXT];
        }
        if ($sinceTs > 0) {
            $query .= " AND time >= :ts";
            $params[':ts'] = [$sinceTs, DbDriver::INT];
        }
        $rows = $this->exec_read_query($query, $params);
        return (int)($rows[0]['c'] ?? 0);
    }

    public function get_leads($startdate, $enddate, $campId): array
    {
        // Prepare SQL query to select leads within the date range and configuration
        $query = "SELECT * FROM clicks WHERE time BETWEEN :startDate AND :endDate AND campaign_id = :campid AND status IS NOT NULL ORDER BY time DESC";

        $clicks = $this->exec_read_query($query, [$startdate => DbDriver::INT, $enddate => DbDriver::INT, $campId => DbDriver::INT]);
        foreach ($clicks as &$click) {
            if (empty($click['params']))
                continue;
            $click['params'] = json_decode($click['params'], true);
            if ($click['params'] === null && json_last_error() !== JSON_ERROR_NONE) {
                add_log("errors", "Failed to parse trafficback params JSON for row " . $click['id'] . ": " . json_last_error_msg());
                $click['params'] = [];
            }
        }
        return $clicks;
    }

    private function get_stats_select_parts(array $selectedFields): array
    {
        $selectParts = [];
        // Process selected fields
        foreach ($selectedFields as $field) {
            switch ($field) {
                case 'clicks':
                    $selectParts[] = "COUNT(c.id) AS clicks";
                    break;
                case 'uniques':
                    $selectParts[] = "COUNT(DISTINCT userid) AS uniques";
                    break;
                case 'uniques_ratio':
                    $selectParts[] = "(COUNT(DISTINCT userid)*1.0/COUNT(*) * 100.0) AS uniques_ratio";
                    break;
                case 'cra':
                    $selectParts[] = "(COUNT(DISTINCT CASE WHEN status IS NOT NULL THEN c.id END) * 100.0 / COUNT(*)) AS cra";
                    break;
                case 'crs':
                    $selectParts[] = "(COUNT(DISTINCT CASE WHEN status = 'Purchase' THEN c.id END) * 100.0 / COUNT(*)) AS crs";
                    break;
                case 'epc':
                    $selectParts[] = "(SUM(payout) * 1.0 / COUNT(c.id)) AS epc";
                    break;
                case 'uepc':
                    $selectParts[] = "(SUM(payout) * 1.0 / COUNT(DISTINCT(userid))) AS uepc";
                    break;
                case 'cpc':
                    $selectParts[] = "(SUM(cost) * 1.0 / COUNT(c.id)) AS cpc";
                    break;
                case 'ucpc':
                    $selectParts[] = "(SUM(cost) * 1.0 / COUNT(DISTINCT(userid))) AS ucpc";
                    break;
                case 'appt':
                    $selectParts[] = "CASE
                            WHEN COUNT(DISTINCT CASE WHEN status = 'Purchase' THEN c.id END) = 0
                                 OR (COUNT(DISTINCT CASE WHEN status IS NOT NULL THEN c.id END) - COUNT(DISTINCT CASE WHEN status = 'Trash' THEN c.id END)) = 0
                            THEN 0
                            ELSE (COUNT(DISTINCT CASE WHEN status = 'Purchase' THEN c.id END) * 100.0 / (COUNT(DISTINCT CASE WHEN status IS NOT NULL THEN c.id END) - COUNT(DISTINCT CASE WHEN status = 'Trash' THEN c.id END)))
                       END AS appt";
                    break;
                case 'app':
                    $selectParts[] = "CASE
                            WHEN COUNT(DISTINCT CASE WHEN status = 'Purchase' THEN c.id END) = 0
                                 OR COUNT(DISTINCT CASE WHEN status IS NOT NULL THEN c.id END) = 0
                            THEN 0
                            ELSE (COUNT(DISTINCT CASE WHEN status = 'Purchase' THEN c.id END) * 100.0 / COUNT(DISTINCT CASE WHEN status IS NOT NULL THEN c.id END))
                       END AS app";
                    break;
                case 'conversion':
                    $selectParts[] = "COUNT(DISTINCT CASE WHEN status IS NOT NULL THEN clickid END) AS conversion";
                    break;
                case 'purchase':
                    $selectParts[] = "COUNT(DISTINCT CASE WHEN status = 'Purchase' THEN clickid END) AS purchase";
                    break;
                case 'hold':
                    $selectParts[] = "COUNT(DISTINCT CASE WHEN status = 'Lead' THEN clickid END) AS hold";
                    break;
                case 'reject':
                    $selectParts[] = "COUNT(DISTINCT CASE WHEN status = 'Reject' THEN clickid END) AS reject";
                    break;
                case 'trash':
                    $selectParts[] = "COUNT(DISTINCT CASE WHEN status = 'Trash' THEN clickid END) AS trash";
                    break;
                case 'ec':
                    $selectParts[] = "(SUM(payout) * 1.0 / COUNT(DISTINCT CASE WHEN status IS NOT NULL THEN clickid END)) AS ec";
                    break;
                case 'cpa':
                    $selectParts[] = "(SUM(cost) * 1.0 / COUNT(DISTINCT CASE WHEN status IS NOT NULL THEN clickid END)) AS cpa";
                    break;
                case 'revenue':
                    $selectParts[] = "SUM(payout) AS revenue";
                    break;
                case 'costs':
                    $selectParts[] = "SUM(cost) AS costs";
                    break;
                case 'profit':
                    $selectParts[] = "(SUM(payout) - SUM(cost)) as profit";
                    break;
                case 'roi':
                    $selectParts[] = "((SUM(payout) - SUM(cost))*1.0 / SUM(cost) * 100.0) as roi";
                    break;
                default:
                    if (str_starts_with($field, 'event.')) {
                        $eventName = substr($field, 6);
                        if (preg_match('/^[a-z0-9_]+$/', $eventName)) {
                            $selectParts[] = "COALESCE(SUM(" . $this->driver->jsonExtractReal('events', $eventName) . "), 0) AS \"$field\"";
                        }
                    }
                    break;
            }
        }
        return $selectParts;
    }

    private const FILTERABLE_FIELDS = [
        'country', 'lang', 'os', 'osver', 'brand', 'model', 'device',
        'isp', 'client', 'clientver', 'flow', 'step', 'path', 'status', 'reason'
    ];

    private const FILTER_OPERATORS = ['=', '!=', 'in', 'not_in', 'is_null', 'is_not_null'];

    private function resolveFilterField(string $field): ?string {
        if (in_array($field, self::FILTERABLE_FIELDS)) {
            return $field;
        }
        if (str_starts_with($field, 'param.')) {
            $key = substr($field, 6);
            if (preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                return $this->driver->jsonExtract('params', $key);
            }
        }
        return null;
    }

    private function buildFilterWhere(array $filters): array {
        $filterWhere = '';
        $filterBinds = [];
        if (empty($filters) || !isset($filters['rules']) || !is_array($filters['rules'])) {
            return [$filterWhere, $filterBinds];
        }

        $filterParts = [];
        foreach ($filters['rules'] as $i => $rule) {
            $field = $rule['field'] ?? '';
            $op = $rule['operator'] ?? '';
            $value = $rule['value'] ?? '';

            $sqlField = $this->resolveFilterField($field);
            if ($sqlField === null || !in_array($op, self::FILTER_OPERATORS)) {
                continue;
            }

            $paramName = ":filter_{$i}";
            switch ($op) {
                case '=':
                    $filterParts[] = "$sqlField = $paramName";
                    $filterBinds[$paramName] = $value;
                    break;
                case '!=':
                    $filterParts[] = "$sqlField != $paramName";
                    $filterBinds[$paramName] = $value;
                    break;
                case 'in':
                    $vals = is_array($value) ? $value : array_map('trim', explode(',', $value));
                    $placeholders = [];
                    foreach ($vals as $vi => $v) {
                        $p = ":filter_{$i}_{$vi}";
                        $placeholders[] = $p;
                        $filterBinds[$p] = $v;
                    }
                    $filterParts[] = "$sqlField IN (" . implode(',', $placeholders) . ")";
                    break;
                case 'not_in':
                    $vals = is_array($value) ? $value : array_map('trim', explode(',', $value));
                    $placeholders = [];
                    foreach ($vals as $vi => $v) {
                        $p = ":filter_{$i}_{$vi}";
                        $placeholders[] = $p;
                        $filterBinds[$p] = $v;
                    }
                    $filterParts[] = "$sqlField NOT IN (" . implode(',', $placeholders) . ")";
                    break;
                case 'is_null':
                    $filterParts[] = "$sqlField IS NULL";
                    break;
                case 'is_not_null':
                    $filterParts[] = "$sqlField IS NOT NULL";
                    break;
            }
        }
        $condition = ($filters['condition'] ?? 'AND') === 'OR' ? ' OR ' : ' AND ';
        if (!empty($filterParts)) {
            $filterWhere = ' AND (' . implode($condition, $filterParts) . ')';
        }

        return [$filterWhere, $filterBinds];
    }

    public function get_statistics(
        array $selectedFields,
        array $groupByFields,
        int $campId,
        string $startDate,
        string $endDate,
        string $timezone,
        array $filters = [],
        array $orderby = []
    ): array {
        $baseQuery =
            "SELECT %s FROM clicks c WHERE campaign_id = :campid AND time BETWEEN :startDate AND :endDate";
        $selectParts = [];
        $groupByParts = [];
        $orderByParts = [];

        $selectParts = $this->get_stats_select_parts($selectedFields);

        [$filterWhere, $filterBinds] = $this->buildFilterWhere($filters);

        // Process group by fields
        foreach ($groupByFields as $field) {
            if ($field === 'date') {

                $dateTime = new DateTime('now', new DateTimeZone($timezone));
                // Get the offset in seconds from UTC
                $offsetInSeconds = $dateTime->getOffset();
                // Convert this offset to an SQLite compatible format (HH:MM)
                $hours = floor($offsetInSeconds / 3600);
                $minutes = floor(($offsetInSeconds % 3600) / 60);
                $offsetFormatted = sprintf('%+03d:%02d', $hours, $minutes);

                $selectParts[] =
                    $this->driver->dateGroup('time', $offsetFormatted) . " AS date";
                $groupByParts[] = "date";
                $orderByParts[] = "date";
            } elseif (in_array($field, ['country', 'lang', 'os', 'osver', 'brand', 'model', 'device', 'isp', 'client', 'clientver', 'flow', 'step', 'path'])) {
                $selectParts[] = $field;
                $groupByParts[] = $field;
                $orderByParts[] = $field;
            } else {
                // JSON fields — strip param. prefix if present
                $jsonKey = str_starts_with($field, 'param.') ? substr($field, 6) : $field;
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $jsonKey)) continue;
                $alias = $jsonKey;
                $jsonExtract = "COALESCE(" . $this->driver->jsonExtract('params', $jsonKey) . ", 'unknown') AS " . $alias;
                $selectParts[] = $jsonExtract;
                $groupByParts[] = $alias;
                $orderByParts[] = $alias;
            }
        }

        // Construct the SQL query
        $selectClause = implode(', ', $selectParts);
        $groupByClause = !empty($groupByParts) ? "GROUP BY " . implode(', ', $groupByParts) : '';
        $orderByClause = !empty($orderByParts) ? "ORDER BY " . implode(', ', $orderByParts) : '';
        $sqlQuery = sprintf($baseQuery, $selectClause) . $filterWhere . " " . $groupByClause . " " . $orderByClause;

        $params = [
            ':campid' => [$campId, DbDriver::INT],
            ':startDate' => [$startDate, DbDriver::INT],
            ':endDate' => [$endDate, DbDriver::INT],
        ];
        foreach ($filterBinds as $param => $val) {
            $params[$param] = [$val, DbDriver::TEXT];
        }

        try {
            $rows = $this->driver->select($sqlQuery, $params);
        } catch (Throwable $e) {
            add_log("errors", "Error executing statistics statement: " . $e->getMessage());
            return [];
        }

        // Normalize groupby field names: strip param. prefix so they match SQL aliases
        $normalizedGroupBy = array_map(function($f) {
            return str_starts_with($f, 'param.') ? substr($f, 6) : $f;
        }, $groupByFields);

        // Build the tree structure
        $tree = $this->build_tree($rows, $normalizedGroupBy, $selectedFields, 0, $orderby);
        return $tree;
    }

    private function build_tree(array $rows, array $groupByFields, array $selectedFields, int $level = 0, array $orderby = []): array
    {
        if (empty($groupByFields) || $level >= count($groupByFields)) {
            // No grouping: recalculate derived metrics to fix SQL NULLs from division by zero
            if ($level === 0 && !empty($rows)) {
                return [$this->calculate_totals($rows, $selectedFields)];
            }
            return $rows;
        }

        $groupField = $groupByFields[$level];
        $groupedData = [];

        // Group rows by current level's field
        foreach ($rows as $row) {
            $groupValue = $row[$groupField];
            // Convert all numeric values to strings to prevent implicit conversions
            if (is_numeric($groupValue)) {
                $groupValue = (string) $groupValue;
            }
            if (!isset($groupedData[$groupValue])) {
                $groupedData[$groupValue] = [];
            }
            $groupedData[$groupValue][] = $row;
        }

        $tree = [];
        foreach ($groupedData as $groupValue => $groupRows) {
            // For leaf nodes or single level grouping
            if ($level >= count($groupByFields) - 1) {
                $totals = $this->calculate_totals($groupRows, $selectedFields);
                $totals['group'] = $groupValue;
                $tree[] = $totals;
            } else {
                $children = $this->build_tree($groupRows, $groupByFields, $selectedFields, $level + 1, $orderby);
                $totals = $this->calculate_totals($groupRows, $selectedFields);
                $node = array_merge(
                    array_diff_key($totals, array_flip($groupByFields)),
                    ['_children' => $children],
                    ['group' => $groupValue]  // Put this last to override any 'group' from totals
                );
                $tree[] = $node;
            }
        }

        if (!empty($orderby)) {
            usort($tree, function ($a, $b) use ($orderby) {
                foreach ($orderby as $rule) {
                    $field = $rule['field'] ?? '';
                    $dir = $rule['dir'] ?? 'asc';
                    $va = $a[$field] ?? 0;
                    $vb = $b[$field] ?? 0;
                    $cmp = $va <=> $vb;
                    if ($dir === 'desc') $cmp = -$cmp;
                    if ($cmp !== 0) return $cmp;
                }
                return 0;
            });
        }

        return $tree;
    }

    private function calculate_totals(array $rows, array $selectedFields): array
    {
        $totals = array_fill_keys($selectedFields, 0);

        foreach ($rows as $row) {
            foreach ($selectedFields as $field) {
                if (isset($row[$field]) && is_numeric($row[$field])) {
                    $totals[$field] += $row[$field];
                }
            }
        }

        // Recalculate derived (non-additive) fields from summed base metrics
        // Percentage metrics: round to 4 decimals (frontend trims to 2)
        if (in_array('uniques_ratio', $selectedFields))
            $totals['uniques_ratio'] = $totals['clicks'] === 0 ? 0 : round($totals['uniques'] * 100.0 / $totals['clicks'], 4);
        if (in_array('cra', $selectedFields))
            $totals['cra'] = $totals['clicks'] === 0 ? 0 : round($totals['conversion'] * 100.0 / $totals['clicks'], 4);
        if (in_array('crs', $selectedFields))
            $totals['crs'] = $totals['clicks'] === 0 ? 0 : round($totals['purchase'] * 100.0 / $totals['clicks'], 4);
        if (in_array('appt', $selectedFields)) {
            $denom = $totals['conversion'] - $totals['trash'];
            $totals['appt'] = $denom === 0 ? 0 : round($totals['purchase'] * 100.0 / $denom, 4);
        }
        if (in_array('app', $selectedFields))
            $totals['app'] = $totals['conversion'] === 0 ? 0 : round($totals['purchase'] * 100.0 / $totals['conversion'], 4);
        if (in_array('roi', $selectedFields))
            $totals['roi'] = $totals['costs'] === 0 ? 0 : round(($totals['revenue'] - $totals['costs']) * 100.0 / $totals['costs'], 4);

        // Money-per-unit metrics: round to 6 decimals (frontend trims to 2-5)
        if (in_array('epc', $selectedFields))
            $totals['epc'] = $totals['clicks'] === 0 ? 0 : round($totals['revenue'] * 1.0 / $totals['clicks'], 6);
        if (in_array('uepc', $selectedFields))
            $totals['uepc'] = $totals['uniques'] === 0 ? 0 : round($totals['revenue'] * 1.0 / $totals['uniques'], 6);
        if (in_array('cpc', $selectedFields))
            $totals['cpc'] = $totals['clicks'] === 0 ? 0 : round($totals['costs'] * 1.0 / $totals['clicks'], 6);
        if (in_array('ucpc', $selectedFields))
            $totals['ucpc'] = $totals['uniques'] === 0 ? 0 : round($totals['costs'] * 1.0 / $totals['uniques'], 6);
        if (in_array('ec', $selectedFields))
            $totals['ec'] = $totals['conversion'] === 0 ? 0 : round($totals['revenue'] * 1.0 / $totals['conversion'], 6);
        if (in_array('cpa', $selectedFields))
            $totals['cpa'] = $totals['conversion'] === 0 ? 0 : round($totals['costs'] * 1.0 / $totals['conversion'], 6);

        // Composite additive metrics: recalculate from base to avoid stale SQL values
        if (in_array('profit', $selectedFields))
            $totals['profit'] = round($totals['revenue'] - $totals['costs'], 6);

        return $totals;
    }

    private function bindType($value): int
    {
        if (is_int($value) || is_bool($value)) {
            return DbDriver::INT;
        }
        if (is_float($value)) {
            return DbDriver::FLOAT;
        }
        return DbDriver::TEXT;
    }

    private function add_click(string $query, array $click): bool
    {
        try {
            preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $query, $matches);
            $placeholders = array_flip($matches[1]);

            $params = [];
            foreach ($click as $key => $value) {
                if (!isset($placeholders[$key])) {
                    continue;
                }
                if (!isset($value)) {
                    add_log("warning", "Null value found for field '$key' in click data");
                    $value = '';
                }
                $params[':' . $key] = [$value, $this->bindType($value)];
            }

            $this->driver->execute($query, $params);
            add_log("trace", "Successfully added click for IP: " . ($click['ip'] ?? 'unknown'));
            return true;
        } catch (Throwable $e) {
            add_log("errors", "Failed to add click: " . $e->getMessage() . ", Data: " . json_encode($click));
            return false;
        }
    }

    public function add_trafficback_click($data): bool
    {
        $click = $this->prepare_click_data($data);
        $query = "INSERT INTO trafficback (time, ip, country, lang, os, osver, brand, model, isp, client, clientver, ua, params) VALUES (:time, :ip, :country, :lang, :os, :osver, :brand, :model, :isp, :client, :clientver, :ua, :params)";
        return $this->add_click($query, $click);
    }

    public function add_white_click($data, $reason, $campId): bool
    {
        $click = $this->prepare_click_data($data, $campId);
        $click['reason'] = $reason;
        $query = "INSERT INTO blocked (campaign_id, time, ip, country, lang, os, osver, brand, model, isp, client, clientver, ua, reason, params) VALUES (:campaign_id, :time, :ip, :country, :lang, :os, :osver, :brand, :model, :isp, :client, :clientver, :ua, :reason, :params)";
        return $this->add_click($query, $click);
    }

    public function add_black_click(string $userid, string $clickid, $data, array $path, string $flow, int $campId): bool
    {
        $click = $this->prepare_click_data($data, $campId);
        $click['userid'] = $userid;
        $click['clickid'] = $clickid;
        $click['flow'] = empty($flow) ? 'unknown' : $flow;
        $click['path'] = json_encode($path);
        $click['step'] = 0;
        $click['status'] = null;

        $query = "INSERT INTO clicks (campaign_id, time, ip, country, lang, os, osver, client, clientver, device, brand, model, isp, ua, userid, clickid, flow, path, step, params, cost, status) VALUES (:campaign_id, :time, :ip, :country, :lang, :os, :osver, :client, :clientver, :device, :brand, :model, :isp, :ua, :userid, :clickid, :flow, :path, :step, :params, :cpc, NULL)";

        return $this->add_click($query, $click);
    }

    public function add_click_step(string $clickid, int $step, string $variant): bool
    {
        if (empty($clickid)) {
            add_log("warning", "Skipping step insertion - empty clickid provided");
            return false;
        }
        if ($step < 0) {
            add_log("warning", "Skipping step insertion - invalid step provided: $step");
            return false;
        }

        if (!$this->clickid_exists($clickid)) {
            add_log("warning", "Skipping step insertion - clickid not found: $clickid");
            return false;
        }

        try {
            $this->driver->beginTransaction();

            $this->driver->execute(
                $this->driver->insertIgnoreInto() . " click_steps (clickid, step, variant, time) VALUES (:clickid, :step, :variant, :time)",
                [
                    ':clickid' => [$clickid, DbDriver::TEXT],
                    ':step' => [$step, DbDriver::INT],
                    ':variant' => [$variant, DbDriver::TEXT],
                    ':time' => [time(), DbDriver::INT],
                ]
            );

            $this->driver->execute(
                "UPDATE clicks SET step = " . $this->driver->greatest('step', ':newStep') . " WHERE clickid = :clickid",
                [
                    ':newStep' => [$step, DbDriver::INT],
                    ':clickid' => [$clickid, DbDriver::TEXT],
                ]
            );

            $this->driver->commit();
            return true;
        } catch (Throwable $e) {
            $this->driver->rollback();
            add_log('errors', $e->getMessage());
            return false;
        }
    }

    public function update_click_path(string $clickid, array $path): bool
    {
        if (empty($clickid)) {
            add_log("warning", "Skipping path update - empty clickid provided");
            return false;
        }
        $pathJson = json_encode(array_values($path));
        if ($pathJson === false) {
            add_log("warning", "Skipping path update - invalid path JSON for clickid: $clickid");
            return false;
        }

        $query = "UPDATE clicks SET path = :path WHERE clickid = :clickid";
        return $this->exec_update_query($query, [$pathJson => DbDriver::TEXT, $clickid => DbDriver::TEXT]);
    }

    public function add_lead(string $clickid, array $leaddata, string $status = 'Lead'): bool
    {
        if (empty($clickid)) {
            add_log("warning", "Skipping lead addition - empty clickid provided");
            return false;
        }

        $leadJson = json_encode($leaddata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($leadJson === false) {
            add_log("warning", "Skipping lead addition - invalid lead data JSON for clickid: $clickid");
            return false;
        }

        $updateQuery = "UPDATE clicks SET status = :status, leaddata = :leaddata WHERE id = (SELECT id FROM clicks WHERE clickid = :clickid ORDER BY time DESC LIMIT 1)";
        return $this->exec_update_query(
            $updateQuery,
            [
                ':status' => [$status, DbDriver::TEXT],
                ':leaddata' => [$leadJson, DbDriver::TEXT],
                ':clickid' => [$clickid, DbDriver::TEXT],
            ]
        );
    }

    public function update_status(string $clickid, string $status, float $payout): bool
    {
        if (empty($clickid)) {
            add_log("warning", "Skipping status update - empty clickid provided");
            return false;
        }

        if (!$this->clickid_exists($clickid)) {
            add_log("warning", "Skipping status update - clickid not found: $clickid");
            return false;
        }

        if (!is_numeric($payout)) {
            throw new Exception("Invalid payout value: $payout");
        }

        $updateQuery = "UPDATE clicks SET status = :status, payout = :payout WHERE id = (SELECT id FROM clicks WHERE clickid = :clickid ORDER BY time DESC LIMIT 1)";
        return $this->exec_update_query($updateQuery, [$status => DbDriver::TEXT, $payout => DbDriver::FLOAT, $clickid => DbDriver::TEXT]);
    }

    /**
     * Record a conversion in the dedicated conversions table (multiple per
     * click allowed). Returns the new row id, or 0 on failure.
     *
     * @param array<string,mixed> $raw original request payload
     */
    public function add_conversion(int $campaignId, string $clickid, string $tid, string $status, float $payout, float $revenue, string $currency, string $source, string $dedupKey, array $raw = []): int
    {
        $query = "INSERT INTO conversions (campaign_id, clickid, tid, status, payout, revenue, currency, time, source, dedup_key, raw)
                  VALUES (:cid, :clickid, :tid, :status, :payout, :revenue, :currency, :time, :source, :dedup, :raw)";
        $params = [
            ':cid' => [$campaignId, DbDriver::INT],
            ':clickid' => [$clickid, DbDriver::TEXT],
            ':tid' => [$tid, DbDriver::TEXT],
            ':status' => [$status, DbDriver::TEXT],
            ':payout' => [$payout, DbDriver::FLOAT],
            ':revenue' => [$revenue, DbDriver::FLOAT],
            ':currency' => [$currency, DbDriver::TEXT],
            ':time' => [time(), DbDriver::INT],
            ':source' => [$source, DbDriver::TEXT],
            ':dedup' => [$dedupKey, DbDriver::TEXT],
            ':raw' => [json_encode($raw === [] ? new stdClass() : $raw), DbDriver::TEXT],
        ];
        $id = $this->exec_write_query($query, $params, true);
        return is_int($id) ? $id : 0;
    }

    /** True if a conversion with this dedup key already exists for the campaign. */
    public function conversion_exists(int $campaignId, string $dedupKey): bool
    {
        if ($dedupKey === '') {
            return false;
        }
        $rows = $this->exec_read_query(
            "SELECT 1 AS x FROM conversions WHERE campaign_id = :cid AND dedup_key = :dedup LIMIT 1",
            [':cid' => [$campaignId, DbDriver::INT], ':dedup' => [$dedupKey, DbDriver::TEXT]]
        );
        return !empty($rows);
    }

    /** @return array<int,array<string,mixed>> */
    public function get_conversions(int $startdate, int $enddate, int $campId = 0, int $limit = 500): array
    {
        $query = "SELECT * FROM conversions WHERE time >= :start AND time <= :end";
        $params = [':start' => [$startdate, DbDriver::INT], ':end' => [$enddate, DbDriver::INT]];
        if ($campId > 0) {
            $query .= " AND campaign_id = :cid";
            $params[':cid'] = [$campId, DbDriver::INT];
        }
        $query .= " ORDER BY time DESC LIMIT " . (int)$limit;
        return $this->exec_read_query($query, $params);
    }

    /** Append a row to the postback audit log. Never blocks the caller. */
    public function log_postback(string $direction, string $clickid, string $status, float $payout, string $currency, string $target, int $httpCode, string $message): bool
    {
        $query = "INSERT INTO postback_log (time, direction, clickid, status, payout, currency, target, http_code, message)
                  VALUES (:time, :dir, :clickid, :status, :payout, :currency, :target, :code, :msg)";
        $params = [
            ':time' => [time(), DbDriver::INT],
            ':dir' => [$direction, DbDriver::TEXT],
            ':clickid' => [$clickid, DbDriver::TEXT],
            ':status' => [$status, DbDriver::TEXT],
            ':payout' => [$payout, DbDriver::FLOAT],
            ':currency' => [$currency, DbDriver::TEXT],
            ':target' => [$target, DbDriver::TEXT],
            ':code' => [$httpCode, DbDriver::INT],
            ':msg' => [$message, DbDriver::TEXT],
        ];
        return (bool)$this->exec_write_query($query, $params);
    }

    /** @return array<int,array<string,mixed>> */
    public function get_postback_log(int $limit = 200, string $direction = ''): array
    {
        $query = "SELECT * FROM postback_log";
        $params = [];
        if ($direction !== '') {
            $query .= " WHERE direction = :dir";
            $params[':dir'] = [$direction, DbDriver::TEXT];
        }
        $query .= " ORDER BY time DESC LIMIT " . (int)$limit;
        return $this->exec_read_query($query, $params);
    }

    public function update_click_params(int $clickId, array $params): bool
    {
        if (empty($clickId)) {
            add_log("warning", "Skipping params update - empty click ID provided");
            return false;
        }

        $paramsJson = json_encode($params);
        if ($paramsJson === false) {
            add_log("warning", "Failed to encode params to JSON for click ID: $clickId");
            return false;
        }

        $updateQuery = "UPDATE clicks SET params = :params WHERE id = :id";
        return $this->exec_update_query($updateQuery, [$paramsJson => DbDriver::TEXT, $clickId => DbDriver::INT]);
    }

    public function add_click_event(string $clickid, string $eventName, float $eventValue): bool
    {
        if ($clickid === '' || !preg_match('/^[a-z0-9_]+$/', $eventName) || !is_finite($eventValue)) {
            return false;
        }

        try {
            $this->driver->beginTransaction();

            $clickRow = $this->driver->selectOne(
                'SELECT id, step, events FROM clicks WHERE clickid = :clickid ORDER BY time DESC LIMIT 1',
                [':clickid' => [$clickid, DbDriver::TEXT]]
            );
            if (empty($clickRow)) {
                throw new Exception('Click not found for clickid ' . $clickid);
            }

            $events = [];
            if (!empty($clickRow['events'])) {
                $decoded = json_decode((string)$clickRow['events'], true);
                if (is_array($decoded)) {
                    $events = $decoded;
                }
            }
            $events[$eventName] = round(((float)($events[$eventName] ?? 0)) + $eventValue, 6);
            $eventsJson = json_encode($events);
            if ($eventsJson === false) {
                throw new Exception('Failed to encode events JSON');
            }

            $this->driver->execute(
                'INSERT INTO click_event_log (clickid, time, step_index, event_name, event_value) VALUES (:clickid, :time, :step_index, :event_name, :event_value)',
                [
                    ':clickid' => [$clickid, DbDriver::TEXT],
                    ':time' => [time(), DbDriver::INT],
                    ':step_index' => [max(0, (int)($clickRow['step'] ?? 0)), DbDriver::INT],
                    ':event_name' => [$eventName, DbDriver::TEXT],
                    ':event_value' => [$eventValue, DbDriver::FLOAT],
                ]
            );

            $this->driver->execute(
                'UPDATE clicks SET events = :events WHERE id = :id',
                [
                    ':events' => [$eventsJson, DbDriver::TEXT],
                    ':id' => [(int)$clickRow['id'], DbDriver::INT],
                ]
            );

            $this->driver->commit();
            return true;
        } catch (Throwable $e) {
            $this->driver->rollback();
            add_log('errors', 'Failed to add click event: ' . $e->getMessage());
            return false;
        }
    }

    public function get_event_names(int $campId): array
    {
        $query = 'SELECT DISTINCT cel.event_name AS event_name FROM click_event_log cel INNER JOIN clicks c ON c.clickid = cel.clickid WHERE c.campaign_id = :campid ORDER BY cel.event_name';
        $rows = $this->exec_read_query($query, [$campId => DbDriver::INT]);
        return array_values(array_filter(array_map(fn($row) => $row['event_name'] ?? null, $rows)));
    }

    public function get_funnel_stats(int $campId, string $flowName, string $status): array
    {
        $query = "SELECT path, COUNT(*) AS impressions, COUNT(CASE WHEN status = :status THEN 1 END) AS conversions FROM clicks WHERE campaign_id = :cid AND flow = :flow GROUP BY path";
        return $this->exec_read_query($query, [$status => DbDriver::TEXT, $campId => DbDriver::INT, $flowName => DbDriver::TEXT]);
    }

    public function get_variant_stats(int $campId, string $flowName, int $stepIndex, string $status): array
    {
        $query = "
            SELECT
                cs.variant AS variant,
                COUNT(*) AS impressions,
                COUNT(CASE WHEN c.status = :status THEN 1 END) AS conversions
            FROM click_steps cs
            INNER JOIN clicks c ON c.clickid = cs.clickid
            WHERE c.campaign_id = :cid AND c.flow = :flow AND cs.step = :step
            GROUP BY cs.variant
        ";
        return $this->exec_read_query($query, [
            $status => DbDriver::TEXT,
            $campId => DbDriver::INT,
            $flowName => DbDriver::TEXT,
            $stepIndex => DbDriver::INT,
        ]);
    }

    private function clickid_exists(string $clickid): bool
    {
        if (empty($clickid)) {
            add_log("warning", "Empty clickid provided for existence check");
            return false;
        }
        $query = "SELECT COUNT(*) AS count FROM clicks WHERE clickid = :clickid";
        $res = $this->exec_read_query($query, [$clickid => DbDriver::TEXT], true);
        return $res['count'] > 0;
    }

    private function prepare_click_data($data, $campId = null): array
    {
        $data["time"] = (new DateTime())->getTimestamp();
        if (!is_null($campId))
            $data["campaign_id"] = $campId;

        $query = [];
        if (!empty($_SERVER['QUERY_STRING'])) {
            parse_str($_SERVER['QUERY_STRING'], $query);
        }

        if (array_key_exists("cpc", $query)) {
            $data["cpc"] = $query["cpc"];
            unset($query["cpc"]);
        }

        $data["params"] = json_encode($query);
        return $data;
    }

    public function add_campaign($name): bool|int
    {
        $query = "INSERT INTO campaigns (name, settings) VALUES (:name, :settings)";

        $settingsJson = file_get_contents(__DIR__ . '/default.json');
        $settings = json_decode($settingsJson, true);
        $settings['apikey'] = $this->generate_api_key();
        $settings['identifier'] = $this->generate_unique_campaign_identifier();
        $settingsJson = json_encode($settings);
        return $this->exec_write_query($query, [$name => DbDriver::TEXT, $settingsJson => DbDriver::TEXT], true);
    }

    public function normalize_campaign_identifier(string $identifier): string
    {
        $identifier = trim($identifier);
        $identifier = trim($identifier, "/ \t\n\r\0\x0B");
        $identifier = preg_replace('/\s+/', '-', $identifier) ?? '';
        return preg_replace('/[^A-Za-z0-9_-]/', '', $identifier) ?? '';
    }

    public function campaign_identifier_is_unique(string $identifier, ?int $exceptId = null): bool
    {
        $identifier = $this->normalize_campaign_identifier($identifier);
        if ($identifier === '') {
            return false;
        }

        foreach ($this->exec_read_query("SELECT id, settings FROM campaigns", []) as $campaign) {
            if ($exceptId !== null && (int)$campaign['id'] === $exceptId) {
                continue;
            }
            $settings = json_decode((string)($campaign['settings'] ?? ''), true);
            if (!is_array($settings)) {
                continue;
            }
            if (($settings['identifier'] ?? '') === $identifier) {
                return false;
            }
        }
        return true;
    }

    private function generate_unique_campaign_identifier(): string
    {
        do {
            $identifier = strtolower(substr(bin2hex(random_bytes(5)), 0, 8));
        } while (!$this->campaign_identifier_is_unique($identifier));

        return $identifier;
    }

    private function generate_api_key(): string
    {
        return sprintf(
            '%04X%04X-%04X-%04X-%04X-%04X%04X%04X',
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(16384, 20479),
            mt_rand(32768, 49151),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535)
        );
    }

    public function get_campaign_by_apikey(string $apikey): array
    {
        $query = "SELECT * FROM campaigns WHERE " . $this->driver->jsonExtract('settings', 'apikey') . " = :apikey";
        $camp = $this->exec_read_query($query, [$apikey => DbDriver::TEXT], true);
        if (isset($camp['settings'])) {
            $camp['settings'] = json_decode($camp['settings'], true);
        }
        return $camp;
    }

    public function clone_campaign($id): bool|int
    {
        $query = "SELECT name, settings FROM campaigns WHERE id = :id";
        $campaign = $this->exec_read_query($query, [$id => DbDriver::INT], true);
        if (empty($campaign)) {
            return false;
        }

        $settings = json_decode((string)$campaign['settings'], true);
        if (!is_array($settings)) {
            return false;
        }
        $settings['identifier'] = $this->generate_unique_campaign_identifier();

        $insertQuery = "INSERT INTO campaigns (name, settings) VALUES (:name, :settings)";
        return $this->exec_write_query(
            $insertQuery,
            [($campaign['name'] . ' (Clone)') => DbDriver::TEXT, json_encode($settings) => DbDriver::TEXT],
            true
        );
    }

    public function get_campaign_name(int $id): string
    {
        $query = "SELECT name FROM campaigns WHERE id = :id";
        $arr = $this->exec_read_query($query, [$id => DbDriver::INT], true);
        return $arr['name'] ?? '';
    }

    public function get_campaigns_list(): array
    {
        $query = "SELECT id, name FROM campaigns ORDER BY name " . $this->driver->caseInsensitiveCollation() . " ASC";
        return $this->exec_read_query($query, []);
    }

    public function get_campaign_settings(int $id): array
    {
        $query = "SELECT settings FROM campaigns WHERE id = :id";
        $arr = $this->exec_read_query($query, [$id => DbDriver::INT], true);
        $settings = json_decode($arr['settings'], true);
        if (is_array($settings) && empty($settings['identifier'])) {
            $settings['identifier'] = $this->generate_unique_campaign_identifier();
            $this->save_campaign_settings($id, $settings);
        }
        return $settings;
    }

    public function get_campaign_by_request(): array|bool
    {
        $identifier = $this->campaign_identifier_from_request();
        if ($identifier !== '') {
            $camp = $this->get_campaign_by_identifier($identifier);
            if ($camp !== false) {
                return $camp;
            }
            // Path carries an identifier that matches no campaign. Optionally
            // intercept (treat as 404) and serve the domain's default campaign.
            return $this->domain_pool_campaign(true);
        }

        // Root request (no campaign identifier in the path).
        $camp = $this->get_campaign_by_domain();
        if ($camp !== false) {
            return $camp;
        }
        // No campaign claims this domain at its root: fall back to the domain's
        // default ("index page") campaign from the domain pool, when set.
        return $this->domain_pool_campaign(false);
    }

    /**
     * Resolve the current request host to its default-campaign behaviour from
     * the domain pool. Returns the campaign row (settings decoded) when the
     * matched pool domain has a default campaign and either this is a root
     * request ($is404 = false) or 404 interception is enabled ($is404 = true).
     * Returns false otherwise (caller then falls back to trafficback/404 stub).
     */
    private function domain_pool_campaign(bool $is404): array|bool
    {
        $host = $this->current_request_host();
        if ($host === '') {
            return false;
        }
        $domain = $this->get_domain_by_host($host);
        if ($domain === null) {
            return false;
        }
        $campId = $domain->defaultCampaignId();
        if ($campId === null) {
            return false;
        }
        if ($is404 && !$domain->intercept404()) {
            return false;
        }
        return $this->get_campaign_by_id($campId);
    }

    /** Normalized host of the current request, with domain-pool alias resolution. */
    public function current_request_host(): string
    {
        $cPath = get_cloaker_path(true, false);
        $parsedUrl = parse_url($cPath);
        if (!isset($parsedUrl['host'])) {
            return '';
        }
        $domain = isset($parsedUrl['port'])
            ? $parsedUrl['host'] . ":" . $parsedUrl['port']
            : $parsedUrl['host'];
        $aliasMap = $this->domain_alias_map();
        return DomainMatcher::resolveAlias($aliasMap, $domain);
    }

    /**
     * Find the pool {@see Domain} matching $host (exact or wildcard pattern),
     * or null when the domains table is absent / no entry matches. Exact
     * matches win over wildcard patterns.
     */
    public function get_domain_by_host(string $host): ?Domain
    {
        if (empty($this->driver->tableColumns('domains'))) {
            return null;
        }
        $host = DomainMatcher::normalize($host);
        if ($host === '') {
            return null;
        }
        $rows = $this->driver->select("SELECT * FROM domains");
        $wildcard = null;
        foreach ($rows as $row) {
            $name = DomainMatcher::normalize((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if ($name === $host) {
                return new Domain($row);
            }
            if ($wildcard === null && strpos($name, '*') !== false
                && DomainMatcher::matchesWildcard($name, $host)) {
                $wildcard = $row;
            }
        }
        return $wildcard === null ? null : new Domain($wildcard);
    }

    /** Whether the current request host allows crawler indexing (robots.txt). */
    public function domain_index_allowed(string $host): bool
    {
        $domain = $this->get_domain_by_host(DomainMatcher::normalize($host));
        return $domain !== null && $domain->indexAllowed();
    }

    /** Load a campaign row by id with its settings JSON decoded, or false. */
    public function get_campaign_by_id(int $id): array|bool
    {
        $query = "SELECT * FROM campaigns WHERE id = :id";
        $camp = $this->exec_read_query($query, [$id => DbDriver::INT], true);
        if (empty($camp)) {
            return false;
        }
        if (isset($camp['settings'])) {
            $camp['settings'] = json_decode($camp['settings'], true);
        }
        return $camp;
    }

    private function campaign_identifier_from_request(): string
    {
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!is_string($path)) {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $path), static fn($part) => $part !== ''));
        if (empty($segments)) {
            return '';
        }

        return $this->normalize_campaign_identifier(rawurldecode($segments[0]));
    }

    public function get_campaign_by_identifier(string $identifier): array|bool
    {
        $identifier = $this->normalize_campaign_identifier($identifier);
        if ($identifier === '') {
            return false;
        }

        $query = "SELECT * FROM campaigns";
        $campaigns = $this->exec_read_query($query, []);
        foreach ($campaigns as $campaign) {
            if (empty($campaign['settings'])) {
                continue;
            }
            $settings = json_decode($campaign['settings'], true);
            if (!is_array($settings)) {
                continue;
            }
            if (($settings['identifier'] ?? '') === $identifier) {
                add_log("trace", "Found matching campaign for identifier $identifier: " . $campaign['id']);
                $campaign['settings'] = $settings;
                return $campaign;
            }
        }
        return false;
    }

    public function get_campaign_by_domain(): array|bool
    {
        $cPath = get_cloaker_path(true, false);
        $parsedUrl = parse_url($cPath);
        $domain = isset($parsedUrl['port']) ?
            $parsedUrl['host'] . ":" . $parsedUrl['port'] :
            $parsedUrl['host'];

        $aliasMap = $this->domain_alias_map();
        $canonical = DomainMatcher::resolveAlias($aliasMap, $domain);

        $query = "SELECT * FROM campaigns";
        $campaigns = $this->exec_read_query($query, []);
        foreach ($campaigns as $campaign) {
            if (empty($campaign['settings'])) {
                continue;
            }
            $settings = json_decode($campaign['settings'], true);
            if (!isset($settings['domains'])) {
                continue;
            }
            if ($this->match_domain($settings['domains'], $domain)
                || ($canonical !== $domain && $this->match_domain($settings['domains'], $canonical))) {
                add_log("trace", "Found matching campaign for domain $domain: " . $campaign['id']);
                $campaign['settings'] = $settings;
                return $campaign;
            }
        }
        return false;
    }

    /**
     * Alias map (alias host => canonical host) from the domain pool. Returns an
     * empty map when the domains table is absent (older installs / pre-migration)
     * so existing campaign domain matching keeps working unchanged.
     *
     * @return array<string,string>
     */
    private function domain_alias_map(): array
    {
        if (empty($this->driver->tableColumns('domains'))) {
            return [];
        }
        $rows = $this->driver->select("SELECT name, settings FROM domains");
        return DomainMatcher::buildAliasMap($rows);
    }

    private function match_domain($domains, $domainToMatch): bool
    {
        return DomainMatcher::matches(is_array($domains) ? $domains : [], (string)$domainToMatch);
    }

    public function rename_campaign(int $id, string $name): bool
    {
        $query = "UPDATE campaigns SET name = :name WHERE id = :id";
        return $this->exec_write_query($query, [$name => DbDriver::TEXT, $id => DbDriver::INT]);
    }

    public function save_campaign_settings(int $id, array $settings): bool
    {
        $query = "UPDATE campaigns SET settings = :settings WHERE id = :id";
        $settingsJson = json_encode($settings);
        return $this->exec_write_query($query, [$settingsJson => DbDriver::TEXT, $id => DbDriver::INT]);
    }


    public function delete_campaign(int $id): bool
    {
        $query = "DELETE FROM campaigns WHERE id = :id";
        return $this->exec_write_query($query, [$id => DbDriver::INT]);
    }

    public function get_campaigns($startDate, $endDate, array $selectFields, array $filters = []): array
    {
        $bindList = [];
        $bindList[] = [$startDate, DbDriver::INT];
        $bindList[] = [$endDate, DbDriver::INT];

        $filterJoin = '';
        if (!empty($filters) && !empty($filters['rules']) && is_array($filters['rules'])) {
            $filterParts = [];
            foreach ($filters['rules'] as $rule) {
                $field = $rule['field'] ?? '';
                $op = $rule['operator'] ?? '';
                $value = $rule['value'] ?? '';

                $sqlField = $this->resolveFilterField($field);
                if ($sqlField === null || !in_array($op, self::FILTER_OPERATORS)) continue;

                if (str_starts_with($field, 'param.')) {
                    $sqlField = $this->driver->jsonExtract('c.params', substr($field, 6));
                } else {
                    $sqlField = "c.$sqlField";
                }

                switch ($op) {
                    case '=':
                        $filterParts[] = "$sqlField = ?";
                        $bindList[] = [$value, DbDriver::TEXT];
                        break;
                    case '!=':
                        $filterParts[] = "$sqlField != ?";
                        $bindList[] = [$value, DbDriver::TEXT];
                        break;
                    case 'in':
                        $vals = is_array($value) ? $value : array_map('trim', explode(',', $value));
                        $filterParts[] = "$sqlField IN (" . implode(',', array_fill(0, count($vals), '?')) . ")";
                        foreach ($vals as $v) $bindList[] = [$v, DbDriver::TEXT];
                        break;
                    case 'not_in':
                        $vals = is_array($value) ? $value : array_map('trim', explode(',', $value));
                        $filterParts[] = "$sqlField NOT IN (" . implode(',', array_fill(0, count($vals), '?')) . ")";
                        foreach ($vals as $v) $bindList[] = [$v, DbDriver::TEXT];
                        break;
                    case 'is_null':
                        $filterParts[] = "($sqlField IS NULL OR $sqlField = '')";
                        break;
                    case 'is_not_null':
                        $filterParts[] = "($sqlField IS NOT NULL AND $sqlField != '')";
                        break;
                }
            }
            $condition = ($filters['condition'] ?? 'AND') === 'OR' ? ' OR ' : ' AND ';
            if (!empty($filterParts)) {
                $filterJoin = ' AND (' . implode($condition, $filterParts) . ')';
            }
        }

        $selectClause = implode(',', $this->get_stats_select_parts($selectFields));
        $query = "
        SELECT cmp.id, cmp.name, cmp.settings, $selectClause
        FROM campaigns cmp
        LEFT JOIN clicks c ON c.campaign_id=cmp.id AND c.time BETWEEN ? AND ?$filterJoin
        GROUP BY cmp.id, cmp.name, cmp.settings";

        $campaigns = $this->exec_bind_list_query($query, $bindList);
        foreach ($campaigns as &$campaign) {
            if (empty($campaign['settings']))
                continue;
            $campaign['settings'] = json_decode($campaign['settings'], true);
        }
        return $campaigns;
    }

    public function get_common_settings(): array
    {
        $query = "SELECT settings FROM common";

        $arr = $this->exec_read_query($query, [], true);
        $settings = json_decode($arr['settings'], true);
        if ($settings === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to parse common settings JSON: " . json_last_error_msg());
        }
        return $settings;
    }

    public function set_common_settings(array $s): bool
    {
        $query = "UPDATE common SET settings=:settings";
        $settingsJson = json_encode($s);
        if ($settingsJson === false) {
            throw new Exception("Failed to encode settings to JSON: " . json_last_error_msg());
        }
        return $this->exec_write_query($query, [$settingsJson => DbDriver::TEXT]);
    }


    private function exec_write_query(string $query, array $p, bool $returnId = false): bool|int
    {
        try {
            $this->driver->beginTransaction();
            $id = $this->driver->insert($query, $p);
            $this->driver->commit();
            add_log("trace", "Successfully executed $query");
            return $returnId ? $id : true;
        } catch (Throwable $e) {
            $this->driver->rollback();
            add_log("errors", $e->getMessage());
            return false;
        }
    }

    private function exec_update_query(string $query, array $p): bool
    {
        try {
            $this->driver->execute($query, $p);
            if ($this->driver->affectedRows() === 0) {
                add_log("errors", "No rows affected when $query");
                return false;
            }
            return true;
        } catch (Throwable $e) {
            add_log("errors", $e->getMessage());
            return false;
        }
    }

    private function exec_bind_list_query(string $query, array $bindList, bool $firstOnly = false): array
    {
        try {
            $rows = $this->driver->select($query, $bindList);
            return $firstOnly ? $rows[0] ?? [] : $rows;
        } catch (Throwable $e) {
            add_error_log($e->getMessage());
            return [];
        }
    }

    private function exec_read_query(string $query, array $p, bool $firstOnly = false): array
    {
        try {
            $rows = $this->driver->select($query, $p);
            return $firstOnly ? $rows[0] ?? [] : $rows;
        } catch (Throwable $e) {
            add_error_log($e->getMessage());
            return [];
        }
    }

}

if (!defined('YELLOWTDS_NO_DB_BOOTSTRAP')) {
    $db = new Db();
}
