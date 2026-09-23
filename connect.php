<?php
/** การเชื่อมต่อฐานข้อมูล CAR7 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
const DB_HOST = 'localhost';
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'CAR7';
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    exit('ไม่สามารถเชื่อมต่อฐานข้อมูล CAR7 ได้: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function flash(string $key, ?string $message = null): ?string {
    if ($message !== null) { $_SESSION[$key] = $message; return null; }
    $result = $_SESSION[$key] ?? null; unset($_SESSION[$key]); return $result;
}
function buddhist_date(?string $date): string {
    if (!$date || $date === '0000-00-00') return '-';
    try {
        $value = new DateTime($date);
        return $value->format('d/m/') . ((int)$value->format('Y') + 543);
    } catch (Throwable) { return '-'; }
}
