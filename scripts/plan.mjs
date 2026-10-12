#!/usr/bin/env node
import fs from 'node:fs';import path from 'node:path';import crypto from 'node:crypto';import {spawnSync} from 'node:child_process';
import {contentDigest} from './evidence-lib.mjs';
const root=process.cwd(),base=path.join(root,'docs/plans'),[cmd,...args]=process.argv.slice(2);
const die=m=>{console.error(m);process.exit(1);};
if(args.includes('--force'))die('--force is forbidden');
const hash=s=>crypto.createHash('sha256').update(s).digest('hex');
const actor=process.env.USER??'agent';
const commit=()=>{const r=spawnSync('git',['rev-parse','HEAD'],{encoding:'utf8'});return r.status===0?r.stdout.trim():'no-git-repository';};
function dirs(){return fs.readdirSync(base,{withFileTypes:true}).filter(e=>e.isDirectory()&&/^P\d{3}-/.test(e.name)).map(e=>path.join(base,e.name));}
function get(id){if(!/^P\d{3}(?:\.\d+)?$/.test(id??''))die('Invalid plan/task ID');const p=id.split('.')[0],matches=dirs().filter(d=>path.basename(d).startsWith(p+'-'));if(matches.length!==1)die(`Plan ${p} not found or duplicate`);return matches[0];}
function read(d){return JSON.parse(fs.readFileSync(path.join(d,'state.json'),'utf8'));}
function log(d,event){fs.appendFileSync(path.join(d,'log.jsonl'),JSON.stringify({...event,time:new Date().toISOString(),actor,commit:commit()})+'\n');}
function write(d,s){log(d,{action:'state',state_hash:hash(JSON.stringify(s))});fs.writeFileSync(path.join(d,'state.json'),JSON.stringify(s,null,2)+'\n');project(d,s);index();}
function validateModel(model,p){
 if(!Array.isArray(model.tasks)||!model.tasks.length)die('Plan must include at least one task');const ids=new Set();
 for(const t of model.tasks){if(!new RegExp('^'+p+'\\.\\d+$').test(t.id)||ids.has(t.id))die('Invalid/duplicate task ID');ids.add(t.id);if(!t.title||!t.files?.length||!t.read_first?.length||!t.dod?.length)die(`${t.id}: missing title/files/reads/DoD`);for(const f of t.files)if(path.isAbsolute(f)||f.split(/[\\/]/).includes('..'))die('Unsafe file path');for(const a of t.dod)if(!Array.isArray(a)||!a.length||a.some(v=>typeof v!=='string'))die('DoD must be argv arrays');}
 const seen=new Set();for(const t of model.tasks){for(const dep of t.depends_on??[])if(!seen.has(dep))die('Dependencies must be topological in plan');seen.add(t.id);}
}
function parsed(d){const doc=fs.readFileSync(path.join(d,'plan.md'),'utf8'),m=doc.match(/```json\n([\s\S]*?)\n```/);if(!m)die('Missing JSON task block');const model=JSON.parse(m[1]);validateModel(model,path.basename(d).split('-')[0]);return{doc,model};}
function projection(s){return '# '+s.id+' — '+s.title+'\n\nStatus: '+s.status+'\n\n'+s.tasks.map(t=>'- ['+(t.status==='done'?'x':' ')+'] '+t.id+' — '+t.title+' ('+t.status+')').join('\n')+'\n';}
function project(d,s){fs.writeFileSync(path.join(d,'progress.md'),projection(s));}
function indexText(){const ds=dirs().sort();return '# Plan index\n\n'+(ds.length?ds.map(d=>{const s=read(d);return '- '+s.id+' — '+s.title+' ('+s.status+')';}).join('\n')+'\n':'No subsequent plans.\n');}
function index(){fs.writeFileSync(path.join(base,'INDEX.md'),indexText());}
function sealed(d,s){const p=parsed(d);if(hash(p.doc)!==s.approved_hash)die('Approved plan changed; authorized resync/reapproval required');return p;}
function findTask(d,s,id){const t=s.tasks.find(t=>t.id===id);if(!t)die('Unknown task');return t;}
function check(){let n=0;for(const d of dirs()){
 const s=read(d),p=parsed(d);if(hash(p.doc)!==s.content_hash)die(`${s.id}: plan state not synced`);
 if(s.status!=='draft'&&s.approved_hash!==s.content_hash)die(`${s.id}: approved hash mismatch`);
 if(JSON.stringify(s.tasks.map(t=>[t.id,t.title]))!==JSON.stringify(p.model.tasks.map(t=>[t.id,t.title])))die(`${s.id}: task state mismatch`);
 if(fs.readFileSync(path.join(d,'progress.md'),'utf8')!==projection(s))die(`${s.id}: progress manually changed`);
 const lines=fs.readFileSync(path.join(d,'log.jsonl'),'utf8').trim().split('\n').filter(Boolean).map(l=>JSON.parse(l));
 if(!lines.length)die(`${s.id}: missing log`);
 const stateReceipt=[...lines].reverse().find(e=>e.action==='state');if(!stateReceipt||stateReceipt.state_hash!==hash(JSON.stringify(s)))die(`${s.id}: state manually changed or missing log receipt`);
 for(const t of s.tasks){if(t.status==='done'&&!(t.depends_on??[]).every(dep=>s.tasks.find(x=>x.id===dep)?.status==='done'))die(`${t.id}: done before dependency`);if(t.status==='done'&&!lines.some(e=>e.action==='done'&&e.task_id===t.id))die(`${t.id}: missing done receipt`);}
 if(s.status==='done'&&!s.tasks.every(t=>t.status==='done'))die(`${s.id}: closed with unfinished tasks`);n++;
 }if(fs.readFileSync(path.join(base,'INDEX.md'),'utf8')!==indexText())die('INDEX manually changed');console.log(JSON.stringify({check:'plans',plans:n,errors:0}));}
try{
if(cmd==='check'){check();}
else if(cmd==='new'){
 const[slug,title]=args;if(!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug??'')||!title)die('new requires safe slug and title');
 const ids=dirs().map(d=>Number(path.basename(d).slice(1,4))),num=Math.max(0,...ids)+1;if(num>999)die('Plan capacity exceeded');const id='P'+String(num).padStart(3,'0');const d=path.join(base,id+'-'+slug);fs.mkdirSync(d);
 const model={tasks:[{id:id+'.1',title:'Define concrete work before approval',depends_on:[],read_first:['docs/02-domain.md'],files:['docs/NOTES.md'],dod:[['node','scripts/check-docs.mjs','--strict']]}]};
 const doc=`# ${id} — ${title}\n\n## 1. Goal\n\nAuthor must write concrete goal.\n\n## 2. Scope\n\nAuthor must define scope and exclusions.\n\n## 3. Decisions\n\nRecord accepted defaults and impacts.\n\n## 4. Documentation changes\n\nList explicitly authorized doc paths/sections or none.\n\n## 5. Tasks\n\n\`\`\`json\n${JSON.stringify(model,null,2)}\n\`\`\`\n`;
 fs.writeFileSync(path.join(d,'plan.md'),doc);fs.writeFileSync(path.join(d,'report.md'),'# Report\n\n## Summary\n\n');fs.writeFileSync(path.join(d,'log.jsonl'),'');const s={id,title,status:'draft',content_hash:hash(doc),tasks:model.tasks.map(t=>({...t,status:'todo',runs:[]}))};log(d,{action:'new'});write(d,s);console.log(id);
}
else if(cmd==='sync'){
 const d=get(args[0]),s=read(d),p=parsed(d);if(s.tasks.some(t=>['in_progress','done'].includes(t.status)))die('Cannot resync active/completed plan; use separate amendment plan');
 s.content_hash=hash(p.doc);s.approved_hash=null;s.status='draft';s.tasks=p.model.tasks.map(t=>({...t,status:'todo',runs:[]}));log(d,{action:'sync',content_hash:s.content_hash});write(d,s);
}
else if(cmd==='approve'){
 const d=get(args[0]),s=read(d),p=parsed(d);if(hash(p.doc)!==s.content_hash)die('sync required');if(/Author must|Define concrete work before approval/.test(p.doc))die('Template plan must be made concrete before approval');if(s.status!=='draft')die('Only draft plan can be approved');s.approved_hash=s.content_hash;s.status='approved';log(d,{action:'approve',content_hash:s.content_hash});write(d,s);
}
else if(cmd==='status'){const d=get(args[0]);console.log(fs.readFileSync(path.join(d,'progress.md'),'utf8'));}
else if(['start','run','done','block'].includes(cmd)){
 const id=args[0],d=get(id),s=read(d);sealed(d,s);if(!['approved','in_progress'].includes(s.status))die('Plan is not approved/active');const t=findTask(d,s,id);
 if(cmd==='start'){
  if(!['todo','blocked'].includes(t.status))die('Task cannot start from current state');if(s.tasks.some(x=>x.status==='in_progress'&&x.id!==id))die('Finish/block active task before next');if(!(t.depends_on??[]).every(dep=>s.tasks.find(x=>x.id===dep)?.status==='done'))die('Dependencies unfinished');t.status='in_progress';t.runs=[];t.started_at=new Date().toISOString();s.status='in_progress';log(d,{action:'start',task_id:id});write(d,s);
 }else if(cmd==='block'){
  if(t.status!=='in_progress')die('Only running task can block');const pos=args.indexOf('--reason'),reason=args.slice(pos+1).join(' ');if(pos<0||!reason)die('Missing reason');t.status='blocked';log(d,{action:'block',task_id:id,reason});write(d,s);
 }else if(cmd==='run'){
  if(t.status!=='in_progress')die('Start task first');const sep=args.indexOf('--'),argv=args.slice(sep+1);if(sep<0||!argv.length)die('run requires -- command argv');
  if(!t.dod.some(a=>JSON.stringify(a)===JSON.stringify(argv)))die('Command not declared DoD');if(argv.some(v=>/--no-verify|--force/.test(v))||argv[0]==='git'&&argv.includes('push'))die('Forbidden command');
  const c=commit(),digest=contentDigest(root,t.files),r=spawnSync(argv[0],argv.slice(1),{cwd:root,encoding:'utf8',maxBuffer:10*1024*1024}),exit=r.status??127;const output=(r.stdout??'')+(r.stderr??'')+(r.error?.message??'');const outputName='command-'+Date.now()+'-'+crypto.randomBytes(4).toString('hex')+'.log';fs.writeFileSync(path.join(d,outputName),output);const receipt={argv,exit_code:exit,commit:c,content_digest:digest,output_sha256:hash(output),output_path:path.relative(root,path.join(d,outputName)),time:new Date().toISOString()};t.runs.push(receipt);log(d,{action:'run',task_id:id,...receipt});write(d,s);process.stdout.write(output);process.exitCode=exit;
 }else{
  if(t.status!=='in_progress')die('Task not running');for(const a of t.dod){const last=[...t.runs].reverse().find(r=>JSON.stringify(r.argv)===JSON.stringify(a));if(!last||last.exit_code!==0||last.content_digest!==contentDigest(root,t.files))die('Every DoD command must pass on current task/contract contents; rerun after content changes');if(!fs.existsSync(path.join(root,last.output_path))||hash(fs.readFileSync(path.join(root,last.output_path)))!==last.output_sha256)die('Command output receipt missing/changed');}
  t.status='done';log(d,{action:'done',task_id:id});write(d,s);
 }
}
else if(cmd==='report'){
 const d=get(args[0]),s=read(d);sealed(d,s);if(!s.tasks.every(t=>t.status==='done'))die('Tasks unfinished');const r=fs.readFileSync(path.join(d,'report.md'),'utf8');const summary=r.split('## Summary')[1]?.trim();if(!summary)die('Write report Summary first');s.report_hash=hash(r);log(d,{action:'report',report_hash:s.report_hash});write(d,s);
}
else if(cmd==='close'){
 const d=get(args[0]),s=read(d);sealed(d,s);if(!s.tasks.every(t=>t.status==='done')||!s.report_hash||hash(fs.readFileSync(path.join(d,'report.md'),'utf8'))!==s.report_hash)die('Completed tasks/current report required');s.status='done';log(d,{action:'close'});write(d,s);
}
else die('Use new/sync/approve/start/run/done/block/report/close/status/check');
}catch(e){die(e.message);}
