<?php
$sidebarPage = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
$sidebarItems = [
    'index.php' => ['แดชบอร์ด', 'fa-chart-pie'],
    'record.php' => ['บันทึกข้อมูลรถ', 'fa-car'],
    'vehicle.php' => ['ค้นหาข้อมูลรถ', 'fa-magnifying-glass'],
    'import.php' => ['นำเข้าไฟล์ Excel / CSV', 'fa-file-excel'],
];
?>
<aside class="sidebar">
    <div class="brand"><i class="fa-solid fa-car-side me-2" aria-hidden="true"></i>CAR7</div>
    <nav class="nav flex-column mt-3" aria-label="เมนูหลัก">
        <?php foreach ($sidebarItems as $sidebarUrl => [$sidebarLabel, $sidebarIcon]): ?>
        <a class="nav-link<?=$sidebarPage === $sidebarUrl ? ' active' : ''?>" href="<?=e($sidebarUrl)?>"<?=$sidebarPage === $sidebarUrl ? ' aria-current="page"' : ''?>>
            <i class="fa-solid <?=e($sidebarIcon)?> me-2" aria-hidden="true"></i><?=e($sidebarLabel)?>
        </a>
        <?php endforeach; ?>
    </nav>
</aside>
