import fs from 'node:fs';import path from 'node:path';import crypto from 'node:crypto';
export const sha256=s=>crypto.createHash('sha256').update(s).digest('hex');
function walk(dir){return fs.existsSync(dir)?fs.readdirSync(dir,{withFileTypes:true}).flatMap(e=>e.isDirectory()?walk(path.join(dir,e.name)):[path.join(dir,e.name)]):[];}
export function contentDigest(root,files){
 const contracts=walk(path.join(root,'contracts')).map(f=>path.relative(root,f));
 const docs=walk(path.join(root,'docs')).filter(f=>/^\d\d-/.test(path.basename(f))).map(f=>path.relative(root,f));
 const inputs=[...new Set([...files,...contracts,...docs])].filter(f=>!f.startsWith('docs/evidence/')&&!/^docs\/plans\/.*\/(state\.json|log\.jsonl|progress\.md)$/.test(f)).sort();
 const chunks=[];for(const f of inputs){const full=path.resolve(root,f);if(full!==root&&!full.startsWith(path.resolve(root)+path.sep))throw new Error('Unsafe digest path');if(fs.existsSync(full)&&fs.statSync(full).isDirectory()){for(const sub of walk(full).sort())chunks.push([path.relative(root,sub),sha256(fs.readFileSync(sub))]);}else chunks.push([f,fs.existsSync(full)?sha256(fs.readFileSync(full)):'missing']);}
 return sha256(JSON.stringify(chunks));
}
export function containedFile(root,f){const full=path.resolve(root,f);if(!full.startsWith(path.resolve(root)+path.sep)||!fs.existsSync(full)||!fs.statSync(full).isFile())throw new Error('Evidence artifact missing/unsafe');return full;}
