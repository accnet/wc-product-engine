#!/usr/bin/env node
import fs from 'node:fs';import path from 'node:path';
import {contentDigest,containedFile,sha256,runtimeEnvironment,commitExists,fileHashes,findAnchor} from './evidence-lib.mjs';
const id=process.argv[2],root=process.cwd(),tasks=JSON.parse(fs.readFileSync('contracts/tasks.json','utf8')).tasks;
const t=tasks.find(t=>t.id===id);if(!t){console.error('Unknown task');process.exit(2);}
const errs=[];let evidence,live;
try{live=runtimeEnvironment();}catch(e){errs.push('Cannot read live runtime environment: '+e.message);}
try{evidence=JSON.parse(fs.readFileSync(`docs/evidence/${id}/result.json`,'utf8'));}catch{errs.push('Missing real runtime evidence');}
function outputOf(e){try{return fs.readFileSync(containedFile(root,e.commands[0].output_path),'utf8');}catch{return null;}}
function check(task,e,requireFresh=true){
 if(e.task_id!==task.id||e.qa_status!=='passed')errs.push(`${task.id}: identity/QA mismatch`);
 if(!e.commit||!e.environment||!['wordpress','woocommerce','php','database'].every(k=>e.environment[k]))errs.push(`${task.id}: missing commit/exact runtime environment`);
 else if(live)for(const k of ['wordpress','woocommerce','php','database'])if(e.environment[k]!==live[k])errs.push(`${task.id}: environment ${k} ${e.environment[k]} != live runtime ${live[k]}`);
 if(!e.base_commit||e.base_commit!==e.commit||!commitExists(root,e.base_commit))errs.push(`${task.id}: base_commit missing/unknown`);
 if(!e.file_hashes||typeof e.file_hashes!=='object')errs.push(`${task.id}: file_hashes missing`);
 else if(requireFresh){const now=fileHashes(root,task.files);if(JSON.stringify(now)!==JSON.stringify(e.file_hashes))errs.push(`${task.id}: file_hashes differ from current task files`);}
 if(requireFresh&&e.content_digest!==contentDigest(root,task.files))errs.push(`${task.id}: evidence is stale for current files/contracts/docs`);
 if(!e.commands?.length)errs.push(`${task.id}: no executed commands`);
 for(const c of e.commands??[]){if(c.exit_code!==0||!c.output_path||!Array.isArray(c.argv)||!c.argv.length||c.content_digest!==e.content_digest)errs.push(`${task.id}: failed/missing/stale command receipt`);else try{const f=containedFile(root,c.output_path);if(sha256(fs.readFileSync(f))!==c.output_sha256)errs.push(`${task.id}: command output changed`);}catch{errs.push(`${task.id}: command output file absent/unsafe`);}}
 if(e.blockers?.length)errs.push(`${task.id}: unresolved blockers`);
 const out=outputOf(e);
 for(const r of task.regression_requirements){const result=e.regression_results?.find(x=>x.id===r.id);if(!result||result.status!=='passed'||!Number.isInteger(result.command_ref)||!e.commands?.[result.command_ref]||!result.test_name||!result.assertion_summary)errs.push(`${task.id}: requirement ${r.id} unverified`);
  else if(result.basis!=='output_anchor'||!result.anchor?.line||out===null||!findAnchor(out,r.id))errs.push(`${task.id}: requirement ${r.id} has no verifiable output anchor`);}
 for(const expected of task.acceptance_checks??[]){const a=e.acceptance?.find(a=>a.id===expected.id);if(!a||a.result!=='passed'||!a.artifact||!a.observation)errs.push(`${task.id}: acceptance ${expected.id} missing`);else{try{containedFile(root,a.artifact);}catch{errs.push(`${task.id}: acceptance artifact missing/unsafe`);}
  if(a.basis==='output_anchor'){if(out===null||!findAnchor(out,expected.id))errs.push(`${task.id}: acceptance ${expected.id} anchor not in output`);}
  else if(a.basis!=='requirements_only')errs.push(`${task.id}: acceptance ${expected.id} has no basis`);}}
 for(const f of task.files)if(!fs.existsSync(path.join(root,f)))errs.push(`${task.id}: implemented path missing ${f}`);
}
if(evidence)check(t,evidence);
for(const dep of t.depends_on){try{const e=JSON.parse(fs.readFileSync(`docs/evidence/${dep}/result.json`,'utf8'));check(tasks.find(t=>t.id===dep),e,false);}catch{errs.push(`${id}: missing dependency evidence ${dep}`);}}
for(const e of errs)console.error(e);console.log(JSON.stringify({task:id,errors:errs.length,scope:'receipt_validation_not_semantic_test_execution'}));process.exit(errs.length?1:0);
