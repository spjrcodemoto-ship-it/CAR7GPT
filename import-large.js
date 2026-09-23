'use strict';
const input=document.querySelector('#files'),start=document.querySelector('#start'),retry=document.querySelector('#retry'),discard=document.querySelector('#discard'),statusBox=document.querySelector('#status'),queue=document.querySelector('#queue'),results=document.querySelector('#results'),bar=document.querySelector('#bar');
const CHUNK=8*1024*1024, MAX=2*1024*1024*1024;
let busy=false, active=null, timer=null;
const saved=()=>{try{return JSON.parse(sessionStorage.getItem('car7-import-current')||'null');}catch{return null;}};
function remember(value){active=value;try{if(value)sessionStorage.setItem('car7-import-current',JSON.stringify(value));else sessionStorage.removeItem('car7-import-current');}catch{}}
function show(text){statusBox.textContent=text;}
function setProgress(value){value=Math.max(0,Math.min(100,value));bar.style.width=value+'%';bar.parentElement.setAttribute('aria-valuenow',Math.round(value));}
function controls(){input.disabled=busy;start.disabled=busy||!input.files.length;retry.hidden=busy||!active||!active.ready;discard.hidden=busy||!active;}
async function api(action,token='',body=null,extra=''){
    const response=await fetch('import-api.php?action='+encodeURIComponent(action)+(token?'&token='+encodeURIComponent(token):'')+extra,{method:'POST',headers:{'X-CSRF-Token':CAR7_CSRF,'Content-Type':body instanceof Blob?'application/octet-stream':'application/json'},body:body instanceof Blob?body:(body?JSON.stringify(body):null)});
    let data;try{data=await response.json();}catch{throw new Error('เซิร์ฟเวอร์ไม่ตอบกลับตามปกติ กรุณาตรวจการเชื่อมต่อแล้วลองใหม่');}
    if(!response.ok){const error=new Error(data.error||'คำขอไม่สำเร็จ');error.data=data;throw error;}return data;
}
function displayJob(job){
    const name=job.name||active?.name||'';
    if(job.phase==='strings')show(name+'\nกำลังเตรียมข้อความใน Excel '+(job.strings||0).toLocaleString()+' รายการ');
    else if(job.phase==='uploading'){setProgress(job.received/job.size*100);show(name+'\nอัปโหลด '+(job.received/1048576).toFixed(1)+' / '+(job.size/1048576).toFixed(1)+' MB');}
    else if(job.phase==='failed')show(job.error||'นำเข้าไม่สำเร็จ');
    else if(job.phase==='complete')show(name+'\nนำเข้าสำเร็จ '+job.committed.toLocaleString()+' รายการ'+(job.skipped?' · ข้ามแถวไม่มีทะเบียน '+job.skipped.toLocaleString():'') );
    else show(name+'\nกำลังนำเข้า · บันทึกแล้ว '+(job.committed||0).toLocaleString()+' รายการ'+(job.line?' · อ่านถึงแถว '+job.line.toLocaleString():''));
}
function addResult(job){const item=document.createElement('div');item.className='alert alert-success';item.textContent=job.name+' — สำเร็จ '+job.committed.toLocaleString()+' รายการ';results.append(item);}
async function monitor(token){
    while(true){const job=await api('status',token);displayJob(job);if(job.phase==='complete')return job;if(job.phase==='failed')throw new Error(job.error);await new Promise(resolve=>setTimeout(resolve,1500));}
}
async function runImport(token){
    timer=setInterval(async()=>{try{displayJob(await api('status',token));}catch{}},1500);
    try{
        try{return await api('run',token);}catch(error){
            const job=await api('status',token);
            if(job.phase==='complete')return job;
            if(!['failed','uploading','discarded'].includes(job.phase))return await monitor(token);
            throw new Error(job.error||error.message);
        }
    }finally{clearInterval(timer);timer=null;}
}
async function upload(file){
    let job=null;
    if(active && (active.name!==file.name || active.size!==file.size || active.modified!==file.lastModified))throw new Error('มีไฟล์พักของ '+active.name+' อยู่ กรุณาลองนำเข้าไฟล์นั้นอีกครั้ง หรือล้างไฟล์พักก่อนเลือกไฟล์ใหม่');
    if(active && active.name===file.name && active.size===file.size && active.modified===file.lastModified){try{job=await api('status',active.token);}catch{}}
    if(!job||job.phase==='discarded'||job.phase==='complete'){
        job=await api('init','',{name:file.name,size:file.size});remember({token:job.token,name:file.name,size:file.size,modified:file.lastModified,ready:false});
    }
    if(!['uploading','failed'].includes(job.phase))return await monitor(job.token);
    let offset=job.received;
    while(offset<file.size){
        let sent=false;
        for(let attempt=0;attempt<3&&!sent;attempt++){
            try{job=await api('chunk',job.token,file.slice(offset,Math.min(offset+CHUNK,file.size)),'&offset='+offset);offset=job.received;sent=true;displayJob(job);}
            catch(error){if(attempt===2)throw error;await new Promise(resolve=>setTimeout(resolve,1000*(attempt+1)));job=await api('status',job.token);if(job.received>offset){offset=job.received;sent=true;}}
        }
    }
    remember({...active,ready:true});setProgress(100);show(file.name+'\nอัปโหลดครบ กำลังเริ่มนำเข้า');
    return await runImport(job.token);
}
input.addEventListener('change',()=>{
    queue.replaceChildren();let total=0;
    for(const file of input.files){total+=file.size;const item=document.createElement('div');item.className='file-row';item.textContent=file.name+' · '+(file.size/1048576).toFixed(1)+' MB';queue.append(item);}
    if(input.files.length){const info=document.createElement('p');info.className='mt-2 muted';info.textContent=input.files.length+' ไฟล์ · รวม '+(total/1073741824).toFixed(2)+' GB';queue.append(info);}controls();
});
start.addEventListener('click',async()=>{
    const files=Array.from(input.files);
    if(files.some(file=>file.size<=0||file.size>MAX||! /\.(xlsx|csv)$/i.test(file.name))){show('กรุณาเลือกไฟล์ .xlsx หรือ .csv ขนาดไม่เกิน 2 GB ต่อไฟล์');return;}
    busy=true;controls();let done=0;
    try{for(const file of files){const result=await upload(file);displayJob(result);addResult(result);remember(null);done++;}show('นำเข้าครบ '+done+' ไฟล์ ดูผลของแต่ละไฟล์ด้านล่าง');input.value='';}
    catch(error){show(error.message+'\nคิวหยุดที่ไฟล์นี้เพื่อให้ตรวจสอบก่อน ไฟล์ที่เสร็จแล้วไม่ต้องนำเข้าซ้ำ');}
    finally{busy=false;controls();}
});
retry.addEventListener('click',async()=>{if(!active)return;busy=true;controls();try{const job=await runImport(active.token);displayJob(job);addResult(job);remember(null);}catch(error){show(error.message);}finally{busy=false;controls();}});
discard.addEventListener('click',async()=>{if(!active)return;busy=true;controls();try{await api('discard',active.token);remember(null);show('ล้างไฟล์พักแล้ว ข้อมูลที่บันทึกสำเร็จยังคงอยู่');setProgress(0);}catch(error){show(error.message);}finally{busy=false;controls();}});
(async()=>{remember(saved());if(!active)return;try{const job=await api('status',active.token);displayJob(job);if(job.phase==='complete'){addResult(job);remember(null);}else if(!['uploading','failed','discarded'].includes(job.phase)){busy=true;controls();try{const done=await monitor(job.token);addResult(done);remember(null);}finally{busy=false;}}else if(job.phase==='uploading')show(job.name+'\nเลือกไฟล์เดิมแล้วกดเริ่มนำเข้าเพื่ออัปโหลดต่อ');}catch(error){show(error.message);}finally{controls();}})();
