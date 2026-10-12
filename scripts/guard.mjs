#!/usr/bin/env node
import fs from 'node:fs';import path from 'node:path';import {spawnSync} from 'node:child_process';
const root=process.cwd(),staged=process.argv.includes('--staged');
function walk(p){return fs.readdirSync(p,{withFileTypes:true}).flatMap(e=>['.git','node_modules','vendor'].includes(e.name)?[]:e.isDirectory()?walk(path.join(p,e.name)):[path.relative(root,path.join(p,e.name))]);}
let files;
if(staged){const r=spawnSync('git',['diff','--cached','--name-only','--diff-filter=ACMR','-z'],{encoding:'utf8'});if(r.status!==0){console.error('Guard --staged requires a git repository');process.exit(2);}files=r.stdout.split('\0').filter(Boolean);}else files=walk(root);
const errors=[];
for(const f of files){
 if(/(^|\/)\.env($|\.)/.test(f)&&!f.endsWith('.example'))errors.push(`${f}: real env file`);
 if(/(^|\/)(wp-admin|wp-includes)\//.test(f)||/wp-content\/plugins\/woocommerce\//.test(f))errors.push(`${f}: protected Core file`);
 if(!/^(tests|src|assets)\//.test(f)||!/(\.(php|[cm]?js|ts|tsx|jsx))$/.test(f))continue;
 let s;if(staged){const r=spawnSync('git',['show',':'+f],{encoding:'utf8'});if(r.status!==0){errors.push(`${f}: cannot read index`);continue;}s=r.stdout;}else s=fs.readFileSync(path.join(root,f),'utf8');
 if(/\b(?:test|it|describe)\s*\.\s*(?:only|skip)\s*\(/.test(s)||/\b(?:markTestSkipped|markTestIncomplete)\s*\(/.test(s))errors.push(`${f}: focused/skipped tests`);
}
for(const e of errors)console.error(e);console.log(JSON.stringify({check:'guard',files:files.length,errors:errors.length}));process.exit(errors.length?1:0);
