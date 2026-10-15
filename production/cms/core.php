<?php
declare(strict_types=1);
namespace NL;

const SCHEMA = 1;
require __DIR__.'/collections.php';
function root(): string { return dirname(__DIR__); }
function data_dir(): string { return getenv('NL_DATA_DIR') ?: dirname(root()).'/.neverlimonad-data'; }
function e(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function json(mixed $data, int $status=200): never { http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit; }
function fail(string $message, int $status=400): never { json(['ok'=>false,'error'=>$message],$status); }
function db(): \PDO {
 static $pdo;
 if($pdo)return $pdo;
 $dir=data_dir();if(!is_dir($dir))throw new \RuntimeException('Сначала выполните установку сайта.');
 $pdo=new \PDO('sqlite:'.$dir.'/cms.sqlite',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
 $pdo->exec('PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON');return $pdo;
}
function doc(string $key): array { $q=db()->prepare('SELECT value,revision FROM documents WHERE name=?');$q->execute([$key]);$row=$q->fetch();if(!$row)throw new \RuntimeException('Запись не найдена: '.$key);return ['value'=>json_decode($row['value'],true,512,JSON_THROW_ON_ERROR),'revision'=>(int)$row['revision']]; }
function save_doc(string $key,array $value,int $revision): int {
 $pdo=db();$pdo->beginTransaction();
 try{$old=doc($key);if($revision!==$old['revision']){ $pdo->rollBack();fail('Эту запись уже изменили. Обновите страницу, прежде чем сохранять.',409);}
 $encoded=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 $q=$pdo->prepare('INSERT INTO revisions(name,value,created_at) VALUES(?,?,?)');$q->execute([$key,json_encode($old['value'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),gmdate('c')]);
 $q=$pdo->prepare('UPDATE documents SET value=?,revision=revision+1,updated_at=? WHERE name=?');$q->execute([$encoded,gmdate('c'),$key]);
 $q=$pdo->prepare('DELETE FROM revisions WHERE name=? AND id NOT IN(SELECT id FROM revisions WHERE name=? ORDER BY id DESC LIMIT 30)');$q->execute([$key,$key]);$pdo->commit();return $revision+1;
 }catch(\Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();throw $ex;}
}
function headers(bool $admin=false): string {
 $nonce=bin2hex(random_bytes(16));header('X-Content-Type-Options: nosniff');header('Referrer-Policy: strict-origin-when-cross-origin');header('X-Frame-Options: SAMEORIGIN');
 header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' https: data: blob:; media-src 'self' https: blob:; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self' mailto:");
 if($admin){header('X-Robots-Tag: noindex, nofollow, noarchive');header('Cache-Control: no-store');}return $nonce;
}
function session_start(): void {
 if(session_status()===PHP_SESSION_ACTIVE)return;
 $dir=data_dir().'/sessions';if(!is_dir($dir))mkdir($dir,0700,true);
 ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');session_save_path($dir);session_name('NLADMIN');
 session_set_cookie_params(['lifetime'=>0,'path'=>'/admin/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Strict']);\session_start();
 if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
 if(isset($_SESSION['user'])&&(time()-($_SESSION['seen']??0)>1800||time()-($_SESSION['started']??0)>43200)){unset($_SESSION['user']);session_regenerate_id(true);}
 if(isset($_SESSION['user']))$_SESSION['seen']=time();
}
function csrf(): void {
 $token=$_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['csrf']??'';
 if(!is_string($token)||!hash_equals($_SESSION['csrf'],$token))fail('Сессия истекла. Обновите страницу.',403);
 if(isset($_SERVER['HTTP_ORIGIN'])){ $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';if($_SERVER['HTTP_ORIGIN']!==$scheme.'://'.($_SERVER['HTTP_HOST']??''))fail('Недопустимый источник запроса.',403); }
}
function require_user(): void { if(empty($_SESSION['user']))fail('Войдите в панель управления.',401); }
function auth_rate(): void {
 $key=hash('sha256',($_SERVER['REMOTE_ADDR']??'local').'|'.data_dir());$q=db()->prepare('SELECT COUNT(*) FROM attempts WHERE ip=? AND attempted_at>?');$q->execute([$key,time()-900]);if((int)$q->fetchColumn()>=10)fail('Слишком много попыток. Повторите через 15 минут.',429);
 $q=db()->prepare('INSERT INTO attempts(ip,attempted_at) VALUES(?,?)');$q->execute([$key,time()]);db()->exec('DELETE FROM attempts WHERE attempted_at<'.(time()-86400));
}
function login_user(string $username,string $password): void {
 auth_rate();$q=db()->prepare('SELECT id,username,password_hash FROM users WHERE username=?');$q->execute([$username]);$user=$q->fetch();$hash=$user['password_hash']??'$2y$12$7O82wV1O89SA6GUV1CH8J.RdV2x8AjI3YPnNm2pOCk3hgjVSGfDG2';
 if(!$user||!password_verify($password,$hash))fail('Неверный логин или пароль.',401);
 session_regenerate_id(true);$_SESSION['user']=['id'=>(int)$user['id'],'username'=>$user['username']];$_SESSION['started']=$_SESSION['seen']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));
}
function setup_user(array $input): void {
 auth_rate();$pdo=db();$pdo->beginTransaction();
 try{
 if((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()>0)fail('Первый вход уже настроен.',409);
 $setup=json_decode(file_get_contents(data_dir().'/setup.json')?:'{}',true);
 if(time()>($setup['expires']??0)||!hash_equals($setup['hash']??'',hash('sha256',(string)($input['token']??''))))fail('Одноразовая ссылка недействительна.',403);
 $username=trim((string)($input['username']??''));$password=(string)($input['password']??'');if(!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/',$username)||strlen($password)<14||strlen($password)>200)fail('Логин: 3–40 латинских символов. Пароль: не менее 14 символов.');
 $q=$pdo->prepare('INSERT INTO users(username,password_hash,created_at) VALUES(?,?,?)');$q->execute([$username,password_hash($password,PASSWORD_DEFAULT),gmdate('c')]);$pdo->commit();unlink(data_dir().'/setup.json');login_user($username,$password);
 }catch(\Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();throw $ex;}
}
function url(string $value,bool $media=false): string {
 $value=trim($value);if(strlen($value)>2000||preg_match('/[\x00-\x20\x7f]/',$value))throw new \InvalidArgumentException('Некорректная ссылка.');
 if($value==='')return '';
 if($value[0]==='/'&&!str_starts_with($value,'//')&&!str_contains($value,'..'))return $value;
 if(!$media&&($value[0]==='#'||preg_match('/^(mailto:|tel:)/i',$value)))return $value;
 if(filter_var($value,FILTER_VALIDATE_URL)&&in_array(strtolower(parse_url($value,PHP_URL_SCHEME)?:''),['https','http'],true)&&!parse_url($value,PHP_URL_USER)&&!parse_url($value,PHP_URL_PASS))return $value;
 throw new \InvalidArgumentException('Используйте адрес сайта или ссылку https://.');
}
function rich(string $html): string {
 if(strlen($html)>50000)throw new \InvalidArgumentException('Текст слишком длинный.');
 $d=new \DOMDocument('1.0','UTF-8');libxml_use_internal_errors(true);$d->loadHTML('<?xml encoding="UTF-8"><div id="rich">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET);libxml_clear_errors();
 $xp=new \DOMXPath($d);$root=$xp->query('//*[@id="rich"]')->item(0);if(!$root)return '';
 $walk=function(\DOMNode $node)use(&$walk):void{foreach(iterator_to_array($node->childNodes) as $child){if($child instanceof \DOMElement){$tag=strtolower($child->tagName);if(in_array($tag,['script','style','iframe','object','embed','svg','math','form','input','video','audio'],true)){ $node->removeChild($child);continue;}$walk($child);if(!in_array($tag,['br','em','strong','b','i','u','s','span','sup','sub'],true)){while($child->firstChild)$node->insertBefore($child->firstChild,$child);$node->removeChild($child);continue;}foreach(iterator_to_array($child->attributes) as $attribute)$child->removeAttributeNode($attribute);}elseif(!($child instanceof \DOMText))$node->removeChild($child);}};
 $walk($root);$out='';foreach($root->childNodes as $child)$out.=$d->saveHTML($child);return $out;
}
function dom(string $html): array {
 $d=new \DOMDocument('1.0','UTF-8');libxml_use_internal_errors(true);$d->loadHTML('<?xml encoding="UTF-8"><html><body><div id="cms-root">'.$html.'</div></body></html>',LIBXML_NONET);libxml_clear_errors();return [$d,new \DOMXPath($d)];
}
function inner(\DOMNode $node): string { $html='';foreach($node->childNodes as $child)$html.=$node->ownerDocument->saveHTML($child);return $html; }
function replace_inner(\DOMElement $el,string $html): void {[$source,$xp]=dom($html);$node=$xp->query('//*[@id="cms-root"]')->item(0);while($el->firstChild)$el->removeChild($el->firstChild);foreach(iterator_to_array($node->childNodes) as $child)$el->appendChild($el->ownerDocument->importNode($child,true));}
function sanitise_document(string $key,array $value): array {
 if(in_array($key,['gallery','recognition','documents'],true))return sanitise_collection($key,$value);
 if($key==='products'){
 if(count($value)>100)throw new \InvalidArgumentException('Не более 100 карточек.');$out=[];$ids=[];
 foreach($value as $p){$id=(string)($p['id']??'');if(!preg_match('/^[a-z][a-z0-9-]{0,63}$/',$id)||isset($ids[$id]))throw new \InvalidArgumentException('У товаров должны быть разные идентификаторы.');$ids[$id]=true;$item=['id'=>$id];foreach(['name','tag','desc','pack','storage'] as $field)$item[$field]=mb_substr(trim((string)($p[$field]??'')),0,4000);if($item['name']==='')throw new \InvalidArgumentException('Укажите название напитка.');$item['series']=in_array($p['series']??'',['sugar','sweetener','kvas'],true)?$p['series']:'sugar';$item['type']=$item['series']==='kvas'?'kvas':'lemonade';$item['photo']=url((string)($p['photo']??''),true);$item['featured']=!empty($p['featured']);
 if(isset($p['frame'])&&is_array($p['frame'])){$frame=$p['frame'];$box=$frame['viewBox']??[];if(count($box)===4&&count(array_filter($box,'is_numeric'))===4&&min($box)>=0&&($box[2]??0)>0&&($box[3]??0)>0)$item['frame']=['source'=>url((string)($frame['source']??''),true),'sourceWidth'=>(int)($frame['sourceWidth']??0),'sourceHeight'=>(int)($frame['sourceHeight']??0),'viewBox'=>array_map('floatval',$box)];}$out[]=$item;}return $out;
 }
 if($key==='settings'){
 $out=[];foreach(['phone','mobile','email','address','shop','cities','director_name','membership_caption','company_name'] as $field)$out[$field]=mb_substr(trim((string)($value[$field]??'')),0,4000);
 if(!filter_var($out['email'],FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Проверьте электронную почту.');
 foreach(['logo','hero_photo','story_photo','director_photo','workshop_video','workshop_photo','og_image'] as $field)$out[$field]=url((string)($value[$field]??''),true);
 $origin=rtrim(url((string)($value['origin']??'')), '/');if(!preg_match('~^https?://[^/]+$~',$origin))throw new \InvalidArgumentException('Укажите основной адрес сайта без пути.');$out['origin']=$origin;$out['indexing']=!empty($value['indexing']);return $out;
 }
 $old=doc($key)['value'];$out=$old;
 foreach($out['fields'] as $id=>&$field)if(array_key_exists($id,$value['fields']??[]))$field['html']=rich((string)($value['fields'][$id]['html']??''));unset($field);
 foreach($out['media'] as $id=>&$media)if(isset($value['media'][$id])){$media['src']=url((string)$value['media'][$id]['src'],true);$media['alt']=mb_substr((string)($value['media'][$id]['alt']??''),0,1000);}unset($media);
 foreach($out['links'] as $id=>&$link)if(isset($value['links'][$id]))$link['href']=url((string)$value['links'][$id]['href']);unset($link);
 $out['sections']=$value['sections']??$old['sections'];$valid=array_column($old['sections'],'id');if(count($out['sections'])!==count($valid)||array_diff(array_column($out['sections'],'id'),$valid)||count(array_unique(array_column($out['sections'],'id')))!==count($valid))throw new \InvalidArgumentException('Состав блоков не совпадает.');foreach($out['sections'] as &$section){$section=['id'=>$section['id'],'label'=>$old['sections'][array_search($section['id'],$valid,true)]['label'],'visible'=>!empty($section['visible'])];}unset($section);
 if(isset($out['seo'])){foreach(['title','description','og_title','og_description'] as $field)$out['seo'][$field]=mb_substr(trim((string)($value['seo'][$field]??'')),0,2000);$out['seo']['og_image']=url((string)($value['seo']['og_image']??''),true);$out['seo']['noindex']=!empty($value['seo']['noindex']);$out['published']=!empty($value['published']);}
 return $out;
}
function product_card(array $p): string {
 $src=$p['photo'];$f=$p['frame']??null;
 if($f&&$src===$f['source']){$box=implode(' ',array_map('floatval',$f['viewBox']));$media='<svg class="product-visual" viewBox="'.e($box).'" role="img" aria-label="'.e($p['name']).'"><defs><clipPath id="frame-'.e($p['id']).'-card"><rect x="'.$f['viewBox'][0].'" y="'.$f['viewBox'][1].'" width="'.$f['viewBox'][2].'" height="'.$f['viewBox'][3].'"/></clipPath></defs><image clip-path="url(#frame-'.e($p['id']).'-card)" href="'.e($src).'" width="'.(int)$f['sourceWidth'].'" height="'.(int)$f['sourceHeight'].'"/></svg>';}
 else $media='<img class="product-visual" src="'.e($src).'" alt="'.e($p['name']).'" loading="lazy">';
 return '<article class="product-card" data-category="'.e($p['series']).'" id="'.e($p['id']).'"><button class="product-picture" data-product="'.e($p['id']).'" aria-label="Подробнее: '.e($p['name']).'"><span class="product-media">'.$media.'</span><span class="round-arrow" aria-hidden="true">↗</span></button><div class="product-caption"><h3>'.e($p['name']).'</h3><p>'.e($p['tag']).'</p><p class="product-storage">Срок хранения: '.e($p['storage']).'</p></div></article>';
}
function render_document(array $page,array $settings,array $products): string {
 [$d,$xp]=dom($page['html']);
 foreach($page['fields'] as $id=>$field){$el=$xp->query('//*[@data-cms-field="'.$id.'"]')->item(0);if($el instanceof \DOMElement)replace_inner($el,$field['html']);}
 foreach($page['media'] as $id=>$media){$el=$xp->query('//*[@data-cms-media="'.$id.'"]')->item(0);if(!$el instanceof \DOMElement)continue;
 if($media['kind']==='svg'&&$media['src']!==$media['original']){$image=$d->createElement('img');$image->setAttribute('src',$media['src']);$image->setAttribute('alt',$media['alt']);$image->setAttribute('class',$el->getAttribute('class'));$image->setAttribute('loading','lazy');$el->parentNode->replaceChild($image,$el);}
 elseif($media['kind']==='svg')$el->setAttribute('aria-label',$media['alt']);
 else{$el->setAttribute($media['attribute'],$media['src']);if($el->tagName==='img')$el->setAttribute('alt',$media['alt']);}
 }
 foreach($page['links'] as $id=>$link){$el=$xp->query('//*[@data-cms-link="'.$id.'"]')->item(0);if($el instanceof \DOMElement)$el->setAttribute('href',$link['href']);}
 foreach(['phone','mobile','email','address','shop','cities','director_name','membership_caption'] as $key){foreach($xp->query('//*[@data-content="'.$key.'"]') as $el){$el->nodeValue=$settings[$key]??'';}}
 foreach($xp->query('//*[@data-contact]') as $el){$key=$el->getAttribute('data-contact');if(!isset($settings[$key]))continue;$el->setAttribute('href',$key==='email'?'mailto:'.$settings[$key]:'tel:'.preg_replace('/[^+0-9]/','',$settings[$key]));$el->nodeValue=$settings[$key];}
 $photos=['logo'=>'//*[contains(concat(" ",normalize-space(@class)," ")," brand ")]/img','hero_photo'=>'//*[contains(concat(" ",normalize-space(@class)," ")," hero-visual ")]/img','story_photo'=>'//*[contains(concat(" ",normalize-space(@class)," ")," story-photo ")]/img','director_photo'=>'//*[contains(concat(" ",normalize-space(@class)," ")," director-section ")]//img'];
 foreach($photos as $key=>$query)if(!empty($settings[$key]))foreach($xp->query($query) as $img)$img->setAttribute('src',$settings[$key]);
 foreach($xp->query('//video[@data-workshop]') as $video){if($settings['workshop_photo']??'')$video->setAttribute('poster',$settings['workshop_photo']);if($settings['workshop_video']??'')foreach($video->getElementsByTagName('source') as $source)$source->setAttribute('src',$settings['workshop_video']);}
 if($settings['workshop_video']??'')foreach($xp->query('//*[@data-workshop-file]') as $link)$link->setAttribute('href',$settings['workshop_video']);
 foreach(['catalog-products','home-products'] as $class){foreach($xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]') as $grid){$items=$class==='home-products'?array_values(array_filter($products,fn($p)=>!empty($p['featured']))):$products;$html='';foreach($items as $p)$html.=product_card($p);replace_inner($grid,$html);}}
 $root=$xp->query('//*[@id="cms-root"]')->item(0);$sectionNodes=[];foreach($page['sections'] as $section){$node=$xp->query('//*[@data-cms-section="'.$section['id'].'"]')->item(0);if($node)$sectionNodes[$section['id']]=$node;}
 if($sectionNodes){$first=reset($sectionNodes);$marker=$d->createComment('sections');$root->insertBefore($marker,$first);foreach($sectionNodes as $node)$root->removeChild($node);foreach($page['sections'] as $section)if($section['visible']&&isset($sectionNodes[$section['id']]))$root->insertBefore($sectionNodes[$section['id']],$marker);$root->removeChild($marker);}
 render_collections($d,$xp);
 return inner($root);
}
function public_page(string $slug): never {
 $key=$slug===''?'home':$slug;try{$record=doc('page:'.$key);$page=$record['value'];}catch(\Throwable){http_response_code(404);$page=null;}
 $settings=doc('settings')['value'];$products=doc('products')['value'];$nonce=headers();header('Content-Type: text/html; charset=utf-8');
 if(!$page||empty($page['published'])){http_response_code(404);$body='<section class="wrap page-intro"><h1>Страница не найдена.</h1><a class="button" href="/">На главную</a></section>';$seo=['title'=>'Страница не найдена','description'=>'','noindex'=>true];}else{$body=render_document($page,$settings,$products);$seo=$page['seo'];}
 $origin=$settings['origin'];$path=$key==='home'?'/':'/'.$key.'/';$canonical=$origin.$path;$noindex=empty($settings['indexing'])||!empty($seo['noindex'])||!$page||empty($page['published']);
 if($noindex)header('X-Robots-Tag: noindex, nofollow');$image=($seo['og_image']??'')?:$settings['og_image'];if($image&&str_starts_with($image,'/'))$image=$origin.$image;
 $config=['assetBase'=>'/assets/','products'=>$products,'contactsUrl'=>'/contacts/','formEnabled'=>false,'content'=>['phone'=>$settings['phone'],'mobile'=>$settings['mobile'],'email'=>$settings['email']]];
 echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#234637"><title>'.e($seo['title']).'</title><meta name="description" content="'.e($seo['description']).'"><meta name="robots" content="'.($noindex?'noindex,nofollow':'index,follow,max-image-preview:large').'"><link rel="canonical" href="'.e($canonical).'"><meta property="og:type" content="website"><meta property="og:locale" content="ru_RU"><meta property="og:site_name" content="'.e($settings['company_name']).'"><meta property="og:title" content="'.e($seo['og_title']?:$seo['title']).'"><meta property="og:description" content="'.e($seo['og_description']?:$seo['description']).'"><meta property="og:url" content="'.e($canonical).'">'.($image?'<meta property="og:image" content="'.e($image).'">':'').'<link rel="icon" href="'.e($settings['logo']).'"><link rel="stylesheet" href="/assets/site.css?v=2"><script nonce="'.e($nonce).'">window.NL_CONFIG='.json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script><script src="/assets/site.js?v=2" defer></script></head><body>'.render_document(doc('shared:header')['value'],$settings,$products).'<main id="main">'.$body.'</main>'.render_document(doc('shared:footer')['value'],$settings,$products).'</body></html>';exit;
}
function upload(): array {
 if(!isset($_FILES['file'])||$_FILES['file']['error']!==UPLOAD_ERR_OK)throw new \InvalidArgumentException('Не удалось загрузить файл. Проверьте размер и повторите.');$f=$_FILES['file'];if($f['size']>100*1024*1024)throw new \InvalidArgumentException('Максимальный размер файла — 100 МБ.');
 $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$map=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/avif'=>'avif','application/pdf'=>'pdf','video/mp4'=>'mp4'];if(!isset($map[$mime]))throw new \InvalidArgumentException('Допустимы JPG, PNG, WebP, GIF, AVIF, PDF и MP4.');
 if(str_starts_with($mime,'image/')){$size=getimagesize($f['tmp_name']);if(!$size||$size[0]*$size[1]>40000000||$f['size']>20*1024*1024)throw new \InvalidArgumentException('Изображение слишком большое или повреждено.');}
 if($mime==='application/pdf'&&file_get_contents($f['tmp_name'],false,null,0,5)!=='%PDF-')throw new \InvalidArgumentException('Повреждённый PDF.');
 $dir=root().'/uploads';if(!is_dir($dir))mkdir($dir,0755,true);$name=bin2hex(random_bytes(16)).'.'.$map[$mime];if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$name))throw new \RuntimeException('Не удалось сохранить файл.');chmod($dir.'/'.$name,0644);
 $path='/uploads/'.$name;$q=db()->prepare('INSERT INTO media(path,name,mime,size,created_at) VALUES(?,?,?,?,?)');$q->execute([$path,mb_substr(basename($f['name']),0,200),$mime,$f['size'],gmdate('c')]);return ['path'=>$path,'name'=>basename($f['name']),'mime'=>$mime];
}
