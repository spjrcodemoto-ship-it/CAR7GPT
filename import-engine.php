<?php
// Streaming XLSX/CSV input and set-based batches. No complete sheet or shared-string table in RAM.
const CAR7_UPLOAD_MAX = 2147483648;
const CAR7_UPLOAD_CHUNK = 8 * 1024 * 1024;

function car7_xml(string $file, string $entry): XMLReader {
    $reader = new XMLReader();
    if (!$reader->open('zip://' . str_replace('\\', '/', $file) . '#' . $entry, null, LIBXML_NONET | LIBXML_COMPACT)) {
        throw new RuntimeException('ไม่สามารถอ่านโครงสร้าง Excel: ' . $entry);
    }
    $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
    $reader->setParserProperty(XMLReader::LOADDTD, false);
    return $reader;
}
function car7_xml_read(XMLReader $reader): bool {
    $ok = $reader->read();
    if ($ok && $reader->nodeType === XMLReader::DOC_TYPE) throw new RuntimeException('ไม่รองรับ XML ที่มี DTD');
    if (!$ok) {
        foreach (libxml_get_errors() as $error) if ($error->level >= LIBXML_ERR_ERROR) throw new RuntimeException('ไฟล์ Excel เสียหาย: ' . trim($error->message));
    }
    return $ok;
}
function car7_xml_text(SimpleXMLElement $node): string {
    $parts = $node->xpath('./*[local-name()="t"] | ./*[local-name()="r"]/*[local-name()="t"]');
    return implode('', array_map('strval', $parts ?: []));
}
function car7_excel_date(float $serial, bool $date1904): string {
    if ($serial < 0 || $serial > 2958465) throw new RuntimeException('วันที่ Excel อยู่นอกช่วงที่รองรับ');
    $days = (int)floor($serial);
    $base = $date1904 ? '1904-01-01' : ($days < 60 ? '1899-12-31' : '1899-12-30');
    return (new DateTimeImmutable($base))->modify("+$days days")->format('Y-m-d');
}
function car7_stream_xlsx(string $file, ?callable $progress = null): Generator {
    $oldErrors = libxml_use_internal_errors(true); libxml_clear_errors();
    $zip = new ZipArchive(); $reader = null; $db = null; $lookup = null; $insert = null; $cacheFile = null;
    try {
        if ($zip->open($file) !== true) throw new RuntimeException('ไฟล์ไม่ใช่ .xlsx ที่อ่านได้ หรือถูกเข้ารหัสด้วยรหัสผ่าน');
        $expanded = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i); $expanded += $stat['size'];
            if ($expanded > 16 * 1024 * 1024 * 1024) throw new RuntimeException('ข้อมูล Excel เมื่อคลายขนาดเกิน 16 GB กรุณาแยกไฟล์');
        }
        $date1904 = false; $sheetId = null;
        $reader = car7_xml($file, 'xl/workbook.xml');
        while (car7_xml_read($reader)) {
            if ($reader->nodeType !== XMLReader::ELEMENT) continue;
            if ($reader->localName === 'workbookPr') $date1904 = in_array($reader->getAttribute('date1904'), ['1','true'], true);
            if ($reader->localName === 'sheet' && $sheetId === null) {
                $sheetId = $reader->getAttributeNs('id','http://schemas.openxmlformats.org/officeDocument/2006/relationships')
                    ?? $reader->getAttributeNs('id','http://purl.oclc.org/ooxml/officeDocument/relationships');
            }
        }
        $reader->close(); $reader = car7_xml($file, 'xl/_rels/workbook.xml.rels');
        $sheet = null;
        while (car7_xml_read($reader)) if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Relationship' && $reader->getAttribute('Id') === $sheetId) {
            $target = $reader->getAttribute('Target');
            if ($reader->getAttribute('TargetMode') === 'External' || !$target || str_contains($target,'..') || str_contains($target,'\\') || str_contains($target,':')) throw new RuntimeException('ตำแหน่งแผ่นงาน Excel ไม่ถูกต้อง');
            $sheet = str_starts_with($target,'/') ? ltrim($target,'/') : 'xl/' . ltrim($target,'/');
        }
        $reader->close(); $reader = null;
        if (!$sheet || $zip->locateName($sheet) === false) throw new RuntimeException('ไม่พบแผ่นงานแรกในไฟล์ Excel');
        $dateStyles = [];
        if ($zip->locateName('xl/styles.xml') !== false) {
            $reader = car7_xml($file,'xl/styles.xml'); $formats=[]; $styleIndex=0; $inStyles=false;
            while (car7_xml_read($reader)) {
                if ($reader->localName==='cellXfs') $inStyles=$reader->nodeType===XMLReader::ELEMENT;
                if ($reader->nodeType!==XMLReader::ELEMENT) continue;
                if ($reader->localName==='numFmt') $formats[(int)$reader->getAttribute('numFmtId')]=$reader->getAttribute('formatCode');
                if ($inStyles && $reader->localName==='xf') {
                    $format=(int)$reader->getAttribute('numFmtId');
                    $code=preg_replace('/"[^"]*"|\\\\./','',$formats[$format]??'');
                    if (in_array($format,array_merge(range(14,22),range(27,36),range(50,58)),true) || preg_match('/[yd]/i',$code)) $dateStyles[$styleIndex]=true;
                    $styleIndex++;
                }
            }
            $reader->close(); $reader=null;
        }
        $sharedCount=0;
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $cacheFile=tempnam(sys_get_temp_dir(),'car7-strings-');
            if ($cacheFile===false) throw new RuntimeException('ไม่สามารถสร้างไฟล์พักข้อมูล');
            $db=new PDO('sqlite:'.$cacheFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF; PRAGMA cache_size=-8192; CREATE TABLE strings (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
            $insert=$db->prepare('INSERT INTO strings VALUES (?,?)'); $db->beginTransaction();
            $reader=car7_xml($file,'xl/sharedStrings.xml');
            while(car7_xml_read($reader)) if($reader->nodeType===XMLReader::ELEMENT && $reader->localName==='si') {
                $xml=simplexml_load_string($reader->readOuterXml(),SimpleXMLElement::class,LIBXML_NONET);
                if(!$xml) throw new RuntimeException('ข้อความใน Excel เสียหาย');
                $value=car7_xml_text($xml);
                if(strlen($value)>1048576) throw new RuntimeException('ข้อความหนึ่งช่องมีขนาดใหญ่เกินไป');
                $insert->execute([$sharedCount++,$value]);
                if($sharedCount%2000===0){$db->commit();$db->beginTransaction();}
                if($progress && $sharedCount%10000===0)$progress(['phase'=>'strings','strings'=>$sharedCount]);
            }
            $db->commit();$insert=null;$reader->close();$reader=null;
            $lookup=$db->prepare('SELECT value FROM strings WHERE id=?');
        }
        $zip->close();
        if($progress)$progress(['phase'=>'reading','strings'=>$sharedCount]);
        $reader=car7_xml($file,$sheet); $row=[]; $column=0; $cache=[]; $cacheBytes=0;
        while(car7_xml_read($reader)) {
            if($reader->localName==='row' && $reader->nodeType===XMLReader::ELEMENT){$row=[];$column=0;}
            if($reader->localName==='c' && $reader->nodeType===XMLReader::ELEMENT){
                $ref=$reader->getAttribute('r');
                if($ref && preg_match('/^([A-Z]+)[0-9]+$/',$ref,$m)) {$column=0;foreach(str_split($m[1])as$c)$column=$column*26+ord($c)-64;$column--;}
                if($column>16383)throw new RuntimeException('จำนวนคอลัมน์ Excel ไม่ถูกต้อง');
                $type=$reader->getAttribute('t');$style=(int)$reader->getAttribute('s');
                $cell=simplexml_load_string($reader->readOuterXml(),SimpleXMLElement::class,LIBXML_NONET);
                if(!$cell)throw new RuntimeException('เซลล์ Excel เสียหาย');
                $nodes=$cell->xpath('./*[local-name()="v"]');$value=(string)($nodes[0]??'');
                if($type==='s'){
                    if(!ctype_digit($value) || !$lookup || (int)$value >= $sharedCount)throw new RuntimeException('ข้อความอ้างอิงใน Excel ไม่ครบ');
                    $id=(int)$value;
                    if(!array_key_exists($id,$cache)){
                        $lookup->execute([$id]);$text=$lookup->fetchColumn();$lookup->closeCursor();
                        if($text===false)throw new RuntimeException('ไม่พบข้อความใน Excel');
                        if(count($cache)>=1000 || $cacheBytes>4*1024*1024){$cache=[];$cacheBytes=0;}
                        $cache[$id]=$text;$cacheBytes+=strlen($text);
                    }
                    $value=$cache[$id];
                }elseif($type==='inlineStr'){$nodes=$cell->xpath('./*[local-name()="is"]');$value=isset($nodes[0])?car7_xml_text($nodes[0]):'';}
                elseif($type==='e')throw new RuntimeException('พบเซลล์ Excel ที่มีข้อผิดพลาด: '.$ref.' '.$value);
                elseif($value!=='' && is_numeric($value) && isset($dateStyles[$style]))$value=car7_excel_date((float)$value,$date1904);
                if($cell->xpath('./*[local-name()="f"]') && $value==='')throw new RuntimeException('สูตร Excel ไม่มีผลลัพธ์ที่บันทึกไว้: '.$ref.' กรุณาคำนวณและบันทึกไฟล์ก่อนนำเข้า');
                $row[$column++]=$value;
            }
            if($reader->localName==='row' && $reader->nodeType===XMLReader::END_ELEMENT && $row)yield $row;
        }
    } finally {
        if($reader)$reader->close();
        $lookup=null;$insert=null;$db=null;
        if($cacheFile && is_file($cacheFile))unlink($cacheFile);
        libxml_clear_errors();libxml_use_internal_errors($oldErrors);
    }
}
function car7_stream_input(string $file,string $extension,?callable $progress=null):Generator {
    if($extension==='xlsx'){yield from car7_stream_xlsx($file,$progress);return;}
    if($extension!=='csv')throw new RuntimeException('รองรับ .xlsx และ .csv; ไฟล์ .xls ให้บันทึกเป็น .xlsx ก่อน');
    $handle=fopen($file,'rb');if(!$handle)throw new RuntimeException('ไม่สามารถเปิดไฟล์ CSV');
    try{$first=true;while(($row=fgetcsv($handle))!==false){if($first){$row[0]=preg_replace('/^\xEF\xBB\xBF/','',(string)($row[0]??''));$first=false;}yield $row;}}finally{fclose($handle);}
}
function car7_bulk_batch(mysqli $conn,array $batch):void {
    $conn->query('DELETE FROM car7_import_batch');$conn->query('DELETE FROM car7_import_matches');
    $columns=implode(',',COLUMNS);$params=[];
    foreach($batch as$row)array_push($params,...array_values($row));
    $values=implode(',',array_fill(0,count($batch),'('.implode(',',array_fill(0,count(COLUMNS),'?')).')'));
    $updates=implode(',',array_map(fn($c)=>"$c=VALUES($c)",COLUMNS));
    $statement=$conn->prepare("INSERT INTO car7_import_batch ($columns) VALUES $values ON DUPLICATE KEY UPDATE $updates");
    $statement->bind_param(str_repeat('s',count($params)),...$params);$statement->execute();$statement->close();
    $conn->begin_transaction();
    try{
        $conn->query('INSERT INTO car7_import_matches (plate_number,vehicle_id) SELECT b.plate_number, MIN(v.id) FROM car7_import_batch b JOIN vehicle_info v ON v.plate_number=b.plate_number GROUP BY b.plate_number');
        $assign=implode(',',array_map(fn($c)=>"v.$c=b.$c",COLUMNS));
        $conn->query("UPDATE vehicle_info v JOIN car7_import_matches m ON m.vehicle_id=v.id JOIN car7_import_batch b ON b.plate_number=m.plate_number SET $assign");
        $select=implode(',',array_map(fn($c)=>"b.$c",COLUMNS));
        $conn->query("INSERT INTO vehicle_info ($columns) SELECT $select FROM car7_import_batch b LEFT JOIN car7_import_matches m ON m.plate_number=b.plate_number WHERE m.vehicle_id IS NULL");
        $conn->commit();
    }catch(Throwable$error){$conn->rollback();throw$error;}
}
function car7_fast_import(mysqli $conn,string $file,string $extension,?callable $progress=null):int {
    $committed=0;$line=0;$skipped=0;$batch=[];$map=null;$locked=false;
    try{
        $locked=(int)$conn->query("SELECT GET_LOCK('CAR7_bulk_import',0)")->fetch_row()[0]===1;
        if(!$locked)throw new RuntimeException('มีงานนำเข้าอื่นกำลังทำงาน กรุณารอให้เสร็จก่อน');
        $conn->query('CREATE TEMPORARY TABLE car7_import_batch AS SELECT '.implode(',',COLUMNS).' FROM vehicle_info WHERE 1=0');
        $conn->query('ALTER TABLE car7_import_batch ADD UNIQUE KEY (plate_number)');
        $conn->query('CREATE TEMPORARY TABLE car7_import_matches (plate_number VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY, vehicle_id INT NOT NULL) ENGINE=InnoDB');
        foreach(car7_stream_input($file,$extension,$progress)as$row){
            $line++;
            if($map===null){
                if(!array_filter($row,fn($v)=>trim((string)$v)!==''))continue;
                $header=array_map(fn($h)=>mb_strtolower(trim((string)$h)),$row);$map=[];
                foreach(COLUMNS as$i=>$key){$map[$key]=array_search($key,$header,true);if($map[$key]===false)$map[$key]=array_search(mb_strtolower(HEADERS[$i]),$header,true);}
                if($map['plate_number']===false)throw new RuntimeException('ไม่พบหัวคอลัมน์ ทะเบียนรถ หรือ plate_number ในแผ่นงานแรก');
                continue;
            }
            $values=[];foreach($map as$key=>$index)$values[$key]=clean($key,$index===false?'':($row[$index]??''));
            if(!$values['plate_number']){$skipped++;continue;}
            $batch[]=$values;
            if(count($batch)===1000){car7_bulk_batch($conn,$batch);$committed+=count($batch);$batch=[];if($progress)$progress(['phase'=>'importing','committed'=>$committed,'line'=>$line,'skipped'=>$skipped]);}
        }
        if($batch){car7_bulk_batch($conn,$batch);$committed+=count($batch);}
        if(!$committed)throw new RuntimeException('ไม่พบรายการรถสำหรับนำเข้า');
        if($progress)$progress(['phase'=>'complete','committed'=>$committed,'line'=>$line,'skipped'=>$skipped]);
        return$committed;
    }catch(Throwable$error){throw new RuntimeException("นำเข้าหยุดที่แถว $line บันทึกสำเร็จแล้ว $committed รายการ: ".$error->getMessage(),0,$error);}
    finally{if($locked){$conn->query('DROP TEMPORARY TABLE IF EXISTS car7_import_matches,car7_import_batch');$conn->query("SELECT RELEASE_LOCK('CAR7_bulk_import')");}}
}
