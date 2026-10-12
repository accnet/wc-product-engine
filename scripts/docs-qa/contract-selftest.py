from pathlib import Path
import json,re
r=Path(__file__).resolve().parents[2];j=json.loads((r/'contracts/openapi/wpe-v1.json').read_text());s=j['components']['schemas'];tests=[]
def check(name,condition):
 assert condition,name
 tests.append({'name':name,'passed':True})
def walk(x):
 if isinstance(x,dict):
  for k,v in x.items():
   if k=='$ref':
    assert v.startswith('#/'),'External reference not checked'
    ref=j
    for key in v[2:].split('/'):ref=ref[key.replace('~1','/').replace('~0','~')]
   else:walk(v)
 elif isinstance(x,list):
  for v in x:walk(v)
walk(j);check('all local OpenAPI references resolve',True)
check('22 operations registered',sum(len(v) for v in j['paths'].values())==22)
for name in ['ValidateWrite','CutoverWrite','RollbackWrite','VariantMutationResult']:
 check(name+' required fields exist',set(s[name]['required'])<=set(s[name]['properties']))
check('cutover requires gate ID',set(s['CutoverWrite']['required'])=={'gate_id','expected_epoch','manifest_hash'})
check('rollback requires reason and defaults dry run',set(s['RollbackWrite']['required'])=={'expected_epoch','manifest_hash','reason'} and s['RollbackWrite']['properties']['dry_run']['default'] is True)
check('validation requires snapshot',set(s['ValidateWrite']['required'])=={'expected_epoch','manifest_hash','evidence_snapshot'})
check('variant mutation requires parent version','parent_version' in s['VariantMutationResult']['required'])
a=s['ProductWrite']['allOf'][0]
check('collection mutation requires collection mode',a['then']['required']==['collection_mode'] and {v['required'][0] for v in a['if']['anyOf']}=={'options','terms','meta','variants'})
stock=s['ProductWrite']['properties']['stock']['properties']['quantity']['pattern']
check('stock string supports negative backorders',bool(re.fullmatch(stock,'-1.500000')) and bool(re.fullmatch(stock,'0')) and not re.fullmatch(stock,'1e3'))
price=s['ProductWrite']['properties']['regular_price']['pattern']
check('price string rejects negative',not re.fullmatch(price,'-1') and bool(re.fullmatch(price,'123.45')))
check('catalog facet mode is explicit',any(p['name']=='facet_semantics' for p in j['paths']['/catalog']['get']['parameters']))
check('product view/edit context exposed',any(p['name']=='context' for p in j['paths']['/products/{id}']['get']['parameters']))
check('delete force exposed',any(p['name']=='force' for p in j['paths']['/admin/products/{id}']['delete']['parameters']))
t=json.loads((r/'contracts/tasks.json').read_text())['tasks'];ids=[a['id'] for x in t for a in x['acceptance_checks']]
check('acceptance IDs unique and present',len(ids)==len(set(ids)) and all(x['acceptance_checks'] for x in t))
check('T02 lockfiles declared',{'composer.lock','package-lock.json'}<=set(t[1]['files']))
out={'scope':'contract structure and selected design assertions only; no full JSON Schema/OpenAPI conformance validator or runtime execution','count':len(tests),'all_passed':True,'tests':tests}
(r/'docs/evidence/contract-selftests.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({'contract_checks':len(tests),'all_passed':True}))
