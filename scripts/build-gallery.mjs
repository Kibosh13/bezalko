import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const theme=path.join(root,'wordpress/neverkinskiy-limonad');
const album=JSON.parse(fs.readFileSync(path.join(theme,'gallery-album.json'),'utf8'));
const photoWord=album.photo_count%100>=11&&album.photo_count%100<=14?'фотографий':album.photo_count%10===1?'фотография':album.photo_count%10>=2&&album.photo_count%10<=4?'фотографии':'фотографий';
const esc=value=>String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
if(album.photos.length!==album.photo_count||new Set(album.photos.map(p=>p.source_name)).size!==album.photo_count)throw new Error('Album inventory mismatch');
for(const photo of album.photos){
 if(!/^album\/img_\d+\.jpg$/.test(photo.photo)||!fs.existsSync(path.join(theme,'assets',photo.photo)))throw new Error('Missing or invalid album asset');
 if(!/^https:\/\/disk\.yandex\.ru\/a\/_SGgJKOynqysQQ(?:\/[a-f0-9]+)?$/.test(photo.source_url))throw new Error('Invalid original-photo link');
 if(!Number.isInteger(photo.width)||!Number.isInteger(photo.height)||photo.width<1||photo.height<1)throw new Error('Invalid photo dimensions');
 if(photo.thumbnail&&(!/^album\/thumb-img_\d+\.jpg$/.test(photo.thumbnail)||!fs.existsSync(path.join(theme,'assets',photo.thumbnail))))throw new Error('Missing gallery thumbnail');
}
const cards=album.photos.map((p,i)=>`<figure><a class="gallery-picture" href="/assets/${esc(p.photo)}" data-gallery-photo data-original="${esc(p.source_url)}" aria-label="Открыть фото ${i+1}: ${esc(p.caption)}"><img src="/assets/${esc(p.thumbnail||p.photo)}" width="${p.thumbnail_width||p.width}" height="${p.thumbnail_height||p.height}" alt="${esc(p.caption)} — фото ${i+1}" loading="lazy" decoding="async"><span class="gallery-zoom" aria-hidden="true">↗</span></a><figcaption>${esc(p.caption)}</figcaption></figure>`).join('\n');
const first=album.photos[0];
const block=`<!-- album:start -->
<section class="wrap gallery-grid album-grid" aria-label="${album.photo_count} ${photoWord} из альбома">${cards}</section>
<dialog id="gallery-dialog" aria-label="Просмотр фотографий альбома"><button class="dialog-close" aria-label="Закрыть">×</button><figure class="gallery-viewer"><img id="gallery-full" src="/assets/${esc(first.photo)}" alt="${esc(first.caption)}"><figcaption><div class="gallery-nav-row"><button type="button" class="gallery-nav" data-gallery-prev aria-label="Предыдущее фото">←</button><div><p id="gallery-caption">${esc(first.caption)}</p><p id="gallery-counter" class="small-note" aria-live="polite">1 / ${album.photo_count}</p></div><button type="button" class="gallery-nav" data-gallery-next aria-label="Следующее фото">→</button></div><a id="gallery-original" class="text-link" href="${esc(first.source_url)}" target="_blank" rel="noopener noreferrer">Фото на Яндекс Диске ↗</a></figcaption></figure></dialog>
<section class="wrap gallery-grid gallery-extra" aria-label="Другие фотографии компании"><figure><img src="/assets/bottles.jpg" width="960" height="1280" alt="Пять бутылок лимонада с разными вкусами" loading="lazy"><figcaption>Знакомые вкусы в стекле</figcaption></figure><figure><img src="/assets/awards.jpg" width="1170" height="814" alt="Благодарности и грамоты компании" loading="lazy"><figcaption>Фотография благодарностей и грамот. Описание и даты: [заполнить].</figcaption></figure></section>
<!-- album:end -->`;
const template=path.join(theme,'templates/gallery.html');
let html=fs.readFileSync(template,'utf8');
if(html.includes('<!-- album:start -->'))html=html.replace(/<!-- album:start -->[\s\S]*?<!-- album:end -->/,block);
else{
 const start=html.indexOf('<section class="wrap gallery-grid">');
 const end=html.indexOf('<section class="wrap gallery-video"');
 if(start<0||end<start)throw new Error('Gallery template markers missing');
 html=html.slice(0,start)+block+html.slice(end);
}
html=html.replace(/<p class="lead">[\s\S]*?<\/p>/,`<p class="lead">${album.photo_count} ${photoWord} продукции из альбома «${esc(album.album_title)}». Нажмите на снимок, чтобы посмотреть его крупно и листать фотографии.</p>`);
html=html.replace('https://disk.yandex.ru/a/_SGgJKOynqysQQ/6abcf0b869f3cfbe19e1ea0f','https://disk.yandex.ru/a/_SGgJKOynqysQQ').replace('Все фото на Яндекс Диске','Альбом на Яндекс Диске');
fs.writeFileSync(template,html);
console.log(`Gallery generated with all ${album.photo_count} album photographs.`);
