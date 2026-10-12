#!/usr/bin/env node
import fs from 'node:fs';import path from 'node:path';
import {contentDigest,containedFile,sha256} from './evidence-lib.mjs';
const id=process.argv[2],root=process.cwd(),tasks=JSON.parse(fs.readFileSync('contracts/tasks.json','utf8')).tasks;
const t=tasks.find(t=>t.id===id);if(!t){console.error('Unknown task');process.exit(2);}
const errs=[];let evidence;
try{evidence=JSON.parse(fs.readFileSync(`docs/evidence/${id}/result.json`,'utf8'));}catch{errs.push('Missing real runtime evidence');}
function check(task,e,requireFresh=true){
 if(e.task_id!==task.id||e.qa_status!=='passed')errs.push(`${task.id}: identity/QA mismatch`);
 if(!e.commit||!e.environment||!['wordpress','woocommerce','php','database'].every(k=>e.environment[k]))errs.push(`${task.id}: missing commit/exact runtime environment`);
 if(requireFresh&&e.content_digest!==contentDigest(root,task.files))errs.push(`${task.id}: evidence is stale for current files/contracts/docs`);
 if(!e.commands?.length)errs.push(`${task.id}: no executed commands`);
 for(const c of e.commands??[]){if(c.exit_code!==0||!c.output_path||!Array.isArray(c.argv)||!c.argv.length||c.content_digest!==e.content_digest)errs.push(`${task.id}: failed/missing/stale command receipt`);else try{const f=containedFile(root,c.output_path);if(sha256(fs.readFileSync(f))!==c.output_sha256)errs.push(`${task.id}: command output changed`);}catch{errs.push(`${task.id}: command output file absent/unsafe`);}}
 if(e.blockers?.length)errs.push(`${task.id}: unresolved blockers`);
 for(const r of task.regression_requirements){const result=e.regression_results?.find(x=>x.id===r.id);if(!result||result.status!=='passed'||!Number.isInteger(result.command_ref)||!e.commands?.[result.command_ref]||!result.test_name||!result.assertion_summary)errs.push(`${task.id}: requirement ${r.id} unverified`);}
 for(const expected of task.acceptance_checks??[]){const a=e.acceptance?.find(a=>a.id===expected.id);if(!a||a.result!=='passed'||!a.artifact||!a.observation)errs.push(`${task.id}: acceptance ${expected.id} missing`);else try{containedFile(root,a.artifact);}catch{errs.push(`${task.id}: acceptance artifact missing/unsafe`);}}
 for(const f of task.files)if(!fs.existsSync(path.join(root,f)))errs.push(`${task.id}: implemented path missing ${f}`);
}
if(evidence)check(t,evidence);
for(const dep of t.depends_on){try{const e=JSON.parse(fs.readFileSync(`docs/evidence/${dep}/result.json`,'utf8'));check(tasks.find(t=>t.id===dep),e,false);}catch{errs.push(`${id}: missing dependency evidence ${dep}`);}}
for(const e of errs)console.error(e);console.log(JSON.stringify({task:id,errors:errs.length,scope:'receipt_validation_not_semantic_test_execution'}));process.exit(errs.length?1:0);
