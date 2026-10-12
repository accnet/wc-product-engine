#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const args=process.argv.slice(2),strict=args.includes('--strict');
const root=path.resolve(args.find(a=>!a.startsWith('--'))??path.join(path.dirname(fileURLToPath(import.meta.url)),'..'));
const errors=[],warnings=[];const fail=m=>errors.push(m);
function walk(dir){return fs.existsSync(dir)?fs.readdirSync(dir,{withFileTypes:true}).flatMap(e=>e.isDirectory()?walk(path.join(dir,e.name)):[path.join(dir,e.name)]):[];}
function json(rel){try{return JSON.parse(fs.readFileSync(path.join(root,rel),'utf8'));}catch(e){fail(`${rel}: ${e.message}`);return null;}}
const docs=walk(path.join(root,'docs')).filter(f=>f.endsWith('.md'));
for(const n of ['AGENTS.md','CLAUDE.md','README.md','.github/pull_request_template.md']){const f=path.join(root,n);if(fs.existsSync(f))docs.push(f);else fail(`Missing ${n}`);}
const text=new Map(docs.map(f=>[path.relative(root,f),fs.readFileSync(f,'utf8')]));
const sectionMap=new Map();
for(const [name,s] of text){
 let fence=null;const sections=new Set();const lines=s.split('\n');
 for(let i=0;i<lines.length;i++){
  const line=lines[i],fm=line.match(/^\s*(`{3,}|~{3,})/);
  if(fm){if(!fence)fence=fm[1][0];else if(fence===fm[1][0])fence=null;continue;}
  if(fence)continue;
  const hm=line.match(/^#{2,6} (\d+(?:\.\d+)*)\.?(?:\s|$)/);
  if(hm){if(sections.has(hm[1]))fail(`${name}:${i+1}: duplicate section ${hm[1]}`);sections.add(hm[1]);}
  if(/^\|/.test(line)){
   const cells=line.replace(/^\|/,'').replace(/\|\s*$/,'').split(/(?<!\\)\|/);
   const prev=lines[i-1]??'';
   if(/^\|/.test(prev)){
    const pc=prev.replace(/^\|/,'').replace(/\|\s*$/,'').split(/(?<!\\)\|/);
    if(cells.length!==pc.length)fail(`${name}:${i+1}: table columns ${cells.length} != ${pc.length}`);
   }else if(!/^\|\s*:?-{3,}/.test(lines[i+1]??''))fail(`${name}:${i+1}: missing table separator`);
  }
 }
 if(fence)fail(`${name}: unmatched code fence`);
 if(/\{\{[^}]+\}\}|<!--\s*(TODO|TEMPLATE|INSTRUCTION)/i.test(s))warnings.push(`${name}: unresolved template/instruction`);
 sectionMap.set(name,sections);
}
const numbered=new Map();for(const name of text.keys()){const m=name.match(/^docs\/(\d\d)-/);if(m)numbered.set(m[1],name);}
for(const [name,s] of text){for(const m of s.matchAll(/\b(0[1-9]|10) §(\d+(?:\.\d+)*)/g)){const target=numbered.get(m[1]);if(!target||!sectionMap.get(target).has(m[2]))fail(`${name}: invalid reference ${m[0]}`);}}
const taskDoc=text.get('docs/06-implementation-plan.md')??'';
const work=json('contracts/tasks.json'),structure=json('contracts/structure.json'),api=json('contracts/api/endpoints.json');
const openapi=json('contracts/openapi/wpe-v1.json'),reg=json('contracts/regression-requirements.json');
for(const f of walk(path.join(root,'contracts')).filter(f=>f.endsWith('.json')))json(path.relative(root,f));
if(work&&structure){
 const tasks=work.tasks??[],ids=new Set(tasks.map(t=>t.id));
 if(ids.size!==tasks.length)fail('Duplicate task IDs');
 const hs=[...taskDoc.matchAll(/^### (T\d+) — (.+)$/gm)].map(m=>[m[1],m[2]]);
 const ps=[...(text.get('docs/PROGRESS.md')??'').matchAll(/^- \[([ x])\] (T\d+) — (.+)$/gm)].map(m=>[m[2],m[3],m[1]]);
 const expected=tasks.map(t=>[t.id,t.title]);
 if(JSON.stringify(hs)!==JSON.stringify(expected))fail('Task document titles/order differ from task registry');
 if(JSON.stringify(ps.map(x=>x.slice(0,2)))!==JSON.stringify(expected))fail('PROGRESS titles/order differ from task registry');
 const positions=new Map(tasks.map((t,i)=>[t.id,i]));
 for(const t of tasks){
  for(const d of t.depends_on){if(!ids.has(d))fail(`${t.id}: unknown dependency ${d}`);else if(positions.get(d)>=positions.get(t.id))fail(`${t.id}: dependency ${d} not earlier in topological order`);}
  for(const f of t.files){if(!structure.paths.includes(f))fail(`${t.id}: Files path not in structure manifest ${f}`);if(!taskDoc.includes('`'+f+'`'))fail(`${t.id}: path not in task document ${f}`);}
  if(!t.acceptance||!t.implementation||!t.regression_requirements?.length)fail(`${t.id}: missing work package fields`);
 }
 const vis=new Set(),seen=new Set();function visit(tid){if(vis.has(tid)){fail(`DAG cycle at ${tid}`);return;}if(seen.has(tid))return;vis.add(tid);for(const d of tasks.find(t=>t.id===tid)?.depends_on??[])if(ids.has(d))visit(d);vis.delete(tid);seen.add(tid);}
 for(const t of tasks)visit(t.id);
 for(const [name,s] of text){if(name.startsWith('docs/plans/P'))continue;for(const m of s.matchAll(/\bT\d\d\b/g))if(!ids.has(m[0]))fail(`${name}: unknown task ${m[0]}`);}
 const allReq=tasks.flatMap(t=>t.regression_requirements.map(r=>({...r,task:t.id,status:'specified_not_implemented'})));
 if(reg&&JSON.stringify(allReq)!==JSON.stringify(reg.requirements))fail('Regression registry differs from tasks');
 const reqIds=allReq.map(r=>r.id);if(new Set(reqIds).size!==reqIds.length)fail('Duplicate regression requirement IDs');
 if(allReq.length!==202)fail(`Expected 202 requirements, got ${allReq.length}`);
 const completed=new Set(ps.filter(x=>x[2]==='x').map(x=>x[0]));
 for(const t of tasks)if(completed.has(t.id))for(const d of t.depends_on)if(!completed.has(d))fail(`${t.id} completed with unfinished dependency ${d}`);
 for(const t of tasks)if(completed.has(t.id)&&!fs.existsSync(path.join(root,'docs/evidence',t.id,'result.json')))fail(`${t.id} done without runtime evidence`);
}
if(api&&openapi){
 const keys=api.endpoints.map(e=>e.method+' '+e.path);if(new Set(keys).size!==keys.length)fail('Duplicate endpoint');
 const apiDoc=text.get('docs/04-api-contract.md')??'';
 for(const e of api.endpoints){if(!apiDoc.includes('`'+e.method+' '+e.path+'`'))fail(`Missing API endpoint document ${e.method} ${e.path}`);if(!openapi.paths?.[e.path]?.[e.method.toLowerCase()])fail(`Missing OpenAPI operation ${e.method} ${e.path}`);}
 for(const [p,ops] of Object.entries(openapi.paths??{}))for(const m of Object.keys(ops).filter(k=>['get','post','patch','delete'].includes(k)))if(!keys.includes(m.toUpperCase()+' '+p))fail(`OpenAPI undeclared endpoint ${m} ${p}`);
 const allowed=new Map(api.errors.map(e=>[e.code,e.http]));
 for(const e of api.errors)if(!apiDoc.includes('`'+e.code+'`'))fail(`Missing error code ${e.code}`);
 for(const [name,s] of text)for(const m of s.matchAll(/\b([45]\d\d) `([a-z][a-z0-9_]+)`/g)){if(allowed.get(m[2])!==Number(m[1]))fail(`${name}: error ${m[2]} not registered HTTP ${m[1]}`);}
 for(const [name,s] of text)for(const m of s.matchAll(/`(GET|POST|PATCH|DELETE) (\/[^`]+)`/g)){if(m[2].startsWith('/v1/')||m[2].startsWith('/admin/')||m[2].startsWith('/catalog')||m[2].startsWith('/products/'))if(!keys.includes(m[1]+' '+m[2]))fail(`${name}: undeclared endpoint ${m[0]}`);}
}
const agent=text.get('AGENTS.md')??'';
for(const m of agent.matchAll(/\b(?:docs|scripts|contracts)\/[A-Za-z0-9_./-]+\.(?:md|mjs|json)/g))if(!fs.existsSync(path.join(root,m[0])))fail(`AGENTS file missing ${m[0]}`);
const known=new Set(['TEXT_JSON','SQL_SELECT','HTML','UTC','ID','VER','TS','AMT','QTY']);
const arch=text.get('docs/01-architecture-and-stack.md')??'';
for(const m of arch.matchAll(/`([A-Z][A-Z0-9]+(?:_[A-Z0-9]+)+)`/g))known.add(m[1]);
for(const [name,s] of text)for(const m of s.matchAll(/\b[A-Z][A-Z0-9]+(?:_[A-Z0-9]+)+\b/g))if(!known.has(m[0]))warnings.push(`${name}: unknown environment/constant ${m[0]}`);
for(const e of errors)console.error('ERROR '+e);for(const w of [...new Set(warnings)])console.error((strict?'ERROR':'WARNING')+' '+w);
console.log(JSON.stringify({check:'docs',strict,files:text.size,tasks:work?.tasks?.length,regression_requirements:reg?.requirements?.length,errors:errors.length,warnings:[...new Set(warnings)].length}));
process.exit(errors.length||(strict&&warnings.length)?1:0);
