<?php
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/scale.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
$hasSearch = isset($_GET['filters']) ? car7_filters() !== [] : $search !== '';
try {
    $total = 0;
    if ($hasSearch) {
        $where = car7_where($conn, $search, car7_search_field());
        $total = (int)$conn->query('SELECT COUNT(*) FROM vehicle_info WHERE ' . $where)->fetch_row()[0];
    }
    echo json_encode(['total' => $total], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'ไม่สามารถนับผลลัพธ์ได้ กรุณาลองอีกครั้ง'], JSON_UNESCAPED_UNICODE);
}
