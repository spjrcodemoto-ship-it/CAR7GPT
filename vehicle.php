<?php
session_start(); require_once __DIR__.'/connect.php';
require_once __DIR__.'/scale.php';
ob_start(function (string $html): string {
    $html = preg_replace_callback('/<input([^>]*name="(?:reg_date|tax_expire_date)"[^>]*)>/', function ($match) {
        $input = str_replace('type="date"', 'type="text"', $match[0]);
        $input = preg_replace_callback('/value="(\\d{4}-\\d{2}-\\d{2})"/', fn($date) => 'value="' . buddhist_date($date[1]) . '"', $input);
        return str_replace('<input', '<input placeholder="dd/mm/พ.ศ."', $input);
    }, $html);
    $html = preg_replace_callback('/<td>(\\d{4}-\\d{2}-\\d{2})<\\/td>/', fn($date) => '<td>' . buddhist_date($date[1]) . '</td>', $html);
    $html = preg_replace('/(<td class="text-end">)(<a class="btn btn-sm btn-outline-primary" href="vehicle\\.php\\?edit=(\\d+)#form">)/', '$1<a class="btn btn-sm btn-outline-secondary me-1" href="vehicle.php?view=$3" title="ดูข้อมูล"><i class="fa-solid fa-eye"></i></a>$2', $html);
    $mode = defined('PAGE_MODE') ? PAGE_MODE : 'vehicle';
    $html = preg_replace('/(<a class="nav-link(?: active)?" href=")vehicle\\.php("><i class="fa-solid fa-car me-2")/', '$1record.php$2', $html);
    $html = preg_replace('/(<a class="nav-link" href=")#import("><i class="fa-solid fa-file-excel)/', '$1import.php$2', $html);
    if ($mode === 'vehicle') {
        $html = preg_replace('/<section class="card mb-4" id="form">.*?<\\/section><section class="card mb-4" id="import">.*?<\\/section>/s', '', $html);
    } elseif ($mode === 'record') {
        $html = preg_replace('/<section class="card mb-4" id="import">.*?<\\/main>/s', '</main>', $html);
    } elseif ($mode === 'import') {
        $html = preg_replace('/<section class="card mb-4" id="form">.*?<\\/section>/s', '', $html);
        $html = preg_replace('/<section class="card"><div class="card-body p-4">.*?<\\/main>/s', '</main>', $html);
    }
    global $q, $viewHtml, $field, $hasSearch;
    if ($mode !== 'vehicle' || empty($hasSearch)) {
        $html = preg_replace('/<a class="btn btn-outline-success"[^>]*>.*?<\/a>/s', '', $html);
        $html = preg_replace('/<a class="btn btn-success" href="vehicle\\.php\\?action=export">.*?<\\/a>/', '', $html);
    } else {
        $html = str_replace('href="vehicle.php?action=export"', 'href="' . e('vehicle.php?' . http_build_query(['action'=>'export'] + car7_search_params($q ?? '', $field ?? 'plate_number'))) . '"', $html);
    }
    if (!empty($viewHtml)) $html = str_replace('<main class="main">', '<main class="main">' . $viewHtml, $html);
    if (isset($_GET['view']) && $mode === 'vehicle') {
        $html = preg_replace('/<section class="card"><div class="card-body p-4">.*?<\/main>/s', '</main>', $html);
    }
    return $html;
});
const COLUMNS=['plate_number','vehicle_type','brand','model','color','chassis_number','engine_number','reg_date','tax_expire_date','status','owner_address'];
const HEADERS=['ทะเบียนรถ','ประเภทรถ','ยี่ห้อ','รุ่น','สี','เลขตัวถัง','เลขเครื่องยนต์','วันที่จดทะเบียน','วันหมดอายุภาษี','สถานะ','ที่อยู่เจ้าของ'];
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
function go(): never {
    $page = defined('PAGE_MODE') ? PAGE_MODE : 'vehicle';
    header('Location: ' . $page . '.php');
    exit;
}
function valid():bool{return hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'');}
function clean(string $key,mixed $value):?string{
    $v=trim((string)$value); if($v==='') return null;
    if(in_array($key,['reg_date','tax_expire_date'],true)){
        $d=DateTime::createFromFormat('Y-m-d',$v)?:DateTime::createFromFormat('d/m/Y',$v)?:DateTime::createFromFormat('d-m-Y',$v);
        if(!$d) return null;
        if((int)$d->format('Y')>2400) $d->modify('-543 years');
        return $d->format('Y-m-d');
    }
    return mb_substr($v,0,$key==='owner_address'?5000:($key==='plate_number'?20:(in_array($key,['color','chassis_number','engine_number'],true)?50:100)));
}
function store(mysqli $conn,array $data,int $id=0):bool {
    static $statements = [];
    $connectionId = spl_object_id($conn);
    if (!isset($statements[$connectionId])) $statements[$connectionId] = [
        'lookup' => $conn->prepare('SELECT id FROM vehicle_info WHERE plate_number = ? ORDER BY id ASC LIMIT 1'),
        'insert' => $conn->prepare('INSERT INTO vehicle_info (plate_number,vehicle_type,brand,model,color,chassis_number,engine_number,reg_date,tax_expire_date,status,owner_address) VALUES (?,?,?,?,?,?,?,?,?,?,?)'),
        'update' => $conn->prepare('UPDATE vehicle_info SET plate_number=?,vehicle_type=?,brand=?,model=?,color=?,chassis_number=?,engine_number=?,reg_date=?,tax_expire_date=?,status=?,owner_address=? WHERE id=?')
    ];
    $v=[]; foreach(COLUMNS as $key) $v[$key]=clean($key,$data[$key]??'');
    if (!$v['plate_number']) throw new RuntimeException('กรุณากรอกทะเบียนรถ');
    $exists=false;
    if (!$id) {
        $lookup=$statements[$connectionId]['lookup']; $lookup->bind_param('s',$v['plate_number']); $lookup->execute();
        $found=$lookup->get_result()->fetch_assoc();
        if ($found) { $id=(int)$found['id']; $exists=true; }
    }
    $params=array_values($v); $types='sssssssssss';
    $statement=$statements[$connectionId][$id?'update':'insert'];
    if ($id) { $params[]=$id; $types.='i'; }
    $statement->bind_param($types,...$params); $statement->execute();
    return $exists;
}
function col(string $ref):int{preg_match('/[A-Z]+/',strtoupper($ref),$m);$n=0;foreach(str_split($m[0]??'A')as$c)$n=$n*26+ord($c)-64;return$n-1;}
function x(string $v):string{return htmlspecialchars($v,ENT_XML1|ENT_QUOTES,'UTF-8');}
function export_search_xlsx(mysqli $conn, string $search): never {
    require_once __DIR__ . '/export-engine.php';
    car7_export_large($conn, $search, car7_search_field(), 'xlsx');
}
if (defined('CAR7_LIBRARY_ONLY')) return;
if (($_GET['action'] ?? '') === 'export_csv') car7_export_csv($conn, trim($_GET['q'] ?? ''), car7_search_field());
if(($_GET['action']??'')==='export')export_search_xlsx($conn, trim($_GET['q'] ?? ''));
if($_SERVER['REQUEST_METHOD']==='POST'){if(!valid()){flash('error','คำขอไม่ถูกต้อง');go();}try{$action=$_POST['action']??'';if($action==='save'){store($conn,$_POST,(int)($_POST['id']??0));flash('success',(int)($_POST['id']??0)?'แก้ไขข้อมูลรถเรียบร้อย':'เพิ่มข้อมูลรถเรียบร้อย');}elseif($action==='delete'){$s=$conn->prepare('DELETE FROM vehicle_info WHERE id=?');$id=(int)$_POST['id'];$s->bind_param('i',$id);$s->execute();flash('success','ลบข้อมูลรถเรียบร้อย');}elseif($action==='import'){if(empty($_FILES['excel']['tmp_name']))throw new RuntimeException('กรุณาเลือกไฟล์');if ($_FILES['excel']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ');set_time_limit(0);$count=car7_import($conn,$_FILES['excel']['tmp_name'],strtolower(pathinfo($_FILES['excel']['name'],PATHINFO_EXTENSION)));flash('success',"นำเข้าข้อมูลรถ $count รายการเรียบร้อย");}go();}catch(Throwable$ex){try{$conn->rollback();}catch(Throwable){}flash('error',$ex->getMessage());go();}}
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$field = car7_search_field();
if ($q === '' || isset($_GET['filters'])) $field = 'plate_number';
$mode = defined('PAGE_MODE') ? PAGE_MODE : 'vehicle';
$ok = flash('success'); $err = flash('error');
// A slow search must not block other tabs or the detail page in this session.
session_write_close();
$hasSearch = isset($_GET['filters']) ? car7_filters() !== [] : $q !== '';
$searchReturnUrl = car7_page_url(
    is_string($_GET['cursor'] ?? null) ? $_GET['cursor'] : '',
    ($_GET['back'] ?? '') === '1', $q, $field
);
[$pageRows, $previousCursor, $nextCursor] = $mode === 'vehicle' && $hasSearch && !isset($_GET['view'])
    ? car7_page($conn, $q, $field, (string)($_GET['cursor'] ?? ''), ($_GET['back'] ?? '') === '1')
    : [[], '', ''];
$edit=null;if(isset($_GET['edit'])){$s=$conn->prepare('SELECT * FROM vehicle_info WHERE id=?');$id=(int)$_GET['edit'];$s->bind_param('i',$id);$s->execute();$edit=$s->get_result()->fetch_assoc();}
$viewHtml = '';
if (isset($_GET['view'])) {
    $stmt = $conn->prepare('SELECT * FROM vehicle_info WHERE id = ?');
    $viewId = (int)$_GET['view'];
    $stmt->bind_param('i', $viewId);
    $stmt->execute();
    if ($vehicle = $stmt->get_result()->fetch_assoc()) {
        $viewHtml = '<section class="card mb-4"><div class="card-body p-4"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0"><i class="fa-solid fa-eye me-2"></i>รายละเอียดรถ: ' . e($vehicle['plate_number']) . '</h2><a class="btn btn-sm btn-outline-secondary" href="' . e($searchReturnUrl . '#search-results') . '">ปิด</a></div><div class="row g-3">';
        foreach (array_combine(COLUMNS, HEADERS) as $formField => $label) {
            $value = in_array($formField, ['reg_date', 'tax_expire_date'], true) ? buddhist_date($vehicle[$formField]) : ($vehicle[$formField] ?: '-');
            $viewHtml .= '<div class="col-md-6 col-xl-4"><div class="text-secondary small">' . e($label) . '</div><div class="fw-semibold">' . nl2br(e($value)) . '</div></div>';
        }
        $viewHtml .= '</div></div></section>';
    } else { $viewHtml = '<div class="alert alert-warning">ไม่พบข้อมูลรถที่เลือก <a href="' . e($searchReturnUrl . '#search-results') . '">กลับผลการค้นหา</a></div>'; }
}
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CAR7 | จัดการข้อมูลรถ</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet"><style>body{background:#f5f7fb;font-family:Tahoma,"Segoe UI",sans-serif;color:#243047}.sidebar{background:#17253e;min-height:100vh;width:265px;position:fixed;inset:0 auto 0 0}.brand{padding:28px 26px;color:#fff;font-weight:700;font-size:1.45rem;border-bottom:1px solid #33445f}.nav-link{margin:8px 13px;padding:13px 15px!important;border-radius:9px;color:#c8d2e2!important}.nav-link:hover,.nav-link.active{background:#2f80ed;color:#fff!important}.main{margin-left:265px;padding:34px}.card{border:0;border-radius:16px;box-shadow:0 5px 18px #1b2b4810}.form-label{font-size:.88rem;font-weight:600}.required:after{content:" *";color:#dc3545}@media(max-width:768px){.sidebar{position:static;width:auto;min-height:auto}.main{margin:0;padding:20px}}</style></head><body><?php require __DIR__ . '/sidebar.php'; ?><main class="main"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4"><div><h1 class="h3 mb-1">จัดการข้อมูลรถ</h1><p class="text-secondary mb-0">เพิ่ม แก้ไข ลบ ค้นหา และนำเข้าข้อมูลรถ</p></div><div class="d-flex flex-wrap align-items-center gap-2"><a class="btn btn-success" href="vehicle.php?action=export"><i class="fa-solid fa-file-export me-1"></i>Excel</a><a class="btn btn-outline-success" href="<?=e('vehicle.php?' . http_build_query(['action'=>'export_csv'] + car7_search_params($q, $field)))?>">ส่งออก CSV ทั้งหมด</a></div></div><?php if($ok):?><div class="alert alert-success"><?=e($ok)?></div><?php endif;if($err):?><div class="alert alert-danger"><?=e($err)?></div><?php endif?><section class="card mb-4" id="form"><div class="card-body p-4"><h2 class="h5 mb-4"><?=$edit?'แก้ไขข้อมูลรถ':'บันทึกข้อมูลรถ'?></h2><form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e((string)($edit['id']??''))?>"><?php foreach(array_combine(COLUMNS,HEADERS)as$formField=>$label):$area=$formField==='owner_address';$date=in_array($formField,['reg_date','tax_expire_date'],true);?><div class="<?=$area?'col-12':'col-md-6 col-xl-4'?>"><label class="form-label <?=$formField==='plate_number'?'required':''?>"><?=e($label)?></label><?php if($area):?><textarea class="form-control" name="<?=$formField?>" rows="2"><?=e($edit[$formField]??'')?></textarea><?php else:?><input class="form-control" type="<?=$date?'date':'text'?>" name="<?=$formField?>" value="<?=e($edit[$formField]??'')?>" <?=$formField==='plate_number'?'required':''?>><?php endif?></div><?php endforeach?><div class="col-12"><button class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i><?=$edit?'บันทึกการแก้ไข':'บันทึกข้อมูล'?></button><?php if($edit):?><a class="btn btn-outline-secondary ms-1" href="vehicle.php#form">ยกเลิก</a><?php endif?></div></form></div></section><section class="card mb-4" id="import"><div class="card-body p-4"><h2 class="h5 mb-1">นำเข้าไฟล์ Excel</h2><p class="text-secondary small">รองรับ .xlsx และ .csv โดยใช้หัวคอลัมน์ภาษาไทย หรือชื่อฟิลด์ภาษาอังกฤษจากไฟล์ Export</p><p class="text-secondary small">นำเข้าไฟล์ Excel / CSV ขนาดใหญ่ได้ที่หน้านำเข้า รองรับหลายไฟล์สูงสุด 2 GB ต่อไฟล์</p><form method="post" enctype="multipart/form-data" class="row g-2 align-items-center"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="import"><div class="col-md-6"><input class="form-control" type="file" name="excel" accept=".xlsx,.csv" required></div><div class="col-auto"><button class="btn btn-outline-primary"><i class="fa-solid fa-file-import me-1"></i>นำเข้าข้อมูล</button></div></form></div></section><section class="card"><div class="card-body p-4"><?php require __DIR__ . '/search-form.php'; ?><?php if ($hasSearch): ?><?php require __DIR__ . '/search-summary.php'; ?><div class="table-responsive" id="search-results"><table class="table table-hover align-middle"><thead><tr class="text-secondary"><th>ทะเบียนรถ</th><th>ประเภทรถ</th><th>ยี่ห้อ / รุ่น</th><th>สี</th><th>เลขตัวถัง</th><th>สถานะ</th><th>ภาษีหมดอายุ</th><th class="text-end">จัดการ</th></tr></thead><tbody><?php if($pageRows):foreach($pageRows as $v):?><tr><td class="fw-semibold"><?=e($v['plate_number']?:'-')?></td><td><?=e($v['vehicle_type']?:'-')?></td><td><?=e(trim(($v['brand']??'').' '.($v['model']??''))?:'-')?></td><td><?=e($v['color']?:'-')?></td><td><?=e($v['chassis_number']?:'-')?></td><td><span class="badge text-bg-light border"><?=e($v['status']?:'-')?></span></td><td><?=e($v['tax_expire_date']?:'-')?></td><td class="text-end"><a class="btn btn-sm btn-outline-secondary me-1" href="<?=e($searchReturnUrl . '&view=' . (int)$v['id'])?>" title="ดูข้อมูล"><i class="fa-solid fa-eye"></i></a><a class="btn btn-sm btn-outline-primary" href="record.php?edit=<?=$v['id']?>#form"><i class="fa-solid fa-pen"></i></a><form method="post" class="d-inline" onsubmit="return confirm('ยืนยันการลบข้อมูลรถ?')"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$v['id']?>"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button></form></td></tr><?php endforeach;else:?><tr><td colspan="8" class="text-center text-secondary py-5">ไม่พบข้อมูลรถ</td></tr><?php endif?></tbody></table></div><div class="d-flex flex-wrap align-items-center gap-2 mt-3"><span class="text-secondary">แสดง <?=count($pageRows)?> รายการ · หน้าละ 50 รายการ · เรียงตามช่องค้นหา</span><a class="btn btn-sm btn-outline-secondary" href="<?=e(car7_page_url('',false,$q,$field))?>">หน้าแรก</a><?php if($previousCursor!==''):?><a class="btn btn-sm btn-outline-primary" href="<?=e(car7_page_url($previousCursor,true,$q,$field))?>">ก่อนหน้า</a><?php endif;if($nextCursor!==''):?><a class="btn btn-sm btn-primary" href="<?=e(car7_page_url($nextCursor,false,$q,$field))?>">ถัดไป</a><?php endif?></div><?php else: ?><p class="text-secondary mb-0">กรอกเงื่อนไขอย่างน้อยหนึ่งช่อง แล้วกดค้นหาเพื่อแสดงข้อมูลรถ</p><?php endif; ?></div></section></main></body></html>
