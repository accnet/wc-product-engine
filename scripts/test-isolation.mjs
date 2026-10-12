#!/usr/bin/env node
// Test isolation and residue gate (audit F09, OD-05, R212).
//
//   node scripts/test-isolation.mjs residue [--json]              junk signatures in the DB; exit 1 when any
//   node scripts/test-isolation.mjs counts                         row counts used for before/after comparison
//   node scripts/test-isolation.mjs snapshot <file>                wp db export
//   node scripts/test-isolation.mjs restore <file>                 wp db import
//   node scripts/test-isolation.mjs cleanup-residue --backup <file> [--apply]
//                                                                  dry run by default; --apply requires a non-empty backup
//
// The runner (scripts/test-runtime.mjs) calls beginIsolation()/finishIsolation(): every task test runs between a DB snapshot
// and a restore, and the receipt records the raw before/after counts and the junk signatures.
import fs from 'node:fs';import os from 'node:os';import path from 'node:path';import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const WP_PATH=process.env.WPE_WP_PATH||'/var/www/site1.localhost';
const wp=(args,opts={})=>spawnSync('wp',[`--path=${WP_PATH}`,'--allow-root',...args],{encoding:'utf8',maxBuffer:64*1024*1024,...opts});
function must(r,what){if(r.status!==0)throw new Error(`${what} failed: ${(r.stderr||r.stdout||'').trim().slice(0,400)}`);return r;}
function query(sql){return must(wp(['db','query',sql,'--skip-column-names']),'db query').stdout.split('\n').filter(l=>l.length).map(l=>l.split('\t'));}
let prefixCache;
export function dbPrefix(){if(prefixCache)return prefixCache;const r=must(wp(['db','prefix']),'db prefix');return prefixCache=r.stdout.trim();}

// Junk signature: data that only tests ever create on this site (AUTO-DRAFT / untitled native shells, finished cleanup jobs,
// per-test control keys). A real catalog never contains them, so a non-zero count means a test leaked.
export function junkQueries(){
 const P=dbPrefix();
 return {
  junk_native_products:`SELECT id FROM ${P}wpe_products WHERE title='' OR title LIKE 'AUTO-DRAFT%'`,
  orphan_auto_draft_posts:`SELECT ID FROM ${P}posts WHERE post_type IN ('product','product_variation') AND post_status='auto-draft' AND ID NOT IN (SELECT id FROM ${P}wpe_products)`,
  completed_cleanup_jobs:`SELECT id FROM ${P}wpe_jobs WHERE kind='cleanup' AND status='completed'`,
  test_control_keys:`SELECT \`key\` FROM ${P}wpe_control WHERE \`key\` LIKE 'mirror\\_stopped\\_cohort\\_r%' OR \`key\` LIKE 'idempotency:test-%'`,
 };
}
export function residueReport(){
 const out={};for(const [k,sql] of Object.entries(junkQueries()))out[k]=query(sql).map(r=>r[0]);
 return out;
}
export function residueTotal(rep){return Object.values(rep).reduce((n,a)=>n+a.length,0);}
export function residueSummary(rep){return Object.fromEntries(Object.entries(rep).map(([k,a])=>[k,a.length]));}

export function rowCounts(){
 const P=dbPrefix();
 const tables=query(`SHOW TABLES LIKE '${P.replace(/_/g,'\\_')}wpe\\_%'`).map(r=>r[0]).sort();
 const all=[...tables,`${P}posts`,`${P}postmeta`];
 const sql=all.map(t=>`SELECT '${t}',COUNT(*) FROM \`${t}\``).join(' UNION ALL ');
 return Object.fromEntries(query(sql).map(([t,n])=>[t,Number(n)]));
}
export function diffCounts(a,b){const d={};for(const k of new Set([...Object.keys(a),...Object.keys(b)])){const x=(b[k]??0)-(a[k]??0);if(x!==0)d[k]=x;}return d;}
export function snapshot(file){must(wp(['db','export',file,'--add-drop-table']),'db export');if(!fs.existsSync(file)||fs.statSync(file).size===0)throw new Error('Empty snapshot');return file;}
export function restore(file){must(wp(['db','import',file]),'db import');}

// ---- runner API ----
export function beginIsolation(taskId){
 const before=residueReport();
 if(residueTotal(before)>0)throw new Error(`Residue gate: ${residueTotal(before)} junk rows already present ${JSON.stringify(residueSummary(before))}. Run: node scripts/test-isolation.mjs cleanup-residue --backup <file> --apply`);
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'wpe-isolation-'));
 const file=path.join(dir,`${taskId}.sql`);snapshot(file);
 return {taskId,dir,file,counts_before:rowCounts(),residue_before:residueSummary(before),restored:false};
}
export function finishIsolation(state){
 if(state.restored)return state.record;
 const counts_after_test=rowCounts(),junk_after_test=residueSummary(residueReport());
 restore(state.file);
 const counts_after_restore=rowCounts(),junk_after_restore=residueSummary(residueReport());
 try{fs.rmSync(state.dir,{recursive:true,force:true});}catch{}
 state.restored=true;
 const leaked=diffCounts(state.counts_before,counts_after_test);
 const residue=diffCounts(state.counts_before,counts_after_restore);
 state.record={
  mechanism:'wp db export before the test, wp db import after the test',
  restored:true,
  counts_before:state.counts_before,
  counts_after_test,
  counts_after_restore,
  leaked_by_test:leaked,
  residue_after_restore:residue,
  junk_before:state.residue_before,
  junk_after_test,
  junk_after_restore,
  junk_total_before:Object.values(state.residue_before).reduce((a,b)=>a+b,0),
  junk_total_after_restore:Object.values(junk_after_restore).reduce((a,b)=>a+b,0),
 };
 return state.record;
}
export function isolationProblems(rec){
 const p=[];
 if(!rec||rec.restored!==true)return ['isolation: database was not restored'];
 if(Object.keys(rec.residue_after_restore??{}).length)p.push(`isolation: residue after restore ${JSON.stringify(rec.residue_after_restore)}`);
 if(rec.junk_total_before!==0)p.push('isolation: junk existed before the test');
 if(rec.junk_total_after_restore!==0)p.push('isolation: junk remains after restore');
 return p;
}

// ---- one-time cleanup ----
function inList(ids){return ids.map(i=>`'${String(i).replace(/'/g,"''")}'`).join(',');}
export function cleanupResidue({backup,apply=false,logPath='docs/evidence/residue-cleanup.json'}){
 const rep=residueReport(),P=dbPrefix();
 const plan={summary:residueSummary(rep),junk_native_product_ids:rep.junk_native_products,orphan_auto_draft_post_ids:rep.orphan_auto_draft_posts,cleanup_job_ids:rep.completed_cleanup_jobs,control_keys:rep.test_control_keys};
 if(!apply)return {applied:false,plan};
 if(!backup||!fs.existsSync(backup)||fs.statSync(backup).size<1024)throw new Error('--apply requires an existing, non-empty --backup file');
 const products=rep.junk_native_products.map(Number).filter(Number.isInteger);
 const stmts=[];
 if(products.length){
  const ids=products.join(',');
  const variantIds=query(`SELECT id FROM ${P}wpe_variants WHERE product_id IN (${ids})`).map(r=>Number(r[0]));
  const owners=[...products,...variantIds].join(',');
  if(variantIds.length){const v=variantIds.join(',');
   stmts.push(`DELETE FROM ${P}wpe_variant_values WHERE variant_id IN (${v})`,`DELETE FROM ${P}wpe_variant_media WHERE variant_id IN (${v})`,`DELETE FROM ${P}wpe_catalog_facets WHERE variant_id IN (${v})`,`DELETE FROM ${P}wpe_variants WHERE id IN (${v})`);}
  stmts.push(
   `DELETE ov FROM ${P}wpe_option_values ov JOIN ${P}wpe_options o ON o.id=ov.option_id WHERE o.product_id IN (${ids})`,
   `DELETE FROM ${P}wpe_options WHERE product_id IN (${ids})`,
   `DELETE FROM ${P}wpe_catalog_facets WHERE product_id IN (${ids})`,
   `DELETE FROM ${P}wpe_catalog WHERE product_id IN (${ids})`,
   `DELETE FROM ${P}wpe_product_terms WHERE product_id IN (${ids})`,
   `DELETE FROM ${P}wpe_product_media WHERE product_id IN (${ids})`,
   `DELETE FROM ${P}wpe_product_links WHERE product_id IN (${ids}) OR target_id IN (${ids})`,
   `DELETE FROM ${P}wpe_inventory WHERE owner_id IN (${owners})`,
   `DELETE FROM ${P}wpe_object_state WHERE object_id IN (${owners})`,
   `DELETE FROM ${P}wpe_sku_registry WHERE object_id IN (${owners})`,
   `DELETE FROM ${P}wpe_meta WHERE object_id IN (${owners})`,
   `DELETE FROM ${P}wpe_downloads WHERE object_id IN (${owners})`,
   `DELETE FROM ${P}wpe_products WHERE id IN (${ids})`,
   `DELETE FROM ${P}postmeta WHERE post_id IN (${owners})`,
   `DELETE FROM ${P}term_relationships WHERE object_id IN (${owners})`,
   `DELETE FROM ${P}posts WHERE ID IN (${owners}) AND post_type IN ('product','product_variation')`);
 }
 const orphans=rep.orphan_auto_draft_posts.map(Number).filter(Number.isInteger);
 if(orphans.length){const o=orphans.join(',');stmts.push(`DELETE FROM ${P}postmeta WHERE post_id IN (${o})`,`DELETE FROM ${P}term_relationships WHERE object_id IN (${o})`,`DELETE FROM ${P}posts WHERE ID IN (${o}) AND post_status='auto-draft'`);}
 if(rep.completed_cleanup_jobs.length)stmts.push(`DELETE FROM ${P}wpe_jobs WHERE id IN (${inList(rep.completed_cleanup_jobs)}) AND kind='cleanup' AND status='completed'`);
 if(rep.test_control_keys.length)stmts.push(`DELETE FROM ${P}wpe_control WHERE \`key\` IN (${inList(rep.test_control_keys)})`);
 const before=rowCounts();
 for(const s of stmts)must(wp(['db','query',s]),`cleanup statement ${s.slice(0,60)}`);
 const after=rowCounts();
 const remaining=residueSummary(residueReport());
 const log={cleaned_at:new Date().toISOString(),backup:path.basename(backup),backup_bytes:fs.statSync(backup).size,deleted:plan,statements:stmts.length,count_delta:diffCounts(before,after),remaining_junk:remaining,untouched:'wpe_audit and wpe_outbox are append-only history and were not modified; objects not matching the junk signatures were left in place'};
 fs.mkdirSync(path.dirname(logPath),{recursive:true});fs.writeFileSync(logPath,JSON.stringify(log,null,2)+'\n');
 return {applied:true,log};
}

// ---- CLI ----
if(process.argv[1]===fileURLToPath(import.meta.url)){
 const [cmd,...rest]=process.argv.slice(2);const flag=n=>{const i=rest.indexOf(n);return i<0?null:rest[i+1];};
 try{
  if(cmd==='residue'){const rep=residueReport();const total=residueTotal(rep);console.log(JSON.stringify(rest.includes('--json')?{total,...rep}:{total,summary:residueSummary(rep)}));process.exit(total?1:0);}
  else if(cmd==='counts'){console.log(JSON.stringify(rowCounts()));}
  else if(cmd==='snapshot'){snapshot(rest[0]);console.log(JSON.stringify({snapshot:rest[0],bytes:fs.statSync(rest[0]).size}));}
  else if(cmd==='restore'){restore(rest[0]);console.log(JSON.stringify({restored:rest[0]}));}
  else if(cmd==='cleanup-residue'){console.log(JSON.stringify(cleanupResidue({backup:flag('--backup'),apply:rest.includes('--apply')}),null,1));}
  else{console.error('Usage: residue|counts|snapshot <file>|restore <file>|cleanup-residue --backup <file> [--apply]');process.exit(2);}
 }catch(e){console.error(e.message);process.exit(1);}
}
