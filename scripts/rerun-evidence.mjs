#!/usr/bin/env node
// Re-runs real runtime evidence for every task in dependency order, then validates every receipt.
//
//   node scripts/rerun-evidence.mjs               run T01..T49 (test-runtime.mjs) and verify each (verify-task.mjs)
//   node scripts/rerun-evidence.mjs --verify-only verify existing receipts only
//   node scripts/rerun-evidence.mjs --only T05,T06   restrict the set (still in dependency order)
//
// Exit 0 only when every selected task ran (unless --verify-only) and verified. Nothing is skipped or faked: a failing
// task keeps its failure in the summary and the exit code. The summary is written to docs/evidence/rerun-summary.json.
import fs from 'node:fs';import path from 'node:path';import {spawnSync} from 'node:child_process';
import {runtimeEnvironment,baseCommit} from './evidence-lib.mjs';

const root=process.cwd();
const args=process.argv.slice(2);
const verifyOnly=args.includes('--verify-only');
const onlyArg=args.indexOf('--only');
const only=onlyArg>=0?new Set(args[onlyArg+1].split(',')):null;
const tasks=JSON.parse(fs.readFileSync(path.join(root,'contracts/tasks.json'),'utf8')).tasks;

// Kahn topological order, stable with respect to the contract order.
function order(all){
 const done=new Set(),out=[];
 while(out.length<all.length){
  const next=all.find(t=>!done.has(t.id)&&(t.depends_on??[]).every(d=>done.has(d)));
  if(!next)throw new Error('Dependency cycle in contracts/tasks.json');
  done.add(next.id);out.push(next);
 }
 return out;
}
const sequence=order(tasks).filter(t=>!only||only.has(t.id));

function run(argv){
 const t0=Date.now();
 const r=spawnSync(argv[0],argv.slice(1),{cwd:root,encoding:'utf8',maxBuffer:128*1024*1024});
 const out=(r.stdout||'')+(r.stderr||'');
 return {exit:r.status??1,ms:Date.now()-t0,tail:out.trim().split('\n').slice(-6).join('\n')};
}

const results=[];
for(const t of sequence){
 const row={task:t.id,title:t.title};
 if(!verifyOnly){
  const r=run(['node','scripts/test-runtime.mjs',t.id]);
  row.run_exit=r.exit;row.run_seconds=Math.round(r.ms/1000);
  if(r.exit!==0)row.run_tail=r.tail;
 }
 const v=run(['node','scripts/verify-task.mjs',t.id]);
 row.verify_exit=v.exit;
 if(v.exit!==0)row.verify_tail=v.tail;
 row.ok=(verifyOnly||row.run_exit===0)&&row.verify_exit===0;
 results.push(row);
 console.log(`${row.ok?'OK  ':'FAIL'} ${t.id} ${t.title}${row.ok?'':'\n'+(row.run_tail||row.verify_tail||'').split('\n').map(l=>'     '+l).join('\n')}`);
}
// Verification of a task depends on its dependencies' receipts, so a second pass after all runs gives the final answer.
if(!verifyOnly){
 for(const row of results){
  const v=run(['node','scripts/verify-task.mjs',row.task]);
  row.verify_exit=v.exit;row.verify_tail=v.exit?v.tail:undefined;
  row.ok=row.run_exit===0&&v.exit===0;
 }
}
const failed=results.filter(r=>!r.ok);
const summary={
 generated_at:new Date().toISOString(),
 mode:verifyOnly?'verify_only':'run_and_verify',
 base_commit:baseCommit(root),
 environment:runtimeEnvironment(),
 tasks:results.length,
 passed:results.length-failed.length,
 failed:failed.map(f=>f.task),
 results,
};
fs.writeFileSync(path.join(root,verifyOnly?'docs/evidence/verify-summary.json':'docs/evidence/rerun-summary.json'),JSON.stringify(summary,null,2)+'\n');
console.log(JSON.stringify({mode:summary.mode,tasks:summary.tasks,passed:summary.passed,failed:summary.failed}));
process.exit(failed.length?1:0);
