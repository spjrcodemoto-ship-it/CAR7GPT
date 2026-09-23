<?php
ini_set('display_errors','0');
define('CAR7_LIBRARY_ONLY',true);
require __DIR__.'/vehicle.php';
$owner=hash('sha256',session_id());
$csrf=$_SESSION['csrf']??'';
car7_end_buffers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function reply(array $value,int $status=200):never{http_response_code($status);echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function job_write(string $directory,array $job):void {
    $handle=fopen($directory.'/status.json','c+');if(!$handle)throw new RuntimeException('ไม่สามารถบันทึกสถานะงาน');
    try{flock($handle,LOCK_EX);rewind($handle);ftruncate($handle,0);fwrite($handle,json_encode($job,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($handle);}finally{flock($handle,LOCK_UN);fclose($handle);}
}
function job_read(string $directory):array{
    $handle=@fopen($directory.'/status.json','rb');if(!$handle)throw new RuntimeException('ไม่พบงานนำเข้า');
    try{flock($handle,LOCK_SH);$job=json_decode(stream_get_contents($handle),true,512,JSON_THROW_ON_ERROR);}finally{flock($handle,LOCK_UN);fclose($handle);}return$job;
}
function public_job(array $job):array{unset($job['owner']);return$job;}
$lock=null;
try{
    if($_SERVER['REQUEST_METHOD']!=='POST')reply(['error'=>'ใช้คำขอ POST'],405);
    if(!$csrf || !hash_equals($csrf,(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))reply(['error'=>'เซสชันหมดอายุ กรุณารีเฟรชหน้าเว็บ'],403);
    $root=sys_get_temp_dir().'/car7-import-jobs';
    if(!is_dir($root) && !mkdir($root,0700,true))throw new RuntimeException('ไม่สามารถสร้างพื้นที่พักไฟล์');
    $action=$_GET['action']??'';
    if($action==='init'){
        $input=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
        $name=basename(str_replace('\\','/',(string)($input['name']??'')));$size=(int)($input['size']??0);
        $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if(!in_array($extension,['xlsx','csv'],true))throw new RuntimeException('รองรับ .xlsx และ .csv เท่านั้น กรุณาแปลง .xls เป็น .xlsx');
        if($size<=0 || $size>CAR7_UPLOAD_MAX)throw new RuntimeException('รองรับสูงสุด 2 GB ต่อไฟล์');
        if(disk_free_space($root)<$size*3+512*1024*1024)throw new RuntimeException('พื้นที่พักไฟล์ไม่พอ กรุณาเพิ่มพื้นที่ว่างก่อน');
        $token=bin2hex(random_bytes(16));$directory=$root.'/'.$token;
        if(!mkdir($directory,0700))throw new RuntimeException('ไม่สามารถสร้างงานนำเข้า');
        $job=['token'=>$token,'owner'=>$owner,'name'=>$name,'extension'=>$extension,'size'=>$size,'received'=>0,'phase'=>'uploading','committed'=>0,'created'=>time(),'updated'=>time()];
        job_write($directory,$job);reply(public_job($job));
    }
    $token=(string)($_GET['token']??'');
    if(!preg_match('/^[a-f0-9]{32}$/D',$token))throw new RuntimeException('รหัสงานไม่ถูกต้อง');
    $directory=$root.'/'.$token;$job=job_read($directory);
    if(!hash_equals($job['owner'],$owner))reply(['error'=>'ไม่สามารถเข้าถึงงานนี้'],403);
    if($action==='status')reply(public_job($job));
    $lock=fopen($directory.'/job.lock','c+');
    if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))reply(['error'=>'งานนี้กำลังประมวลผล กรุณารอและตรวจสถานะ'],409);
    $job=job_read($directory);
    if($action==='chunk'){
        if($job['phase']!=='uploading')throw new RuntimeException('งานนี้ไม่ได้อยู่ในขั้นตอนอัปโหลด');
        $offset=filter_var($_GET['offset']??null,FILTER_VALIDATE_INT);
        $length=(int)($_SERVER['CONTENT_LENGTH']??0);
        if($offset===false || $offset<0 || $length<=0 || $length>CAR7_UPLOAD_CHUNK || $offset+$length>$job['size'])throw new RuntimeException('ขนาดส่วนอัปโหลดไม่ถูกต้อง');
        if($offset!==$job['received'])reply(['error'=>'ตำแหน่งไฟล์ไม่ตรงกัน','received'=>$job['received']],409);
        $part=$directory.'/chunk.part';$in=fopen('php://input','rb');$out=fopen($part,'wb');
        try{$copied=stream_copy_to_stream($in,$out,CAR7_UPLOAD_CHUNK+1);}finally{fclose($in);fclose($out);}
        if($copied!==$length){unlink($part);throw new RuntimeException('อัปโหลดส่วนนี้ไม่ครบ กรุณาลองใหม่');}
        $dest=fopen($directory.'/payload','c+b');$in=fopen($part,'rb');
        try{ftruncate($dest,$offset);fseek($dest,$offset);$written=stream_copy_to_stream($in,$dest);fflush($dest);}finally{fclose($in);fclose($dest);unlink($part);}
        if($written!==$length)throw new RuntimeException('บันทึกไฟล์ไม่ครบ พื้นที่อาจไม่เพียงพอ');
        $job['received']=$offset+$length;$job['updated']=time();job_write($directory,$job);reply(public_job($job));
    }
    if($action==='discard'){
        if(is_file($directory.'/payload'))unlink($directory.'/payload');
        if(is_file($directory.'/chunk.part'))unlink($directory.'/chunk.part');
        $job['phase']='discarded';$job['updated']=time();job_write($directory,$job);reply(public_job($job));
    }
    if($action!=='run')throw new RuntimeException('คำสั่งไม่ถูกต้อง');
    if($job['phase']==='complete')reply(public_job($job));
    if(!in_array($job['phase'],['uploading','failed'],true) || $job['received']!==$job['size'])throw new RuntimeException('ไฟล์ยังอัปโหลดไม่ครบ หรือกำลังนำเข้า');
    clearstatcache(true,$directory.'/payload');
    if(!is_file($directory.'/payload') || filesize($directory.'/payload')!==$job['size'])throw new RuntimeException('ไฟล์พักไม่ครบ กรุณาอัปโหลดใหม่');
    set_time_limit(0);ignore_user_abort(true);
    $job['phase']='preparing';$job['committed']=0;$job['updated']=time();unset($job['error']);job_write($directory,$job);
    $finished=false;
    register_shutdown_function(function()use(&$finished,&$job,$directory){
        if(!$finished){$error=error_get_last();$job['phase']='failed';$job['error']='งานหยุดก่อนเสร็จ ชุดที่บันทึกสำเร็จแล้วจะคงอยู่ กรุณาลองใหม่'.($error?' (เซิร์ฟเวอร์หยุดประมวลผล)':'');$job['updated']=time();job_write($directory,$job);}
    });
    try{
        $count=car7_fast_import($conn,$directory.'/payload',$job['extension'],function(array$event)use(&$job,$directory){
            $job=array_merge($job,$event,['updated'=>time()]);
            if($job['phase']==='complete')$job['phase']='finishing';
            job_write($directory,$job);
        });
        $job['phase']='complete';$job['committed']=$count;$job['updated']=time();
        if(is_file($directory.'/payload'))unlink($directory.'/payload');
        job_write($directory,$job);$finished=true;reply(public_job($job));
    }catch(Throwable$error){$job['phase']='failed';$job['error']=$error->getMessage();$job['updated']=time();job_write($directory,$job);$finished=true;reply(public_job($job),422);}
}catch(Throwable$error){reply(['error'=>$error->getMessage()],400);}
