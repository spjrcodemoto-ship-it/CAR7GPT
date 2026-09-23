<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <strong id="search-total" role="status" aria-live="polite">กำลังนับผลลัพธ์ทั้งหมด…</strong>
    <span class="text-secondary">แสดงหน้านี้ <?=number_format(count($pageRows))?> รายการ</span>
    <button id="retry-search-count" class="btn btn-sm btn-outline-secondary" type="button" hidden>ลองนับอีกครั้ง</button>
</div>
<p class="text-secondary small">Export แบ่งไฟล์ละไม่เกิน 500,000 รายการ (ไม่รวมหัวคอลัมน์) หากมีหลายไฟล์จะดาวน์โหลดเป็น ZIP · ข้อมูลจำนวนมากอาจใช้เวลาเตรียมไฟล์</p>
<script>
(() => {
    const status = document.getElementById('search-total');
    const retry = document.getElementById('retry-search-count');
    const url = <?=json_encode('search-count.php?' . http_build_query(car7_search_params($q, $field)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
    async function updateTotal() {
        retry.hidden = true;
        status.textContent = 'กำลังนับผลลัพธ์ทั้งหมด…';
        try {
            const response = await fetch(url, {cache: 'no-store'});
            if (!response.ok) throw new Error('Count failed');
            const result = await response.json();
            if (!Number.isSafeInteger(result.total) || result.total < 0) throw new Error('Invalid total');
            status.textContent = 'พบทั้งหมด ' + new Intl.NumberFormat('th-TH').format(result.total) + ' รายการ';
        } catch (error) {
            status.textContent = 'ไม่สามารถนับผลลัพธ์ทั้งหมดได้';
            retry.hidden = false;
        }
    }
    retry.addEventListener('click', updateTotal);
    updateTotal();
})();
</script>
