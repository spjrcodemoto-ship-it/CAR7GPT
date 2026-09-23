<?php
const CAR7_EXPORT_PART_ROWS = 500000;

/** Disk-backed exports: only one database row is held in PHP memory at a time. */
class Car7Export {
    private array $temporary = [];
    public function __construct(private string $directory) {}
    public function cleanup(): void {
        foreach ($this->temporary as $path) if (is_file($path)) @unlink($path);
        $this->temporary = [];
    }
    private function temp(): string {
        $path = tempnam($this->directory, 'car7-export-');
        if ($path === false) throw new RuntimeException('สร้างไฟล์ส่งออกไม่สำเร็จ');
        $this->temporary[] = $path;
        return $path;
    }
    private function write($file, string $text): void {
        if (fwrite($file, $text) !== strlen($text)) throw new RuntimeException('พื้นที่จัดเก็บไฟล์ส่งออกไม่เพียงพอ');
    }
    private function xmlRow($file, array $values, int $number): void {
        $xml = '<row r="'.$number.'">';
        foreach (array_values($values) as $index => $value) {
            $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string)$value);
            $xml .= '<c r="'.chr(65+$index).$number.'" t="inlineStr"><is><t xml:space="preserve">'.
                htmlspecialchars($text ?? '', ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</t></is></c>';
        }
        $this->write($file, $xml.'</row>');
    }
    private function csvRow($file, array $values): void {
        $values = array_map(static function($value) {
            $value = (string)($value ?? '');
            return preg_match('/^[=+@\-\t\r]/u', $value) ? "'".$value : $value;
        }, $values);
        if (fputcsv($file, $values) === false) throw new RuntimeException('เขียนไฟล์ CSV ไม่สำเร็จ');
    }
    private function zip(): array {
        $path = $this->temp();
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE|ZipArchive::OVERWRITE) !== true) throw new RuntimeException('สร้าง ZIP ไม่สำเร็จ');
        return [$zip, $path];
    }
    private function workbook(string $sheet): string {
        [$zip, $path] = $this->zip();
        $entries = [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="ผลการค้นหา" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>'
        ];
        foreach ($entries as $name => $xml) {
            if (!$zip->addFromString($name, '<?xml version="1.0" encoding="UTF-8"?>'.$xml)) throw new RuntimeException('เขียน Excel ไม่สำเร็จ');
        }
        if (!$zip->addFile($sheet, 'xl/worksheets/sheet1.xml') || !$zip->close()) throw new RuntimeException('บันทึก Excel ไม่สำเร็จ');
        unlink($sheet);
        return $path;
    }
    public function build(iterable $rows, string $format, array $columns, array $headers): array {
        if (!in_array($format, ['xlsx','csv'], true)) throw new InvalidArgumentException('Invalid format');
        $parts = []; $handle = null; $count = 0; $total = 0; $path = '';
        $open = function() use (&$path, &$handle, &$count, $format, $headers) {
            $path = $this->temp(); $handle = fopen($path, 'wb'); $count = 0;
            if (!$handle) throw new RuntimeException('เปิดไฟล์ส่งออกไม่สำเร็จ');
            if ($format === 'xlsx') {
                $this->write($handle, '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
                $this->xmlRow($handle, $headers, 1);
            } else { $this->write($handle, "\xEF\xBB\xBF"); $this->csvRow($handle, $headers); }
        };
        $close = function() use (&$parts, &$handle, &$path, $format) {
            if ($format === 'xlsx') $this->write($handle, '</sheetData></worksheet>');
            if (!fclose($handle)) throw new RuntimeException('บันทึกไฟล์ส่งออกไม่สำเร็จ');
            $handle = null;
            $parts[] = $format === 'xlsx' ? $this->workbook($path) : $path;
        };
        try {
            $open();
            foreach ($rows as $row) {
                if ($count === CAR7_EXPORT_PART_ROWS) { $close(); $open(); }
                $values = array_map(static fn($column) => $row[$column] ?? '', $columns);
                if ($format === 'xlsx') $this->xmlRow($handle, $values, $count+2);
                else $this->csvRow($handle, $values);
                ++$count; ++$total;
            }
            $close();
            $result = $parts[0]; $extension = $format;
            if (count($parts) > 1) {
                [$zip, $result] = $this->zip();
                foreach ($parts as $i => $part) {
                    $name = sprintf('CAR7_part_%03d.%s', $i+1, $format);
                    if (!$zip->addFile($part, $name)) throw new RuntimeException('รวมไฟล์ไม่สำเร็จ');
                    if ($format === 'xlsx') $zip->setCompressionName($name, ZipArchive::CM_STORE);
                }
                if (!$zip->close()) throw new RuntimeException('รวม ZIP ไม่สำเร็จ');
                $extension = 'zip';
            }
            return ['path'=>$result, 'extension'=>$extension, 'parts'=>count($parts), 'rows'=>$total];
        } finally { if (is_resource($handle)) fclose($handle); }
    }
}

function car7_export_large(mysqli $conn, string $search, string $field, string $format): never {
    car7_end_buffers();
    set_time_limit(0);
    $writer = new Car7Export(sys_get_temp_dir());
    register_shutdown_function([$writer, 'cleanup']);
    $result = null;
    try {
        $where = car7_where($conn, $search, $field);
        $result = $conn->query('SELECT '.implode(',', COLUMNS).' FROM vehicle_info WHERE '.$where, MYSQLI_USE_RESULT);
        $rows = (function() use ($result) { while ($row = $result->fetch_assoc()) yield $row; })();
        $file = $writer->build($rows, $format, COLUMNS, HEADERS);
        $result->free(); $result = null;
        $types = ['zip'=>'application/zip','csv'=>'text/csv; charset=utf-8','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        header('Content-Type: '.$types[$file['extension']]);
        header('Content-Disposition: attachment; filename="CAR7_'.date('Ymd_His').'.'.$file['extension'].'"');
        header('Content-Length: '.filesize($file['path']));
        header('Cache-Control: no-store');
        readfile($file['path']);
    } catch (Throwable $error) {
        error_log('CAR7 export: '.$error->getMessage());
        if (!headers_sent()) {
            http_response_code(500); header('Content-Type: text/plain; charset=utf-8');
            echo 'ส่งออกไม่สำเร็จ กรุณาลองใหม่ หรือตรวจสอบพื้นที่ว่างสำหรับสร้างไฟล์';
        }
    } finally {
        if ($result !== null) $result->free();
        $writer->cleanup();
    }
    exit;
}
