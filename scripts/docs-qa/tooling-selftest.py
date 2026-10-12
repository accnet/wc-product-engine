from pathlib import Path
import json,tempfile,shutil,subprocess
ROOT=Path(__file__).resolve().parents[2]
results=[]
def call(p,args,expected=0,label=''):
 r=subprocess.run(['node']+args,cwd=p,text=True,capture_output=True)
 assert r.returncode==expected,(label,args,r.returncode,r.stdout,r.stderr)
 results.append({'test':label or ' '.join(args),'expected_exit':expected,'exit_code':r.returncode})
 return r
def mutate_case(name,modify,command=None):
 with tempfile.TemporaryDirectory(prefix='wpe-doc-check-') as d:
  p=Path(d)/'kit';shutil.copytree(ROOT,p)
  modify(p)
  call(p,command or ['scripts/check-docs.mjs','--strict'],1,name)
call(ROOT,['scripts/check-docs.mjs','--strict'],label='valid docs')
call(ROOT,['scripts/plan.mjs','check'],label='empty initial plan registry')
call(ROOT,['scripts/guard.mjs','--all'],label='valid guard')
call(ROOT,['scripts/verify-task.mjs','T01'],1,'unimplemented task cannot pass')
call(ROOT,['scripts/test-runtime.mjs','T01'],2,'missing runtime runner cannot fake pass')
def add(p,f,s):
 q=p/f;q.write_text(q.read_text()+s)
mutate_case('bad section reference',lambda p:add(p,'docs/02-domain.md','\n01 §999\n'))
mutate_case('duplicate section',lambda p:add(p,'docs/02-domain.md','\n## 13. Duplicate\n'))
mutate_case('unbalanced fence',lambda p:add(p,'docs/NOTES.md','\n```js\n'))
mutate_case('template strict fail',lambda p:add(p,'docs/NOTES.md','\n{{unfilled}}\n'))
mutate_case('invalid table',lambda p:add(p,'docs/NOTES.md','\n| A | B |\n|---|---|\n| one | two | three |\n'))
mutate_case('unknown task',lambda p:add(p,'docs/NOTES.md','\nT99 missing\n'))
def progress(p):
 q=p/'docs/PROGRESS.md';q.write_text(q.read_text().replace('- [ ] T01','- [x] T01'))
mutate_case('done requires runtime evidence',progress)
def dag(p):
 q=p/'contracts/tasks.json';x=json.loads(q.read_text());x['tasks'][0]['depends_on']=['T49'];q.write_text(json.dumps(x))
mutate_case('DAG cycle fails',dag)
def api(p):
 q=p/'contracts/openapi/wpe-v1.json';x=json.loads(q.read_text());x['paths'].pop('/catalog');q.write_text(json.dumps(x))
mutate_case('OpenAPI missing endpoint',api)
def structure(p):
 q=p/'contracts/structure.json';x=json.loads(q.read_text());x['paths'].remove('scripts/test-runtime.mjs');q.write_text(json.dumps(x))
mutate_case('task Files outside structure',structure)
def guard(p):
 q=p/'tests/unit/IntentionalGuard.js';q.parent.mkdir(parents=True,exist_ok=True);q.write_text('test.only("focus", () => {});')
mutate_case('focused tests rejected',guard,['scripts/guard.mjs','--all'])
with tempfile.TemporaryDirectory(prefix='wpe-plan-lifecycle-') as d:
 p=Path(d)/'kit';shutil.copytree(ROOT,p)
 call(p,['scripts/plan.mjs','new','smoke','Smoke plan'],label='create draft plan')
 call(p,['scripts/plan.mjs','approve','P001'],1,'draft template cannot approve')
 folder=next((p/'docs/plans').glob('P001-*'));plan=folder/'plan.md'
 text=plan.read_text().replace('Author must write concrete goal.','Test script lifecycle in isolated scratch.').replace('Author must define scope and exclusions.','Only docs/NOTES.md inside temporary copy.').replace('Define concrete work before approval','Run isolated checker')
 plan.write_text(text)
 call(p,['scripts/plan.mjs','sync','P001'],label='sync authored plan')
 call(p,['scripts/plan.mjs','approve','P001'],label='approve concrete test plan')
 call(p,['scripts/plan.mjs','done','P001.1'],1,'done before start blocked')
 call(p,['scripts/plan.mjs','start','P001.1'],label='start approved task')
 call(p,['scripts/plan.mjs','done','P001.1'],1,'done without DoD blocked')
 call(p,['scripts/plan.mjs','run','P001.1','--','node','-e','process.exit(0)'],1,'undeclared command blocked')
 call(p,['scripts/plan.mjs','run','P001.1','--','node','scripts/check-docs.mjs','--strict'],label='execute declared DoD')
 add(p,'docs/NOTES.md','\nContent changed after test.\n')
 call(p,['scripts/plan.mjs','done','P001.1'],1,'uncommitted edit invalidates test receipt')
 call(p,['scripts/plan.mjs','run','P001.1','--','node','scripts/check-docs.mjs','--strict'],label='rerun after content change')
 call(p,['scripts/plan.mjs','done','P001.1'],label='done with fresh receipt')
 call(p,['scripts/plan.mjs','close','P001'],1,'close without report blocked')
 (folder/'report.md').write_text('# Report\n\n## Summary\n\nLifecycle tested in temporary copy.\n')
 call(p,['scripts/plan.mjs','report','P001'],label='record authored report')
 call(p,['scripts/plan.mjs','close','P001'],label='close completed test plan')
 call(p,['scripts/plan.mjs','check'],label='closed state/log/projections valid')
 (folder/'progress.md').write_text('tampered')
 call(p,['scripts/plan.mjs','check'],1,'generated progress tampering blocked')

out=ROOT/'docs/evidence/docs-tooling-selftests.json'
out.write_text(json.dumps({'scope':'documentation tooling only, isolated copies, no runtime WC tests','tests':results,'count':len(results)},indent=2)+'\n')
print(json.dumps({'selftests':len(results),'all_passed':True}))
