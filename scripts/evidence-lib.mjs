import fs from 'node:fs';import path from 'node:path';import crypto from 'node:crypto';import {spawnSync} from 'node:child_process';
export const sha256=s=>crypto.createHash('sha256').update(s).digest('hex');
function walk(dir){return fs.existsSync(dir)?fs.readdirSync(dir,{withFileTypes:true}).flatMap(e=>e.isDirectory()?walk(path.join(dir,e.name)):[path.join(dir,e.name)]):[];}
const WP_PATH=process.env.WPE_WP_PATH||'/var/www/site1.localhost';
// The registry `status` field is lifecycle metadata (revalidation_pending -> verified); it must not invalidate the evidence it describes.
function digestBytes(rel,full){
 if(rel==='contracts/regression-requirements.json'){try{const j=JSON.parse(fs.readFileSync(full,'utf8'));for(const r of j.requirements??[])delete r.status;return Buffer.from(JSON.stringify(j));}catch{}}
 return fs.readFileSync(full);
}
export function contentDigest(root,files){
 const contracts=walk(path.join(root,'contracts')).map(f=>path.relative(root,f));
 const docs=walk(path.join(root,'docs')).filter(f=>/^\d\d-/.test(path.basename(f))).map(f=>path.relative(root,f));
 const inputs=[...new Set([...files,...contracts,...docs])].filter(f=>!f.startsWith('docs/evidence/')&&!/^docs\/plans\/.*\/(state\.json|log\.jsonl|progress\.md)$/.test(f)).sort();
 const chunks=[];for(const f of inputs){const full=path.resolve(root,f);if(full!==root&&!full.startsWith(path.resolve(root)+path.sep))throw new Error('Unsafe digest path');if(fs.existsSync(full)&&fs.statSync(full).isDirectory()){for(const sub of walk(full).sort())chunks.push([path.relative(root,sub),sha256(fs.readFileSync(sub))]);}else chunks.push([f,fs.existsSync(full)?sha256(digestBytes(f,full)):'missing']);}
 return sha256(JSON.stringify(chunks));
}
export function containedFile(root,f){const full=path.resolve(root,f);if(!full.startsWith(path.resolve(root)+path.sep)||!fs.existsSync(full)||!fs.statSync(full).isFile())throw new Error('Evidence artifact missing/unsafe');return full;}

// R203: the environment is read from the live runtime, never typed into the runner.
let envCache;
export function runtimeEnvironment(){
 if(envCache)return envCache;
 const php='echo json_encode(["wordpress"=>get_bloginfo("version"),"woocommerce"=>defined("WC_VERSION")?WC_VERSION:"","php"=>PHP_VERSION,"database"=>$GLOBALS["wpdb"]->get_var("SELECT VERSION()")]);';
 const r=spawnSync('wp',[`--path=${WP_PATH}`,'--allow-root','eval',php],{encoding:'utf8'});
 if(r.status!==0)throw new Error('Cannot read runtime environment via WP-CLI: '+(r.stderr||'').slice(0,300));
 const line=r.stdout.trim().split('\n').filter(l=>l.startsWith('{')).pop();
 const env=JSON.parse(line);if(!['wordpress','woocommerce','php','database'].every(k=>env[k]))throw new Error('Incomplete runtime environment');
 return envCache=env;
}

// R204: a receipt cannot contain the SHA of the commit that stores it, so it binds the base commit plus the content of every task file.
export function baseCommit(root=process.cwd()){const r=spawnSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'});return r.status===0&&r.stdout.trim()?r.stdout.trim():'';}
export function commitExists(root,sha){return /^[0-9a-f]{40}$/.test(sha)&&spawnSync('git',['cat-file','-e',sha+'^{commit}'],{cwd:root}).status===0;}
export function fileHashes(root,files){
 const out={};
 for(const f of [...files].sort()){if(f.startsWith('docs/evidence/'))continue;const full=path.resolve(root,f);if(!fs.existsSync(full))continue;
  if(fs.statSync(full).isDirectory()){for(const sub of walk(full).sort())out[path.relative(root,sub)]=sha256(fs.readFileSync(sub));}
  else out[f]=sha256(fs.readFileSync(full));}
 return out;
}

// R205: per-requirement receipt derived from the test output, not from constants in the runner.
const PASS=/passed|PASSED|✓|\[OK\]|\bPASS\b|\bOK\b/;
const FAILED=/FAIL|Fatal error|✗/;
export function anchorRegex(id){
 if(id.startsWith('AC-'))return new RegExp(id.replace(/-/g,'[-_]')+'(?![0-9])');
 return new RegExp(`(?:^|[^A-Za-z0-9])(?:test)?R0*${Number(id.slice(1))}(?![0-9])`);
}
export function findAnchor(output,id){
 const re=anchorRegex(id),lines=output.split('\n');
 for(let i=0;i<lines.length;i++){const l=lines[i];if(re.test(l)&&PASS.test(l)&&!FAILED.test(l))return{line:i+1,text:l.trim().slice(0,200)};}
 return null;
}
export function buildReceipts(result,output){
 const problems=[];
 for(const r of result.regression_results){
  const a=findAnchor(output,r.id);
  if(a){r.status='passed';r.basis='output_anchor';r.anchor=a;}
  else{r.status='failed';r.basis='no_output_anchor';delete r.anchor;problems.push(`${r.id}: no passing anchor in test output`);}
 }
 const allPass=result.regression_results.every(r=>r.status==='passed');
 for(const ac of result.acceptance){
  const a=findAnchor(output,ac.id);
  if(a){ac.basis='output_anchor';ac.anchor=a;ac.result='passed';}
  else{ac.basis='requirements_only';delete ac.anchor;ac.result=allPass?'passed':'failed';}
  if(ac.result!=='passed')problems.push(`${ac.id}: not passed`);
 }
 return problems;
}
// Finalize a runner-produced result: runtime env, base commit, file hashes and receipts. Returns problems; the caller fails on any.
export function finalizeEvidence(root,task,result,output){
 result.environment=runtimeEnvironment();
 const base=baseCommit(root);result.commit=base;result.base_commit=base;
 result.file_hashes=fileHashes(root,task.files);
 // Requirements added to the contract after the runner was written still get a receipt (and fail without an anchor).
 for(const r of task.regression_requirements??[])if(!result.regression_results.some(x=>x.id===r.id))result.regression_results.push({id:r.id,status:'failed',command_ref:0,test_name:`${r.id}_${r.name}`,assertion_summary:`Task test output asserts requirement ${r.name}`});
 for(const a of task.acceptance_checks??[])if(!result.acceptance.some(x=>x.id===a.id))result.acceptance.push({id:a.id,result:'failed',artifact:task.files.find(f=>!f.startsWith('docs/evidence/'))??task.files[0],observation:a.text??a.id});
 const problems=buildReceipts(result,output);
 if(problems.length){result.qa_status='failed';result.blockers=problems;}
 return problems;
}
