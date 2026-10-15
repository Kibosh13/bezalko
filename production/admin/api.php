<?php
declare(strict_types=1);
require dirname(__DIR__).'/cms/core.php';
$host=$_SERVER['HTTP_HOST']??'';if(!preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/',$host)&&(empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off')){http_response_code(400);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false,"error":"Используйте HTTPS для панели управления."}';exit;}
\NL\headers(true);
try{
 \NL\session_start();$action=$_GET['action']??'state';
 if($_SERVER['REQUEST_METHOD']==='GET'){
  if($action==='session'){\NL\json(['ok'=>true,'user'=>$_SESSION['user']??null,'csrf'=>$_SESSION['csrf'],'setup_required'=>(int)\NL\db()->query('SELECT COUNT(*) FROM users')->fetchColumn()===0]);}
  \NL\require_user();
  if($action==='state'){$records=[];foreach(\NL\db()->query('SELECT name,value,revision FROM documents') as $r)$records[$r['name']]=['value'=>json_decode($r['value'],true),'revision'=>(int)$r['revision']];\NL\json(['ok'=>true,'records'=>$records]);}
  if($action==='media'){$q=\NL\db()->prepare('SELECT id,path,name,mime,size,created_at,deleted FROM media WHERE deleted=? ORDER BY id DESC');$q->execute([isset($_GET['trash'])?1:0]);\NL\json(['ok'=>true,'media'=>$q->fetchAll()]);}
  if($action==='history'){$q=\NL\db()->prepare('SELECT id,created_at FROM revisions WHERE name=? ORDER BY id DESC LIMIT 30');$q->execute([$_GET['key']??'']);\NL\json(['ok'=>true,'history'=>$q->fetchAll()]);}
  if($action==='export'){$records=[];foreach(\NL\db()->query('SELECT name,value FROM documents') as $r)$records[$r['name']]=json_decode($r['value'],true);header('Content-Disposition: attachment; filename="neverlimonad-content-'.gmdate('Y-m-d').'.json"');\NL\json(['schema'=>\NL\SCHEMA,'created_at'=>gmdate('c'),'records'=>$records]);}
  \NL\fail('Неизвестный запрос.',404);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')\NL\fail('Метод не поддерживается.',405);
 \NL\csrf();$input=[];if(str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json')){$raw=file_get_contents('php://input',false,null,0,5*1024*1024+1);if(strlen($raw)>5*1024*1024)\NL\fail('Слишком большой запрос.',413);$input=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($input))\NL\fail('Некорректные данные.');}
 if($action==='login'){\NL\login_user((string)($input['username']??''),(string)($input['password']??''));\NL\json(['ok'=>true,'csrf'=>$_SESSION['csrf'],'user'=>$_SESSION['user']]);}
 if($action==='setup'){\NL\setup_user($input);\NL\json(['ok'=>true,'csrf'=>$_SESSION['csrf'],'user'=>$_SESSION['user']]);}
 \NL\require_user();
 if($action==='logout'){$_SESSION=[];session_regenerate_id(true);\NL\json(['ok'=>true]);}
 if($action==='save'){$key=(string)($input['key']??'');\NL\doc($key);$value=\NL\sanitise_document($key,$input['value']??[]);$revision=\NL\save_doc($key,$value,(int)($input['revision']??0));\NL\json(['ok'=>true,'revision'=>$revision,'value'=>$value]);}
 if($action==='upload'){\NL\json(['ok'=>true,'file'=>\NL\upload()]);}
 if($action==='media-trash'||$action==='media-restore'){$q=\NL\db()->prepare('UPDATE media SET deleted=? WHERE id=?');$q->execute([$action==='media-trash'?1:0,(int)($input['id']??0)]);\NL\json(['ok'=>true]);}
 if($action==='restore'){$key=(string)($input['key']??'');$q=\NL\db()->prepare('SELECT value FROM revisions WHERE id=? AND name=?');$q->execute([(int)($input['id']??0),$key]);$value=$q->fetchColumn();if(!$value)\NL\fail('Версия не найдена.',404);$value=json_decode($value,true,512,JSON_THROW_ON_ERROR);$clean=\NL\sanitise_document($key,$value);\NL\json(['ok'=>true,'revision'=>\NL\save_doc($key,$clean,(int)($input['revision']??0)),'value'=>$clean]);}
 \NL\fail('Неизвестное действие.',404);
}catch(InvalidArgumentException|JsonException $e){\NL\fail($e->getMessage(),400);}catch(Throwable $e){error_log('NL admin: '.$e->getMessage());\NL\fail('Не удалось выполнить действие. Повторите попытку.',500);}
