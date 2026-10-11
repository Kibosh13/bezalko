import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const dist=path.join(root,'dist');const base=process.env.DEMO_BASE_PATH||'/';
const htmlFiles=fs.readdirSync(dist,{recursive:true}).filter(f=>f.endsWith('.html'));
const errors=[];const resources=new Set();
for(const f of htmlFiles){const html=fs.readFileSync(path.join(dist,f),'utf8');
 if(!/<meta name="robots" content="noindex,nofollow,noarchive">/.test(html))errors.push(`${f}: missing noindex`);
 for(const m of html.matchAll(/\b(?:href|src)="([^"#]+)"/g)){const url=m[1];if(!url.startsWith('/'))continue;
  if(!url.startsWith(base)){errors.push(`${f}: outside project path: ${url}`);continue;}
  let rel=url.slice(base.length).split(/[?#]/)[0];if(!rel||rel.endsWith('/'))rel+='index.html';
  if(!fs.existsSync(path.join(dist,rel)))errors.push(`${f}: missing ${rel}`);else resources.add(rel);
 }
}
if(errors.length)throw new Error(errors.join('\n'));
const phpIndex=fs.readFileSync(path.join(root,'wordpress/neverkinskiy-limonad/index.php'),'utf8');
if(/noindex|nofollow|noarchive/.test(phpIndex))throw new Error('Demo indexing policy must not be in production PHP');
console.log(`Validated ${htmlFiles.length} HTML files and ${resources.size} internal resource targets. Production PHP has no forced noindex.`);
