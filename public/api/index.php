<?php
declare(strict_types=1);
require __DIR__.'/db.php';
$cfg=require __DIR__.'/config.php';
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])]);
session_start();
header('Content-Type: application/json');header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');
function out($d,int $c=200){http_response_code($c);echo json_encode($d);exit;}
function fail(string $m,int $c=400){out(['error'=>$m],$c);}
function body():array{static $b;return $b??=(json_decode(file_get_contents('php://input')?:'',true)?:[]);}
function uid():int{if(empty($_SESSION['uid']))fail('Sign in first.',401);return (int)$_SESSION['uid'];}
function charge(string $method,int $amount):string{
  // PRODUCTION: call your payment provider here (for example Paymob or Fawry) with a token from their checkout.
  // Card numbers never reach this server: the app sends only the last 4 digits.
  return 'demo_'.bin2hex(random_bytes(6));
}
function ingestAuth(){global $cfg;if(!hash_equals($cfg['ingest_key'],$_SERVER['HTTP_X_INGEST_KEY']??''))fail('Bad ingest key.',403);}

$r=$_GET['r']??'';$db=db();$now=gmdate('Y-m-d H:i:s');
$machine=in_array($r,['ingest','plate'],true);
if($_SERVER['REQUEST_METHOD']==='POST'&&!$machine&&($_SERVER['HTTP_X_REQUESTED_WITH']??'')!=='fetch')fail('Bad request.',403);

// free bays whose booking has run out
$db->prepare("UPDATE bays SET status='free' WHERE status='reserved' AND EXISTS(SELECT 1 FROM bookings b WHERE b.lot_id=bays.lot_id AND b.bay_num=bays.num AND b.status='active' AND b.expires_at<?)")->execute([$now]);
$db->prepare("UPDATE bookings SET status='done' WHERE status='active' AND expires_at<?")->execute([$now]);

switch($r){
case 'lots':
  out($db->query("SELECT l.id,l.name,l.lat,l.lng,l.rate,COUNT(b.num) total,SUM(b.status='free') free FROM lots l JOIN bays b ON b.lot_id=l.id GROUP BY l.id")->fetchAll());
case 'bays':
  $s=$db->prepare('SELECT num,status FROM bays WHERE lot_id=? ORDER BY num');$s->execute([(int)($_GET['lot']??0)]);out($s->fetchAll());
case 'stats':
  out($db->query('SELECT lot_id,fps,latency_ms,util,created_at FROM gpu_stats WHERE id IN(SELECT MAX(id) FROM gpu_stats GROUP BY lot_id)')->fetchAll());
case 'me':
  if(empty($_SESSION['uid']))out(['user'=>null]);
  $s=$db->prepare('SELECT id,name,email FROM users WHERE id=?');$s->execute([$_SESSION['uid']]);out(['user'=>$s->fetch()?:null]);
case 'register':
  $b=body();$e=strtolower(trim((string)($b['email']??'')));$n=trim((string)($b['name']??''));$p=(string)($b['password']??'');
  if(!filter_var($e,FILTER_VALIDATE_EMAIL))fail('Enter a valid email.');
  if($n===''||mb_strlen($n)>60)fail('Enter your name.');
  if(strlen($p)<8)fail('Password needs at least 8 characters.');
  try{$db->prepare('INSERT INTO users(email,name,pass,created_at)VALUES(?,?,?,?)')->execute([$e,$n,password_hash($p,PASSWORD_DEFAULT),$now]);}
  catch(PDOException $x){fail('That email is already registered.',409);}
  session_regenerate_id(true);$_SESSION['uid']=(int)$db->lastInsertId();out(['user'=>['name'=>$n,'email'=>$e]]);
case 'login':
  $b=body();$s=$db->prepare('SELECT * FROM users WHERE email=?');$s->execute([strtolower(trim((string)($b['email']??'')))]);$u=$s->fetch();
  if(!$u||!password_verify((string)($b['password']??''),$u['pass'])){usleep(300000);fail('Wrong email or password.',401);}
  session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];out(['user'=>['name'=>$u['name'],'email'=>$u['email']]]);
case 'logout':
  $_SESSION=[];session_destroy();out(['ok'=>true]);
case 'bookings':
  $s=$db->prepare('SELECT bk.id,l.name lot,bk.bay_num,bk.plate,bk.hours,bk.amount,bk.method,bk.last4,bk.status,bk.created_at,bk.expires_at FROM bookings bk JOIN lots l ON l.id=bk.lot_id WHERE bk.user_id=? ORDER BY bk.id DESC LIMIT 20');
  $s->execute([uid()]);out($s->fetchAll());
case 'reserve':
  $u=uid();$b=body();$lot=(int)($b['lot_id']??0);$num=(int)($b['bay']??0);$hrs=(int)($b['hours']??0);
  $plate=strtoupper(trim((string)($b['plate']??'')));$m=(string)($b['method']??'');
  if(!preg_match('/^[A-Z0-9 ]{4,10}$/',$plate))fail('Enter a valid plate number.');
  if(!in_array($hrs,[1,2,4,8],true))fail('Choose 1, 2, 4 or 8 hours.');
  $s=$db->prepare('SELECT * FROM lots WHERE id=?');$s->execute([$lot]);$l=$s->fetch();if(!$l)fail('Unknown car park.',404);
  $last4=null;
  if($m==='card'){$last4=(string)($b['last4']??'');if(!preg_match('/^\d{4}$/',$last4))fail('Card details missing.');}
  elseif($m==='instapay'){if(!preg_match('/^[\w.\-]+@[\w.\-]+$|^01\d{9}$/',(string)($b['ipa']??'')))fail('Enter a valid InstaPay address or mobile number.');}
  else fail('Choose a payment method.');
  $amount=(int)$l['rate']*$hrs;
  $db->beginTransaction();
  try{
    $up=$db->prepare("UPDATE bays SET status='reserved',updated_at=? WHERE lot_id=? AND num=? AND status='free'");$up->execute([$now,$lot,$num]);
    if($up->rowCount()!==1){$db->rollBack();fail('That bay was just taken. Pick another.',409);}
    $ref=charge($m,$amount);
    $db->prepare('INSERT INTO bookings(user_id,lot_id,bay_num,plate,hours,amount,method,last4,pay_ref,created_at,expires_at)VALUES(?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$u,$lot,$num,$plate,$hrs,$amount,$m,$last4,$ref,$now,gmdate('Y-m-d H:i:s',time()+$hrs*3600)]);
    $id=(int)$db->lastInsertId();$db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log($e->getMessage());fail('Payment failed. You were not charged.',500);}
  out(['id'=>$id,'amount'=>$amount,'lot'=>$l['name'],'bay'=>$num,'ref'=>$ref],201);
case 'cancel':
  $u=uid();$id=(int)(body()['id']??0);
  $s=$db->prepare("SELECT * FROM bookings WHERE id=? AND user_id=? AND status='active'");$s->execute([$id,$u]);$bk=$s->fetch();
  if(!$bk)fail('Booking not found.',404);
  $db->prepare("UPDATE bookings SET status='cancelled' WHERE id=?")->execute([$id]);
  $db->prepare("UPDATE bays SET status='free',updated_at=? WHERE lot_id=? AND num=? AND status='reserved'")->execute([$now,$bk['lot_id'],$bk['bay_num']]);
  out(['ok'=>true]);
case 'ingest': // called by the GPU worker
  ingestAuth();$b=body();$lot=(int)($b['lot_id']??0);
  $busy=$db->prepare("UPDATE bays SET status='busy',updated_at=? WHERE lot_id=? AND num=? AND status!='busy'");
  $free=$db->prepare("UPDATE bays SET status='free',updated_at=? WHERE lot_id=? AND num=? AND status='busy'");
  $db->beginTransaction();
  foreach(($b['bays']??[]) as $n=>$st){(($st==='busy')?$busy:$free)->execute([$now,$lot,(int)$n]);}
  $db->prepare('INSERT INTO gpu_stats(lot_id,fps,latency_ms,util,created_at)VALUES(?,?,?,?,?)')->execute([$lot,(float)($b['fps']??0),(float)($b['latency_ms']??0),(float)($b['util']??0),$now]);
  $db->commit();out(['ok'=>true]);
case 'plate':
  ingestAuth();$b=body();$p=strtoupper(trim((string)($b['plate']??'')));$k=($b['kind']??'')==='exit'?'exit':'enter';$lot=(int)($b['lot_id']??0);
  $db->prepare('INSERT INTO plate_events(lot_id,plate,kind,created_at)VALUES(?,?,?,?)')->execute([$lot,$p,$k,$now]);
  $s=$db->prepare("UPDATE bookings SET arrived_at=? WHERE lot_id=? AND plate=? AND status='active' AND arrived_at IS NULL");$s->execute([$now,$lot,$p]);
  out(['open_gate'=>$k==='enter'&&$s->rowCount()>0]);
default: fail('Not found.',404);
}
