<?php
declare(strict_types=1);
require dirname(__DIR__).'/cms/core.php';
$host=$_SERVER['HTTP_HOST']??'';if(!preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/',$host)&&(empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off')){header('Location: https://'.$host.'/admin/',true,302);exit;}
$nonce=\NL\headers(true);header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Управление сайтом — Неверкинский лимонад</title><link rel="stylesheet" href="/admin/admin.css?v=1"><script src="/admin/admin.js?v=1" defer></script></head><body><div id="app"><p class="loading">Загружаем панель управления…</p></div><div id="notice" role="status" aria-live="polite"></div><dialog id="media-dialog"><div class="dialog-heading"><h2>Медиатека</h2><button type="button" class="icon" id="close-media" aria-label="Закрыть медиатеку">×</button></div><div id="media-controls"></div><div id="media-items" class="media-grid"></div></dialog></body></html>
