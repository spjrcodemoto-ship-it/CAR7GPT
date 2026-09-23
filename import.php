<?php
define('CAR7_LIBRARY_ONLY',true);
require __DIR__.'/vehicle.php';
$csrf=$_SESSION['csrf'];
car7_end_buffers();
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CAR7 | นำเข้าข้อมูลรถ</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>body{margin:0;background:#f5f7fb;font-family:Tahoma,"Segoe UI",sans-serif;color:#243047}.sidebar{background:#17253e;min-height:100vh;width:265px;position:fixed;inset:0 auto 0 0}.brand{padding:28px 26px;color:white;font-weight:700;font-size:1.45rem;border-bottom:1px solid #33445f}.nav-link{display:block;margin:8px 13px;padding:13px 15px;border-radius:9px;color:#c8d2e2;text-decoration:none}.nav-link.active,.nav-link:hover{background:#2f80ed;color:white}.main{margin-left:265px;padding:34px;max-width:1400px}.card{border:0;border-radius:16px;box-shadow:0 5px 18px #1b2b4810;padding:28px}.muted{color:#64748b}.progress{height:14px}.file-row{padding:12px 0;border-bottom:1px solid #e9edf3;overflow-wrap:anywhere}#status{white-space:pre-wrap;overflow-wrap:anywhere}button{min-height:42px}@media(max-width:768px){.sidebar{position:static;width:auto;min-height:auto}.main{margin:0;padding:20px}}</style></head>
<body><?php require __DIR__ . '/sidebar.php'; ?>
<main class="main"><h1 class="h3">นำเข้าข้อมูลรถ</h1><p class="muted mb-4">เลือกได้หลายไฟล์ ระบบจะนำเข้าตามลำดับและแสดงผลของแต่ละไฟล์</p>
<section class="card"><h2 class="h5">ไฟล์ Excel และ CSV ขนาดใหญ่</h2><p class="muted">รองรับ .xlsx และ .csv สูงสุด 2 GB ต่อไฟล์ · เลือกหลายไฟล์รวม 5 GB ได้</p>
<label class="form-label" for="files">เลือกไฟล์ที่ต้องการนำเข้า</label><input class="form-control" id="files" type="file" accept=".xlsx,.csv" multiple>
<div id="queue" class="my-3"></div><div class="d-flex flex-wrap gap-2"><button id="start" class="btn btn-primary" disabled>เริ่มนำเข้า</button><button id="retry" class="btn btn-outline-primary" hidden>ลองนำเข้าไฟล์นี้อีกครั้ง</button><button id="discard" class="btn btn-outline-secondary" hidden>ล้างไฟล์พักและเลือกใหม่</button></div>
<div class="mt-4"><div class="progress" role="progressbar" aria-label="ความคืบหน้าการอัปโหลด" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div id="bar" class="progress-bar" style="width:0%"></div></div><div id="status" role="status" aria-live="polite" class="mt-3 muted">พร้อมนำเข้า</div></div>
<div id="results" class="mt-3"></div></section>
<section class="mt-4"><h2 class="h6">ก่อนเริ่มนำเข้า</h2><ul class="muted"><li>Excel อ่านแผ่นงานแรก ใช้แถวแรกที่มีข้อมูลเป็นหัวคอลัมน์ภาษาไทย หรือชื่อฟิลด์ภาษาอังกฤษจากไฟล์ส่งออก</li><li>ไฟล์ .xls ให้บันทึกเป็น .xlsx ก่อน หากมีสูตร ให้คำนวณและบันทึกผลใน Excel ก่อนนำเข้า</li><li>ทะเบียนที่มีอยู่แล้วจะอัปเดตรายการเดิม ไฟล์ควรมีทุกคอลัมน์เพื่อไม่ให้ข้อมูลเดิมถูกแทนด้วยค่าว่าง</li><li>หากบางชุดมีข้อผิดพลาด ชุดที่บันทึกสำเร็จแล้วจะคงอยู่ ระบบแจ้งจำนวนให้ทราบ และสามารถนำเข้าไฟล์เดิมซ้ำได้</li><li>เปิดหน้านี้ไว้จนจบคิว หากการเชื่อมต่อขาดระหว่างอัปโหลด ให้เลือกไฟล์เดิมเพื่อต่อจากส่วนที่สำเร็จแล้ว ระหว่างนำเข้าควรหลีกเลี่ยงการแก้ไขข้อมูลรถเดียวกันจากหน้าอื่น</li></ul></section></main>
<script>const CAR7_CSRF=<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script><script src="import-large.js"></script></body></html>
