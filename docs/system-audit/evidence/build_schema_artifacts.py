"""Generate documentary artifacts from read-only database metadata, never connect or mutate."""
import json,re,csv
from pathlib import Path
root=Path(__file__).resolve().parents[3]
evidence=Path(__file__).parent
data=json.loads((evidence/'live-schema.json').read_text())
out=root/'docs/capstone-manuscript/tables';out.mkdir(parents=True,exist_ok=True)
diagrams=root/'docs/capstone-manuscript/diagrams';diagrams.mkdir(parents=True,exist_ok=True)
purposes={
'users':'Registered accounts, role, active status, password hash and acceptance state.',
'guest_sessions':'Anonymous session identity and inactivity expiry.',
'password_resets':'Hashed recovery codes and expiry/consumption state.',
'user_tokens':'Persistent sign-in selectors and hashed validators.',
'rate_limits':'Per-action identity counters and time windows.',
'admin_audit_logs':'Administrative action evidence and target references.',
'admin_security_challenges':'Legacy administrator challenge storage; current login uses emailed codes.',
'summaries':'Summary ownership, sharing capability, metadata, aggregate metrics and cached Nutshell.',
'summary_artifacts':'Source text and structured generated output stored apart from summary metadata.',
'summary_jobs':'Durable background work state, owner, payload, retry and result references.',
'documents':'Independent document API source content and nominal owner fields.',
'document_summaries':'Cached summary for each document/profile/length combination.',
'nutshell_generations':'Completed/failed short-summary generation ledger and generation metrics.',
'feedback':'Latest owner-associated rating, reasons and optional legacy identifying fields.',
'feedback_comments':'Feedback comment/reason event ledger without direct user/guest columns.',
'landing_page_sessions':'Aggregated visitor engagement, device and web-vital measurements.',
'landing_page_events':'Individual landing-page events associated with a session.',
'schema_migrations':'Migration application bookkeeping.',
'evaluation_datasets':'Versioned evaluation collections and sealing state.',
'evaluation_documents':'Dataset source documents, split, profile and provenance.',
'evaluation_references':'Versioned reference summaries and review status.',
'evaluation_content_units':'Essential source facts to assess coverage.',
'evaluation_challenge_cases':'Expected facts and prohibited distortions for challenge cases.',
'evaluation_runs':'Frozen configuration and dataset snapshots for comparison runs.',
'evaluation_outputs':'Generated output for a run/document/mode/system/ablation combination.',
'evaluation_metrics':'Metric values, metric versions and availability/error status.',
'evaluation_assignments':'Blinded output assignments to registered evaluator accounts.',
'evaluation_ratings':'Human criterion ratings for an assignment.',
'evaluation_errors':'Reviewer-classified error observations.',
'evaluation_content_unit_reviews':'Human judgments of whether output represents a content unit.',
'evaluation_audit_log':'Changes to evaluation records with old/new values and reasons.'}
meaning={'id':'Unique row identifier.','user_id':'Registered account identifier; an enforced relation only where listed below.',
'guest_token':'Anonymous owner/session token; not a foreign key unless explicitly listed.',
'created_at':'Creation timestamp.','updated_at':'Most recent update timestamp.','summary_id':'Related summary identifier.',
'document_id':'Related document identifier in this table’s domain.','run_id':'Related evaluation run identifier.',
'output_id':'Related generated evaluation output identifier.','content':'Stored source document text.',
'original_text':'Persisted original/source text (main summary service applies pattern masking).',
'generated_summary':'Structured summary JSON/text payload.','password_hash':'One-way password verifier, not a readable password.',
'token_hash':'One-way recovery-code verifier.','share_token':'Random capability token used by shared-summary links.',
'selector':'Public lookup part of a persistent sign-in token.','hashed_validator':'Hash of persistent sign-in secret.',
'payload':'Job input JSON retained according to processing/retry lifecycle.',
'state':'Current durable work state.','status':'Domain status marker.','active':'Enabled/active state.',
'role':'Account access category.','terms_accepted':'Whether the account accepted terms.',
'terms_accepted_at':'Recorded acceptance time.', 'rating':'Numeric feedback rating.',
'reasons':'Serialized selected feedback reason codes.','comment':'Free-text feedback comment.',
'processing_time':'Elapsed summary processing seconds; current main caller does not supply this value.',
'original_word_count':'Source word count; main file path currently counts the empty input string.',
'summary_word_count':'Generated summary word count.',
'compression_ratio':'Module-specific compression metric; check service semantics before comparisons.',
'profile':'Content emphasis/profile selection.', 'length':'Summary depth selection.',
'source_hash':'Hash used to identify source content.', 'configuration_hash':'Hash of canonical run configuration.',
'dataset_snapshot_json':'Frozen dataset content and reference snapshot.', 'provenance_json':'Serialized execution/source provenance.',
'metric_value':'Metric value, nullable when unavailable.', 'review_status':'Reference-review lifecycle status.',
'blind_label':'Evaluator-visible label that conceals system identity.'}
tables=[t['TABLE_NAME'] for t in data['tables']]
cols={t:[c for c in data['columns'] if c['TABLE_NAME']==t] for t in tables}
fks=data['foreign_keys'];indexes=data['indexes'];checks=data.get('checks',[])
def esc(s):return str(s).replace('|','\\|').replace('\n',' ')
def constraints(t,c):
 result=['NULL allowed' if c['IS_NULLABLE']=='YES' else 'NOT NULL']
 if c['COLUMN_KEY']=='PRI':result.append('PRIMARY KEY')
 if c['EXTRA']:result.append(c['EXTRA'])
 result.append('default '+('SQL NULL / no explicit non-null default' if c['COLUMN_DEFAULT'] is None else str(c['COLUMN_DEFAULT'])))
 for fk in fks:
  if fk['TABLE_NAME']==t and fk['COLUMN_NAME']==c['COLUMN_NAME']:result.append('FK → '+fk['REFERENCED_TABLE_NAME']+'.'+fk['REFERENCED_COLUMN_NAME']+'; DELETE '+fk['DELETE_RULE']+'; UPDATE '+fk['UPDATE_RULE'])
 return '; '.join(result)
lines=['# Data dictionary — verified live schema','',
'Metadata was read directly from information_schema without loading the application bootstrap or reading application rows. The snapshot contains 31 tables, 312 columns and 35 foreign-key constraints. SQL types, defaults, nullability, keys and relationships below are observed metadata. Field-purpose prose is an interpretation of names and inspected service use, not a claim that every field is actively used.',
'','The current live schema differs from the fresh-install scripts for password_resets: live uses used_at and has no used/created_at columns, while database/schema.sql defines used and created_at and no checked-in migration adds used_at. Do not treat the base SQL alone as the deployed schema.',
'','Tables are presented alphabetically. Chapter III supplies the academic table caption; subsection keys here are stable dictionary identifiers.','']
for i,t in enumerate(tables,1):
 lines += [f'## DD-{i:02d}. {t}','',purposes.get(t,'Application storage table.'),'','| Field Name | Description | Data Type/Length | Constraints |','|---|---|---|---|']
 for c in cols[t]:
  desc=meaning.get(c['COLUMN_NAME'],c['COLUMN_NAME'].replace('_',' ').capitalize()+'.')
  lines.append('| '+ ' | '.join(map(esc,[c['COLUMN_NAME'],desc,c['COLUMN_TYPE'],constraints(t,c)]))+' |')
 lines += ['','Indexes and constraints:','']
 names=sorted({x['INDEX_NAME'] for x in indexes if x['TABLE_NAME']==t})
 for name in names:
  ix=sorted([x for x in indexes if x['TABLE_NAME']==t and x['INDEX_NAME']==name],key=lambda x:int(x['SEQ_IN_INDEX']))
  lines.append('- `'+name+'`: '+('UNIQUE' if int(ix[0]['NON_UNIQUE'])==0 else 'nonunique')+' ('+', '.join(x['COLUMN_NAME'] for x in ix)+').')
 for ck in checks:
  if ck['TABLE_NAME']==t:lines.append('- CHECK `'+ck['CONSTRAINT_NAME']+'`: `'+ck['CHECK_CLAUSE']+'`.')
 lines += ['','The table supports '+purposes.get(t,'application storage').rstrip('.') .lower()+'. Its constraints describe enforced storage behavior; runtime verification is reported separately.','']
(out/'DATA_DICTIONARY.md').write_text('\n'.join(lines),encoding='utf8')
with (out/'DATA_DICTIONARY.csv').open('w',newline='',encoding='utf-8-sig') as f:
 w=csv.writer(f);w.writerow(['table','field','description','sql_type','constraints'])
 for t in tables:
  for c in cols[t]:w.writerow([t,c['COLUMN_NAME'],meaning.get(c['COLUMN_NAME'],c['COLUMN_NAME'].replace('_',' ').capitalize()+'.'),c['COLUMN_TYPE'],constraints(t,c)])
def mermaid(selected):
 result=['erDiagram']
 for t in selected:
  result.append('    '+t+' {')
  for c in cols[t]:
   related=[k for k in fks if k['TABLE_NAME']==t and k['COLUMN_NAME']==c['COLUMN_NAME']]
   if c['COLUMN_KEY']=='PRI' or related:
    typ=re.sub(r'[^a-zA-Z0-9_]','_',c['COLUMN_TYPE'].split('(')[0].replace(' ','_'))
    keys=[]
    if c['COLUMN_KEY']=='PRI':keys.append('PK')
    if related:keys.append('FK')
    result.append('        '+typ+' '+c['COLUMN_NAME']+' '+','.join(keys))
  result.append('    }')
 for fk in fks:
  t=fk['TABLE_NAME'];parent=fk['REFERENCED_TABLE_NAME']
  if t not in selected or parent not in selected:continue
  column=next(c for c in cols[t] if c['COLUMN_NAME']==fk['COLUMN_NAME'])
  parentmark='|o' if column['IS_NULLABLE']=='YES' else '||'
  singleunique=any(int(ix['NON_UNIQUE'])==0 and ix['COLUMN_NAME']==fk['COLUMN_NAME'] and sum(1 for z in indexes if z['TABLE_NAME']==t and z['INDEX_NAME']==ix['INDEX_NAME'])==1 for ix in indexes if ix['TABLE_NAME']==t)
  childmark='o|' if singleunique else 'o{'
  result.append(f'    {parent} {parentmark}--{childmark} {t} : "{fk["COLUMN_NAME"]}"')
 return '\n'.join(result)+'\n'
(diagrams/'ERD_ACTUAL_SCHEMA.mmd').write_text(mermaid(tables),encoding='utf8')
(diagrams/'ERD_CORE.mmd').write_text(mermaid([t for t in tables if not t.startswith('evaluation_')]),encoding='utf8')
(diagrams/'ERD_EVALUATION.mmd').write_text(mermaid([t for t in tables if t.startswith('evaluation_') or t=='users']),encoding='utf8')
(diagrams/'ERD_NOTES.md').write_text('''# ERD evidence and interpretation

These diagrams are generated from live-schema.json. Only actual foreign keys appear as edges. Nullable foreign keys allow zero or one parent; non-null keys require a parent. A parent permits zero or many child rows unless the child foreign-key column is independently unique. Primary/foreign-key columns are shown; the full dictionary supplies all attributes.

Figure interpretation: the core ERD illustrates registered accounts, persistent tokens, summaries, artifacts, feedback, jobs and document caches. The evaluation ERD illustrates datasets, source documents, runs, outputs, metrics, reviewer assignments, ratings, and audit records. The combined source contains every observed relationship.

Important boundaries: guest_token is not an enforced relationship to guest_sessions. documents.user_id, nutshell_generations.user_id and landing_page_sessions.user_id do not have foreign keys. summary_artifacts.summary_id is not unique, so the database allows multiple artifacts per summary even though the normal writer inserts one. Evaluation assignment/ratings have real uniqueness constraints. No historical normalization sequence is claimed.
''',encoding='utf8')
print(json.dumps({'tables':len(tables),'columns':len(data['columns']),'foreign_keys':len(fks),'dictionary':str(out/'DATA_DICTIONARY.md')}))
