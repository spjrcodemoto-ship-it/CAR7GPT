<?php
require_once __DIR__.'/import-engine.php';
// Shared bounded-memory operations for the CAR7 website and CSV command-line import.
const CAR7_PAGE_SIZE = 50;
const CAR7_IMPORT_BATCH = 1000;
const CAR7_SEARCH_FIELDS = ['plate_number', 'brand', 'model', 'chassis_number'];
const CAR7_FILTER_FIELDS = ['plate_number','vehicle_type','brand','model','color','chassis_number','engine_number','reg_date','tax_expire_date','status','owner_address'];

function car7_filters(): array {
    $input = $_GET['filters'] ?? null;
    if (!is_array($input)) return [];
    $filters = [];
    foreach (CAR7_FILTER_FIELDS as $key) {
        if (isset($input[$key]) && is_string($input[$key]) && trim($input[$key]) !== '') {
            $filters[$key] = mb_substr(trim($input[$key]), 0, 500);
        }
    }
    return $filters;
}
function car7_filter_signature(string $search): string {
    return isset($_GET['filters']) ? hash('sha256', json_encode(car7_filters())) : $search;
}
function car7_search_params(string $search, string $field): array {
    return isset($_GET['filters']) ? ['filters' => car7_filters()] : ['q' => $search, 'field' => $field];
}

function car7_search_field(): string {
    $field = $_GET['field'] ?? 'plate_number';
    return is_string($field) && in_array($field, CAR7_SEARCH_FIELDS, true) ? $field : 'plate_number';
}
function car7_where(mysqli $conn, string $search, string $field): string {
    if (isset($_GET['filters'])) {
        $clauses = [];
        foreach (car7_filters() as $key => $value) {
            if (in_array($key, ['reg_date','tax_expire_date'], true)) {
                if (!preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $value, $match)) {
                    $clauses[] = '0=1'; continue;
                }
                $year = (int)$match[3] - 543;
                if (!checkdate((int)$match[2], (int)$match[1], $year)) {
                    $clauses[] = '0=1'; continue;
                }
                $date = sprintf('%04d-%02d-%02d', $year, $match[2], $match[1]);
                $clauses[] = "`$key` = '" . $conn->real_escape_string($date) . "'";
            } else {
                $pattern = '%' . str_replace('=', '==', $value) . '%';
                $clauses[] = "`$key` LIKE '" . $conn->real_escape_string($pattern) . "' ESCAPE '='";
            }
        }
        return $clauses ? implode(' AND ', $clauses) : '1=1';
    }
    if (!in_array($field, CAR7_SEARCH_FIELDS, true)) throw new InvalidArgumentException('Invalid search field');
    if ($search === '') return '1=1';
    $pattern = '%' . str_replace('=', '==', $search) . '%';
    return '`' . $field . "` LIKE '" . $conn->real_escape_string($pattern) . "' ESCAPE '='";
}
function car7_cursor(array $row, string $field, string $search): string {
    return rtrim(strtr(base64_encode(json_encode([$field, car7_filter_signature($search), $row[$field], (int)$row['id']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
}
function car7_page(mysqli $conn, string $search, string $field, string $token, bool $back): array {
    $where = car7_where($conn, $search, $field);
    $cursor = null;
    if ($token !== '' && strlen($token) <= 2048) {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        $candidate = $decoded === false ? null : json_decode($decoded, true);
        if (is_array($candidate) && count($candidate) === 4 && $candidate[0] === $field && $candidate[1] === car7_filter_signature($search)
            && (is_string($candidate[2]) || $candidate[2] === null) && is_int($candidate[3]) && $candidate[3] > 0) $cursor = $candidate;
    }
    if ($cursor) {
        $id = $cursor[3]; $value = $cursor[2]; $operator = $back ? '<' : '>';
        if ($value === null) {
            $seek = "(`$field` IS NULL AND id $operator $id)";
            if (!$back) $seek .= " OR `$field` IS NOT NULL";
        } else {
            $value = "'" . $conn->real_escape_string($value) . "'";
            $seek = "`$field` $operator $value OR (`$field` = $value AND id $operator $id)";
            if ($back) $seek .= " OR `$field` IS NULL";
        }
        $where .= ' AND (' . $seek . ')';
    } else $back = false;
    $direction = $back ? 'DESC' : 'ASC';
    // Find matching IDs in the index before fetching full vehicle records.
    $limit = CAR7_PAGE_SIZE + 1;
    $rows = $conn->query("SELECT v.* FROM (
        SELECT id FROM vehicle_info WHERE $where
        ORDER BY `$field` $direction, id $direction LIMIT $limit
    ) AS matches JOIN vehicle_info AS v ON v.id = matches.id
    ORDER BY v.`$field` $direction, v.id $direction")->fetch_all(MYSQLI_ASSOC);
    $extra = count($rows) > CAR7_PAGE_SIZE;
    if ($extra) array_pop($rows);
    if ($back) $rows = array_reverse($rows);
    return [$rows,
        $rows && ($back ? $extra : (bool)$cursor) ? car7_cursor($rows[0], $field, $search) : '',
        $rows && ($back ? (bool)$cursor : $extra) ? car7_cursor($rows[count($rows)-1], $field, $search) : ''];
}
function car7_page_url(string $cursor, bool $back, string $search, string $field): string {
    return 'vehicle.php?' . http_build_query(car7_search_params($search, $field) + ['cursor' => $cursor, 'back' => $back ? '1' : '0']);
}
function car7_end_buffers(): void {
    while (ob_get_level() > 0) ob_end_clean();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}
function car7_export_csv(mysqli $conn, string $search, string $field): never {
    require_once __DIR__ . '/export-engine.php';
    car7_export_large($conn, $search, $field, 'csv');
}
function car7_import_rows(string $file,string $extension):Generator { yield from car7_stream_input($file,$extension); }
function car7_import(mysqli $conn,string $file,string $extension,?callable $progress=null):int {
    return car7_fast_import($conn,$file,$extension,$progress ? function(array $event)use($progress){if(isset($event['committed']))$progress($event['committed']);} : null);
}
function car7_stats(mysqli $conn): array {
    $cache = sys_get_temp_dir() . '/car7-stats-' . hash('sha256', __DIR__ . DB_NAME) . '.json';
    $handle = fopen($cache, 'c+');
    if (!$handle) throw new RuntimeException('Cannot open dashboard cache');
    try {
        flock($handle, LOCK_EX);
        $cached = json_decode(stream_get_contents($handle), true);
        if (is_array($cached) && ($cached['expires'] ?? 0) > time()) return $cached['data'];
        $data = [
            'total' => (int)$conn->query("SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vehicle_info'")->fetch_row()[0],
            'brands' => (int)$conn->query("SELECT COUNT(DISTINCT brand) FROM vehicle_info WHERE brand IS NOT NULL AND brand <> ''")->fetch_row()[0],
            'types' => (int)$conn->query("SELECT COUNT(DISTINCT vehicle_type) FROM vehicle_info WHERE vehicle_type IS NOT NULL AND vehicle_type <> ''")->fetch_row()[0],
            'expiring' => (int)$conn->query('SELECT COUNT(*) FROM vehicle_info WHERE tax_expire_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)')->fetch_row()[0]
        ];
        rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode(['expires' => time()+300, 'data' => $data]));
        return $data;
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}
