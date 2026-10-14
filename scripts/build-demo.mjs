import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import './build-gallery.mjs';
import './build-catalog.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const theme=path.join(root,'wordpress/neverkinskiy-limonad');
const out=path.join(root,'dist');
let base=process.env.DEMO_BASE_PATH||'/';
if(!/^\/(?:[a-zA-Z0-9_-]+\/)*$/.test(base))throw new Error('DEMO_BASE_PATH must be a slash-delimited URL path');
const pages={home:['','Главная'],catalog:['catalog','Напитки'],about:['about','О компании'],gallery:['gallery','Галерея'],documents:['documents','Документы'],reviews:['reviews','Отзывы'],contacts:['contacts','Где купить'],privacy:['privacy','Обработка персональных данных']};
const noindex='<meta name="robots" content="noindex,nofollow,noarchive">';
const rewrite=(html)=>html.replace(/\b(href|src)="\/(?!\/)([^"]*)"/g,(_,attr,url)=>`${attr}="${base}${url}"`);
const read=(name)=>fs.readFileSync(path.join(theme,'templates',name+'.html'),'utf8');
const config={assetBase:base+'assets/',productsUrl:base+'products.json',contactsUrl:base+'contacts/',formEnabled:false};
const shell=(label,body)=>`<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${label} — Неверкинский лимонад</title><meta name="description" content="Демонстрация сайта Неверкинский лимонад. Каталог, документы и контакты производителя."><meta name="theme-color" content="#234637">${noindex}<link rel="icon" href="${base}assets/logo.svg"><link rel="stylesheet" href="${base}style.css"><script>window.NL_CONFIG=${JSON.stringify(config)};</script><script src="${base}app.js" defer></script></head><body>${body}</body></html>`;
fs.rmSync(out,{recursive:true,force:true});fs.mkdirSync(out,{recursive:true});
fs.cpSync(path.join(theme,'assets'),path.join(out,'assets'),{recursive:true});
for(const [src,dest] of [['design.css','style.css'],['app.js','app.js'],['products.json','products.json']])fs.copyFileSync(path.join(theme,src),path.join(out,dest));
for(const [name,[slug,label]] of Object.entries(pages)){
 const dir=path.join(out,slug);fs.mkdirSync(dir,{recursive:true});
 const body=rewrite(read('header')+`<main id="main">${read(name)}</main>`+read('footer'));
 fs.writeFileSync(path.join(dir,'index.html'),shell(label,body));
}
fs.writeFileSync(path.join(out,'404.html'),shell('Страница не найдена',`<main class="wrap page-intro"><p class="eyebrow">404</p><h1>Такой страницы<br><em>пока нет.</em></h1><a class="button" href="${base}">На главную ↗</a></main>`));
fs.writeFileSync(path.join(out,'robots.txt'),'# Primary noindex control is the robots meta tag on every HTML page.\n# On project GitHub Pages, this file is not the domain-root robots.txt.\nUser-agent: *\nDisallow: '+base+'\n');
fs.writeFileSync(path.join(out,'.nojekyll'),'');
console.log(`Built ${Object.keys(pages).length} pages for ${base} with noindex`);
