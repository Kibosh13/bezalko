<?php
declare(strict_types=1);
require __DIR__.'/cms/core.php';
try{
 $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';
 if($path==='/robots.txt'){header('Content-Type: text/plain; charset=utf-8');$s=\NL\doc('settings')['value'];echo 'User-agent: *'."\n".(empty($s['indexing'])?'Disallow: /':"Allow: /\nDisallow: /admin/\nDisallow: /cms/")."\nSitemap: ".$s['origin']."/sitemap.xml\n";exit;}
 if($path==='/sitemap.xml'){header('Content-Type: application/xml; charset=utf-8');$s=\NL\doc('settings')['value'];echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach(\NL\db()->query("SELECT value,updated_at FROM documents WHERE name LIKE 'page:%'") as $row){$page=json_decode($row['value'],true);if(!$page['published']||$page['seo']['noindex']||!$s['indexing'])continue;echo '<url><loc>'.\NL\e($s['origin'].'/'.($page['slug']?$page['slug'].'/':'')).'</loc><lastmod>'.\NL\e(substr($row['updated_at'],0,10)).'</lastmod></url>';}echo '</urlset>';exit;}
 if($path==='/products.json'){\NL\json(\NL\doc('products')['value']);}
 if(!preg_match('~^/(?:([a-z0-9-]+)/)?$~',$path,$match)){http_response_code(404);\NL\public_page('not-found');}
 \NL\public_page($match[1]??'');
}catch(Throwable $error){error_log('NL site: '.$error->getMessage());http_response_code(503);header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Неверкинский лимонад</title><h1>Сайт готовится к открытию</h1><p>Пожалуйста, зайдите позже.</p></html>';}
