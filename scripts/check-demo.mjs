import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const dist=path.join(root,'dist');const base=process.env.DEMO_BASE_PATH||'/';
const htmlFiles=fs.readdirSync(dist,{recursive:true}).filter(f=>f.endsWith('.html'));
const errors=[];const resources=new Set();
for(const f of htmlFiles){const html=fs.readFileSync(path.join(dist,f),'utf8');
 if(!/<meta name="robots" content="noindex,nofollow,noarchive">/.test(html))errors.push(`${f}: missing noindex`);
 for(const m of html.matchAll(/\b(?:href|src|poster)="([^"#]+)"/g)){const url=m[1];if(!url.startsWith('/'))continue;
  if(!url.startsWith(base)){errors.push(`${f}: outside project path: ${url}`);continue;}
  let rel=url.slice(base.length).split(/[?#]/)[0];if(!rel||rel.endsWith('/'))rel+='index.html';
  if(!fs.existsSync(path.join(dist,rel)))errors.push(`${f}: missing ${rel}`);else resources.add(rel);
 }
}
if(errors.length)throw new Error(errors.join('\n'));
const album=JSON.parse(fs.readFileSync(path.join(root,'wordpress/neverkinskiy-limonad/gallery-album.json'),'utf8'));
const gallery=fs.readFileSync(path.join(dist,'gallery/index.html'),'utf8');
const photoLinks=[...gallery.matchAll(/<a\b[^>]*\bdata-gallery-photo\b[^>]*>/g)].map(m=>m[0].match(/href="([^"]+)"/)[1]);
if(photoLinks.length!==album.photo_count||new Set(photoLinks).size!==album.photo_count)throw new Error('Published gallery photo count mismatch');
if(album.source_photo_count!==album.photo_count+album.skipped_photos.length)throw new Error('Source album inventory is incomplete');
for(const p of album.photos){
 if(!photoLinks.includes(base+'assets/'+p.photo))throw new Error('Photo absent from gallery: '+p.source_name);
 if(fs.statSync(path.join(dist,'assets',p.photo)).size!==p.bytes)throw new Error('Photo byte size changed during export: '+p.source_name);
}
const phpIndex=fs.readFileSync(path.join(root,'wordpress/neverkinskiy-limonad/index.php'),'utf8');
if(/noindex|nofollow|noarchive/.test(phpIndex))throw new Error('Demo indexing policy must not be in production PHP');
const products=JSON.parse(fs.readFileSync(path.join(dist,'products.json'),'utf8'));
const catalog=fs.readFileSync(path.join(dist,'catalog/index.html'),'utf8');
const catalogIds=[...catalog.matchAll(/<article class="product-card"[^>]* id="([^"]+)"/g)].map(m=>m[1]);
if(catalogIds.length!==products.length||new Set(catalogIds).size!==products.length)throw new Error('Catalogue card count mismatch');
for(const p of products){if(!catalogIds.includes(p.id)||!p.storage||!['sugar','sweetener','kvas'].includes(p.series))throw new Error('Missing catalogue product details: '+p.id);}
const recognition=JSON.parse(fs.readFileSync(path.join(root,'wordpress/neverkinskiy-limonad/recognition.json'),'utf8'));
const about=fs.readFileSync(path.join(dist,'about/index.html'),'utf8');
for(const item of recognition){
 const bytes=fs.readFileSync(path.join(dist,'assets',item.pdf));
 if(createHash('sha256').update(bytes).digest('hex')!==item.pdf_sha256)throw new Error('Original document changed: '+item.pdf);
 if(!about.includes(base+'assets/'+item.pdf)||!about.includes(base+'assets/'+item.preview))throw new Error('Document or preview missing from about page: '+item.pdf);
}
if(!about.includes('data-workshop')||!gallery.includes('data-workshop')||!about.includes('poster="'+base+'assets/workshop-poster.jpg"'))throw new Error('Workshop video or poster is missing from export');
if(about.includes('адрес магазина и часы работы: [заполнить]'))throw new Error('Superseded shop-address placeholder remains');
console.log(`Validated ${htmlFiles.length} HTML files, ${resources.size} internal resource targets, ${products.length} catalogue cards, ${album.photo_count} album photos and ${recognition.length} unchanged PDF originals. Production PHP has no forced noindex.`);
