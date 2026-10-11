import fs from 'node:fs';
import {execFileSync} from 'node:child_process';
const files=execFileSync('git',['ls-files','-z'],{encoding:'utf8'}).split('\0').filter(Boolean);
const forbidden=/(^|\/)(?:\.env(?:\..*)?|node_modules|dist|build|tmp|temp|logs|\.openai|\.cache)(?:\/|$)|\.(?:sqlite3?|db|sql|log|pem|key|pfx|p12|zip|tar|gz|tmp|bak)$/i;
const secrets=[/\bgh[pousr]_[A-Za-z0-9]{30,}\b/,/github_pat_[A-Za-z0-9_]{40,}/,/\bAKIA[A-Z0-9]{16}\b/,/\bsk-(?:proj-)?[A-Za-z0-9_-]{30,}\b/,/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/,/https?:\/\/[^\s/]+:[^\s/@]+@/];
const findings=[];
for(const f of files){
 if(forbidden.test(f))findings.push(f+': prohibited file');
 const buf=fs.readFileSync(f);if(buf.subarray(0,16).toString().startsWith('SQLite format 3'))findings.push(f+': database');
 if(!buf.subarray(0,8000).includes(0)){const text=buf.toString();if(secrets.some(re=>re.test(text)))findings.push(f+': potential secret (value redacted)');}
}
if(findings.length)throw new Error(findings.join('\n'));
console.log(`Audited ${files.length} tracked files: no prohibited files or recognized credential patterns.`);
