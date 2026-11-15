<?php
// API de Madrid Underground RP. Usa la base de datos MySQL de Wasmer (variables DB_HOST, DB_PORT, DB_NAME, DB_USERNAME, DB_PASSWORD).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out($d,$c=200){http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function fail($m,$c=400){out(['error'=>$m],$c);}

class D{
 public $my=null,$lt=null;
 function __construct(){
  $sq=getenv('MRP_SQLITE'); // solo para pruebas locales
  if($sq){$this->lt=new SQLite3($sq);return;}
  mysqli_report(MYSQLI_REPORT_OFF);
  $h=getenv('DB_HOST');
  if(!$h)fail('Base de datos no configurada: activa la base de datos MySQL en Wasmer.',500);
  $err='';
  foreach([1,0] as $ssl){
   $c=mysqli_init();
   if($ssl&&defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT'))$c->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT,false);
   if(@$c->real_connect($h,getenv('DB_USERNAME'),getenv('DB_PASSWORD'),getenv('DB_NAME'),(int)getenv('DB_PORT'),null,$ssl?MYSQLI_CLIENT_SSL:0)){$this->my=$c;break;}
   $err=$c->connect_error;
  }
  if(!$this->my)fail('No se pudo conectar a la base de datos: '.$err,500);
  $this->my->set_charset('utf8mb4');
 }
 function v($x){
  if($x===null)return'NULL';
  if(is_int($x))return(string)$x;
  $s=(string)$x;
  return "'".($this->my?$this->my->real_escape_string($s):SQLite3::escapeString($s))."'";
 }
 function f($sql,$a){$p=explode('?',$sql);$s=$p[0];foreach($a as $i=>$x)$s.=$this->v($x).$p[$i+1];return $s;}
 function x($sql,$a=[]){
  $s=$this->f($sql,$a);
  if($this->my){if(!$this->my->query($s))fail('Error de base de datos: '.$this->my->error,500);return;}
  if(!$this->lt->exec($s))fail('Error de base de datos: '.$this->lt->lastErrorMsg(),500);
 }
 function r($sql,$a=[]){
  $s=$this->f($sql,$a);$o=[];
  if($this->my){
   $q=$this->my->query($s);if(!$q)fail('Error de base de datos: '.$this->my->error,500);
   while($w=$q->fetch_assoc())$o[]=$w;$q->free();
  }else{
   $q=$this->lt->query($s);if(!$q)fail('Error de base de datos: '.$this->lt->lastErrorMsg(),500);
   while($w=$q->fetchArray(SQLITE3_ASSOC))$o[]=$w;
  }
  return $o;
 }
 function one($sql,$a=[]){$r=$this->r($sql,$a);return $r?$r[0]:null;}
}

function now(){return (int)round(microtime(true)*1000);}
function newid(){return substr(bin2hex(random_bytes(8)),0,12).dechex(now()%65536);}

$d=new D();
$sfx=$d->my?' DEFAULT CHARSET=utf8mb4':'';
$d->x("CREATE TABLE IF NOT EXISTS users(id VARCHAR(32) NOT NULL PRIMARY KEY, username VARCHAR(30) NOT NULL, ukey VARCHAR(30) NOT NULL UNIQUE, pass VARCHAR(255) NOT NULL, role VARCHAR(10) NOT NULL DEFAULT 'member', mafia_id VARCHAR(40) NOT NULL DEFAULT '', created BIGINT NOT NULL, last_seen BIGINT NOT NULL DEFAULT 0)".$sfx);
$d->x("CREATE TABLE IF NOT EXISTS sessions(token VARCHAR(64) NOT NULL PRIMARY KEY, user_id VARCHAR(32) NOT NULL, created BIGINT NOT NULL)".$sfx);
$d->x("CREATE TABLE IF NOT EXISTS docs(col VARCHAR(20) NOT NULL, id VARCHAR(40) NOT NULL, data LONGTEXT NOT NULL, PRIMARY KEY(col,id))".$sfx);
if(!$d->one("SELECT id FROM docs WHERE col='settings' LIMIT 1")){
 $d->x("REPLACE INTO docs(col,id,data) VALUES('settings','main',?)",[json_encode(['id'=>'main','adUrl'=>'','adTitle'=>'Anuncio del servidor','adText'=>'','fee'=>15,'seeded'=>true])]);
 foreach([['m1','La Manada Negra','#9ca3af',1],['m2','Los Blues','#3b82f6',2],['m3','Los Esmeralds','#10b981',3]] as $m)
  $d->x("REPLACE INTO docs(col,id,data) VALUES('mafias',?,?)",[$m[0],json_encode(['id'=>$m[0],'name'=>$m[1],'color'=>$m[2],'ord'=>$m[3]],JSON_UNESCAPED_UNICODE)]);
}

$in=json_decode(file_get_contents('php://input'),true);
if(!is_array($in))$in=[];
$a=(string)($in['a']??'');

function docs($d,$c){
 $o=[];foreach($d->r('SELECT data FROM docs WHERE col=?',[$c]) as $r){$j=json_decode($r['data'],true);if(is_array($j))$o[]=$j;}
 return $o;
}
function pubUser($r,$adm){
 $u=['id'=>$r['id'],'username'=>$r['username'],'role'=>$r['role'],'mafiaId'=>$r['mafia_id'],'created'=>(int)$r['created']];
 if($adm)$u['lastSeen']=(int)$r['last_seen'];
 return $u;
}
function auth($d,$in){
 $t=$in['token']??'';
 if(!is_string($t)||!preg_match('/^[a-f0-9]{64}$/',$t))fail('No autorizado',401);
 $u=$d->one('SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND s.created>?',[$t,now()-30*86400000]);
 if(!$u)fail('Sesión caducada',401);
 return $u;
}
function session($d,$uid){
 $t=bin2hex(random_bytes(32));
 $d->x('DELETE FROM sessions WHERE created<?',[now()-30*86400000]);
 $d->x('INSERT INTO sessions(token,user_id,created) VALUES(?,?,?)',[$t,$uid,now()]);
 return $t;
}
function checkPass($p){if(strlen($p)<6||strlen($p)>72)fail('La contraseña debe tener entre 6 y 72 caracteres.');}

if($a==='public'){
 $m=docs($d,'mafias');usort($m,fn($x,$y)=>($x['ord']??0)<=>($y['ord']??0));
 $s=docs($d,'settings');
 out(['mafias'=>$m,'settings'=>$s?$s[0]:new stdClass,'hasUsers'=>(bool)$d->one('SELECT id FROM users LIMIT 1')]);
}
if($a==='register'){
 $un=trim((string)($in['username']??''));$pw=(string)($in['password']??'');$mid=(string)($in['mafiaId']??'');
 if(!preg_match('/^[A-Za-z0-9._-]{3,20}$/',$un))fail('Usuario: 3-20 caracteres (letras, números, . _ -).');
 checkPass($pw);
 if(!$d->one("SELECT id FROM docs WHERE col='mafias' AND id=?",[$mid]))fail('Elige una mafia.');
 if($d->one('SELECT id FROM users WHERE ukey=?',[strtolower($un)]))fail('Ese usuario ya existe.');
 $first=!$d->one('SELECT id FROM users LIMIT 1');
 $id=newid();
 $d->x('INSERT INTO users(id,username,ukey,pass,role,mafia_id,created,last_seen) VALUES(?,?,?,?,?,?,?,?)',[$id,$un,strtolower($un),password_hash($pw,PASSWORD_DEFAULT),$first?'admin':'member',$mid,now(),now()]);
 $lid=newid();
 $d->x("REPLACE INTO docs(col,id,data) VALUES('log',?,?)",[$lid,json_encode(['id'=>$lid,'t'=>now(),'mafiaId'=>$mid,'userId'=>$id,'type'=>'ingreso','desc'=>"$un se une a la mafia",'pts'=>0,'roboId'=>''],JSON_UNESCAPED_UNICODE)]);
 out(['token'=>session($d,$id)]);
}
if($a==='login'){
 $un=trim((string)($in['username']??''));$pw=(string)($in['password']??'');
 $u=$d->one('SELECT * FROM users WHERE ukey=?',[strtolower($un)]);
 if(!$u||!password_verify($pw,$u['pass'])){usleep(500000);fail('Usuario o contraseña incorrectos.',401);}
 out(['token'=>session($d,$u['id'])]);
}

$u=auth($d,$in);
$adm=$u['role']==='admin';

switch($a){
 case 'logout':
  $d->x('DELETE FROM sessions WHERE token=?',[$in['token']]);out(['ok'=>true]);
 case 'sync':
  $d->x('UPDATE users SET last_seen=? WHERE id=?',[now(),$u['id']]);
  $u['last_seen']=now();
  $m=docs($d,'mafias');usort($m,fn($x,$y)=>($x['ord']??0)<=>($y['ord']??0));
  $s=docs($d,'settings');
  out(['me'=>pubUser($u,$adm),
   'users'=>array_map(fn($r)=>pubUser($r,$adm),$d->r('SELECT * FROM users ORDER BY created')),
   'mafias'=>$m,'settings'=>$s?$s[0]:new stdClass,
   'robos'=>docs($d,'robos'),'posts'=>docs($d,'posts'),'log'=>docs($d,'log')]);
 case 'passwd':
  $p=(string)($in['password']??'');checkPass($p);
  $d->x('UPDATE users SET pass=? WHERE id=?',[password_hash($p,PASSWORD_DEFAULT),$u['id']]);out(['ok'=>true]);
 case 'save':
  $ops=$in['ops']??[];
  if(!is_array($ops)||count($ops)>300)fail('Demasiados cambios a la vez.');
  $err=0;
  foreach($ops as $o){
   $c=(string)($o['c']??'');$id=$o['id']??'';$data=$o['data']??null;
   if(!in_array($c,['mafias','settings','robos','posts','log'],true)||!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,40}$/',$id)){$err++;continue;}
   $old=$d->one('SELECT data FROM docs WHERE col=? AND id=?',[$c,$id]);$old=$old?json_decode($old['data'],true):null;
   if($data===null){
    $ok=($c==='posts')?($adm||($old&&($old['userId']??'')===$u['id'])):$adm;
    if(!$ok){$err++;continue;}
    $d->x('DELETE FROM docs WHERE col=? AND id=?',[$c,$id]);
    if($c==='mafias')$d->x("UPDATE users SET mafia_id='' WHERE mafia_id=?",[$id]);
    continue;
   }
   if(!is_array($data)){$err++;continue;}
   $data['id']=$id;$ok=false;
   $own=$adm||(($data['mafiaId']??'')===$u['mafia_id']&&($data['userId']??'')===$u['id']);
   if($c==='mafias'||$c==='settings')$ok=$adm;
   elseif($c==='robos'){
    if($old){$ok=$adm||($old['userId']??'')===$u['id'];$data['userId']=$old['userId']??'';if(!$adm)$data['mafiaId']=$old['mafiaId']??'';}
    else $ok=$own;
   }
   elseif($c==='posts')$ok=!$old&&$own;
   elseif($c==='log')$ok=!$old&&($adm||($data['mafiaId']??'')===$u['mafia_id']);
   if(!$ok){$err++;continue;}
   $j=json_encode($data,JSON_UNESCAPED_UNICODE);
   if(strlen($j)>900000){$err++;continue;}
   $d->x('REPLACE INTO docs(col,id,data) VALUES(?,?,?)',[$c,$id,$j]);
  }
  out(['ok'=>true,'errors'=>$err]);
}

if(!$adm)fail('Solo el administrador puede hacer esto.',403);
$tid=(string)($in['id']??'');
$t=$d->one('SELECT * FROM users WHERE id=?',[$tid]);
if(!$t)fail('El usuario no existe.',404);
switch($a){
 case 'user_update':
  if(array_key_exists('mafiaId',$in)){
   $mid=(string)$in['mafiaId'];
   if($mid!==''&&!$d->one("SELECT id FROM docs WHERE col='mafias' AND id=?",[$mid]))fail('Esa mafia no existe.');
   $d->x('UPDATE users SET mafia_id=? WHERE id=?',[$mid,$tid]);
  }
  if(array_key_exists('role',$in)){
   if($tid===$u['id'])fail('No puedes cambiar tu propio rol.');
   if(!in_array($in['role'],['admin','member'],true))fail('Rol no válido.');
   $d->x('UPDATE users SET role=? WHERE id=?',[$in['role'],$tid]);
  }
  out(['ok'=>true]);
 case 'user_delete':
  if($tid===$u['id'])fail('No puedes eliminarte a ti mismo.');
  $d->x('DELETE FROM sessions WHERE user_id=?',[$tid]);$d->x('DELETE FROM users WHERE id=?',[$tid]);out(['ok'=>true]);
 case 'user_setpass':
  $p=(string)($in['password']??'');checkPass($p);
  $d->x('UPDATE users SET pass=? WHERE id=?',[password_hash($p,PASSWORD_DEFAULT),$tid]);
  $d->x('DELETE FROM sessions WHERE user_id=?',[$tid]);out(['ok'=>true]);
}
fail('Acción desconocida.');
