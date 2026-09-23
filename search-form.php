<?php
$filters = car7_filters();
if (!isset($_GET['filters']) && $q !== '') $filters[$field] = $q;
?>
<form method="get" action="vehicle.php" class="row g-3 mb-4">
    <div class="col-12">
        <h2 class="h5">ค้นหาข้อมูลรถ</h2>
        <p class="text-secondary small mb-0">กรอกช่องที่ต้องการ ระบบจะแสดงรถที่ตรงทุกเงื่อนไข ค้นหาข้อความส่วนใดก็ได้ เช่น ปัตตานี ใช้ _ แทน 1 ตัวอักษรหรือตัวเลข เช่น ข_ 95__ สงขลา และใช้ % แทนข้อความกี่ตัวก็ได้ วันที่ใช้รูปแบบ วัน/เดือน/พ.ศ. เช่น 23/09/2569</p>
    </div>
    <?php foreach (array_combine(COLUMNS, HEADERS) as $searchKey => $searchLabel):
        $searchDate = in_array($searchKey, ['reg_date','tax_expire_date'], true);
    ?>
    <div class="col-md-6 col-xl-4">
        <label class="form-label" for="search-<?=e($searchKey)?>"><?=e($searchLabel)?><?=$searchDate ? ' (พ.ศ.)' : ''?></label>
        <input class="form-control" type="text" id="search-<?=e($searchKey)?>"
            name="filters[<?=e($searchKey)?>]" value="<?=e($filters[$searchKey] ?? '')?>"
            <?=$searchDate ? 'placeholder="dd/mm/พ.ศ." pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" maxlength="10"' : 'maxlength="500"'?>>
    </div>
    <?php endforeach; ?>
    <div class="col-12">
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass me-1"></i>ค้นหา</button>
        <a class="btn btn-outline-secondary" href="vehicle.php">ล้างเงื่อนไข</a>
    </div>
</form>
