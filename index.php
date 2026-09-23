<?php
session_start(); require_once __DIR__ . '/connect.php';
ob_start(function (string $html): string {
    $html = preg_replace('/(<a class="nav-link" href=")vehicle\\.php("><i class="fa-solid fa-car me-2")/', '$1record.php$2', $html);
    $html = preg_replace('/(<a class="nav-link" href=")vehicle\\.php#import("><i class="fa-solid fa-file-excel)/', '$1import.php$2', $html);
    $html = str_replace('href="vehicle.php#form"', 'href="record.php"', $html);
    $html = preg_replace('/<section class="card table-card">.*?<\\/section>/s', '', $html);
    global $provinceSummaryHtml;
    return str_replace('</main>', $provinceSummaryHtml . '</main>', $html);
});
ob_start(fn(string $html): string => preg_replace_callback('/<td>(\\d{4}-\\d{2}-\\d{2})<\\/td>/', fn($date) => '<td>' . buddhist_date($date[1]) . '</td>', $html));
require_once __DIR__.'/scale.php';
$stats=car7_stats($conn);
$total=$stats['total'];$brands=$stats['brands'];$types=$stats['types'];$expiring=$stats['expiring'];
$recent=$conn->query('SELECT plate_number,brand,model,vehicle_type,status,tax_expire_date FROM vehicle_info ORDER BY plate_number ASC,id ASC LIMIT 8');
$provinceResult = $conn->query("
    SELECT TRIM(SUBSTRING_INDEX(TRIM(plate_number), ' ', -1)) AS province,
           COALESCE(NULLIF(TRIM(vehicle_type), ''), 'ไม่ระบุประเภทรถ') AS vehicle_type,
           COUNT(*) AS total
    FROM vehicle_info
    WHERE plate_number IS NOT NULL AND TRIM(plate_number) <> ''
    GROUP BY province, vehicle_type
    ORDER BY province ASC, vehicle_type ASC
");
$provinceRows = [];
while ($row = $provinceResult->fetch_assoc()) {
    $province = $row['province'] ?: 'ไม่ระบุจังหวัด';
    $provinceRows[$province][] = $row;
}
$provinceSummaryHtml = '<section class="card table-card"><div class="card-body p-4"><div class="mb-3"><h2 class="h5 mb-1">สรุปรถตามจังหวัดทะเบียนและประเภท</h2><p class="text-secondary small mb-0">จังหวัดอ้างอิงจากคำท้ายของเลขทะเบียนรถ</p></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr class="text-secondary"><th>จังหวัดทะเบียน</th><th>ประเภทรถ</th><th class="text-end">จำนวนรถ (คัน)</th></tr></thead><tbody>';
foreach ($provinceRows as $province => $typesByProvince) {
    $provinceTotal = array_sum(array_map(fn($item) => (int)$item['total'], $typesByProvince));
    foreach ($typesByProvince as $index => $item) {
        $provinceCell = $index === 0 ? '<td rowspan="' . count($typesByProvince) . '" class="fw-semibold">' . e($province) . '</td>' : '';
        $provinceSummaryHtml .= '<tr>' . $provinceCell . '<td>' . e($item['vehicle_type']) . '</td><td class="text-end">' . number_format((int)$item['total']) . '</td></tr>';
    }
    $provinceSummaryHtml .= '<tr class="table-light fw-bold"><td colspan="2">รวมทุกประเภทรถ — ' . e($province) . '</td><td class="text-end">' . number_format($provinceTotal) . '</td></tr>';
}
$provinceSummaryHtml .= '</tbody></table></div></div></section>';
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CAR7 | Dashboard</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet"><style>body{background:#f5f7fb;font-family:Tahoma,"Segoe UI",sans-serif;color:#243047}.sidebar{background:#17253e;min-height:100vh;width:265px;position:fixed;inset:0 auto 0 0}.brand{padding:28px 26px;color:#fff;font-weight:700;font-size:1.45rem;border-bottom:1px solid #33445f}.nav-link{margin:8px 13px;padding:13px 15px!important;border-radius:9px;color:#c8d2e2!important}.nav-link:hover,.nav-link.active{background:#2f80ed;color:#fff!important}.main{margin-left:265px;padding:34px}.stat,.table-card{border:0;border-radius:16px;box-shadow:0 5px 18px #1b2b4810}.icon{width:54px;height:54px;border-radius:14px;display:grid;place-items:center;font-size:1.4rem}@media(max-width:768px){.sidebar{position:static;width:auto;min-height:auto}.main{margin:0;padding:20px}}</style></head><body><?php require __DIR__ . '/sidebar.php'; ?><main class="main"><div class="d-flex justify-content-between align-items-center mb-4"><div><h1 class="h3 mb-1">แดชบอร์ดข้อมูลรถ</h1><p class="text-secondary mb-0">ภาพรวมข้อมูลในระบบ CAR7</p></div><a class="btn btn-primary" href="vehicle.php#form"><i class="fa-solid fa-plus me-1"></i>เพิ่มข้อมูลรถ</a></div><p class="text-secondary small">จำนวนรถทั้งหมดเป็นค่าประมาณ · สถิติอัปเดตทุก 5 นาที</p><div class="row g-3 mb-4"><?php foreach([['รถทั้งหมด',$total,'fa-car','#e8f1ff','#2f80ed'],['ยี่ห้อรถ',$brands,'fa-tags','#eaf8f0','#27ae60'],['ประเภทรถ',$types,'fa-layer-group','#fff3e7','#f2994a'],['ภาษีใกล้หมดอายุ',$expiring,'fa-calendar-xmark','#fdeced','#e74c3c']] as $s):?><div class="col-sm-6 col-xl-3"><div class="card stat"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="text-secondary small"><?=e($s[0])?></div><div class="fs-2 fw-bold"><?=number_format($s[1])?></div></div><div class="icon" style="background:<?=$s[3]?>;color:<?=$s[4]?>"><i class="fa-solid <?=$s[2]?>"></i></div></div></div></div><?php endforeach?></div><section class="card table-card"><div class="card-body p-4"><div class="d-flex justify-content-between mb-3"><h2 class="h5 mb-0">รายการรถ</h2><a href="vehicle.php" class="text-decoration-none">ดูทั้งหมด <i class="fa-solid fa-arrow-right"></i></a></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr class="text-secondary"><th>ทะเบียนรถ</th><th>ยี่ห้อ / รุ่น</th><th>ประเภท</th><th>สถานะ</th><th>ภาษีหมดอายุ</th></tr></thead><tbody><?php if($recent->num_rows):while($r=$recent->fetch_assoc()):?><tr><td class="fw-semibold"><?=e($r['plate_number']?:'-')?></td><td><?=e(trim(($r['brand']??'').' '.($r['model']??''))?:'-')?></td><td><?=e($r['vehicle_type']?:'-')?></td><td><span class="badge text-bg-light border"><?=e($r['status']?:'-')?></span></td><td><?=e($r['tax_expire_date']?:'-')?></td></tr><?php endwhile;else:?><tr><td colspan="5" class="text-center py-5 text-secondary">ยังไม่มีข้อมูลรถ <a href="vehicle.php#form">เพิ่มรายการแรก</a></td></tr><?php endif?></tbody></table></div></div></section></main></body></html>
