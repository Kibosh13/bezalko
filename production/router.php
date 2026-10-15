<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';
if(preg_match('~^/(assets|uploads)/[a-zA-Z0-9_./-]+$~',$path)&&!str_contains($path,'..')&&is_file(__DIR__.$path))return false;
if($path==='/admin/'||$path==='/admin')require __DIR__.'/admin/index.php';elseif($path==='/admin/api.php')require __DIR__.'/admin/api.php';elseif(in_array($path,['/admin/admin.js','/admin/admin.css'],true))return false;else require __DIR__.'/index.php';
