from pathlib import Path
import re,html,textwrap
B=Path(__file__).resolve().parents[1]
p=B/'CHAPTER_3.md';s=p.read_text(encoding='utf-8')
s=s.replace('The use-case model separates browser users from maintenance operators and external processing services.','The use-case model distinguishes browser access contexts. Maintenance is an operational responsibility described in Appendix G, rather than an additional stored account role.')
s=s.replace('guest, registered-user and administrator activities, together with maintenance and assigned-evaluation responsibilities.','guest, registered-user, administrator and assigned-evaluator activities.')
def fig(key,name,title,body):return f'![{title}](diagrams/{name}.png)\n\nFigure {key}. {title}\n\nFigure {key} illustrates {body}\n\n'
use='The following enlarged role panels provide the readable detail of the overview. Their associations do not introduce additional database roles.\n\n'
for i,(name,title) in enumerate([('guests','Guest visitor use cases'),('registered','Registered account use cases'),('administrators','Administrator use cases'),('evaluators','Assigned evaluator use cases')],1):
    use+=fig('UC'+str(i),'use-cases-'+name,title+'.','the authorized activities for the named access context. The panel is derived from the same role model as the overview; existence of a use case is source-supported and does not override the verification limits in the feature inventory.')
if 'Figure UC1.' not in s:s=s.replace('#### B. Data Flow Diagram',use+'#### B. Data Flow Diagram')
ds=[('dfd-level1-accounts','Level 1 account and session detail'),('dfd-level1-summary','Level 1 source and summary detail'),('dfd-level1-results','Level 1 result and reading-helper detail'),('dfd-level1-feedback','Level 1 feedback and usage detail'),('dfd-level1-administration','Level 1 administration and evaluation detail'),('dfd-level2-summary-intake','Level 2 summary intake detail'),('dfd-level2-summary-output','Level 2 summary output detail'),('dfd-level2-auth-verification','Level 2 account verification detail'),('dfd-level2-auth-session','Level 2 session and identity-change detail')]
detail='The following enlarged panels retain the process and store identifiers used in the overview. They divide a dense drawing for print legibility rather than define additional processes.\n\n'
for i,(name,title) in enumerate(ds,1):detail+=fig('DP'+str(i),name,title+'.','the named portion of the inspected data flow. Inputs and outputs pass through an application process; persistent tables, file storage and PHP session state retain their documented distinctions. The complete overview and editable sources retain the cross-panel context.')
if 'Figure DP1.' not in s:s=s.replace('#### C. Database Normalization',detail+'#### C. Database Normalization')
p.write_text(s,encoding='utf-8')

# Original redraw of the supplied schedule, using its month/week slots without invented dates.
chapter=(B/'CHAPTER_2.md').read_text(encoding='utf-8')
items=[];months=['April','May','June','July','August','September','October']
for n,stage,activity,interval in re.findall(r'^\| (\d+) \| ([^|]+) \| ([^|]+) \| ([^|]+) \|$',chapter,re.M):
    if not any(m in interval for m in months):continue
    hits=re.findall(r'(April|May|June|July|August|September|October) weeks? (\d)(?:[–-](\d))?',interval)
    if not hits:raise ValueError(interval)
    m,a,b=hits[0];start=months.index(m)*4+int(a)-1
    if len(hits)==1:end=months.index(m)*4+int(b or a)-1
    else:m,a,b=hits[-1];end=months.index(m)*4+int(b or a)-1
    items.append((int(n),activity.strip(),start,end))
assert len(items)==21
for panel in range(3):
    rows=items[panel*7:(panel+1)*7];svg=['<svg xmlns="http://www.w3.org/2000/svg" width="800" height="670" viewBox="0 0 800 670">','<rect width="800" height="670" fill="white"/>','<g font-family="Arial,sans-serif" fill="#172c43">','<text x="20" y="30" font-size="23" font-weight="bold">Supplied provisional schedule — 2026</text>','<text x="20" y="57" font-size="19">Week slots are planned; completion is not established.</text>']
    x0=325;cw=16
    for i,m in enumerate(months):svg.append(f'<text x="{x0+i*64+32}" y="90" font-size="18" text-anchor="middle">{m[:3]}</text>')
    for j in range(28):svg.append(f'<text x="{x0+j*cw+8}" y="116" font-size="16" text-anchor="middle">{j%4+1}</text>')
    for r,(number,title,start,end) in enumerate(rows):
        y=135+r*70
        for k,line in enumerate(textwrap.wrap(str(number)+'. '+title,27)):
            svg.append(f'<text x="20" y="{y+25+k*23}" font-size="20">{html.escape(line)}</text>')
        for j in range(28):svg.append(f'<rect x="{x0+j*cw}" y="{y}" width="16" height="58" fill="{"#e5eef6" if (j//4)%2==0 else "#f7f9fb"}" stroke="#c4ccd4"/>')
        svg.append(f'<rect x="{x0+start*cw+2}" y="{y+15}" width="{(end-start+1)*cw-4}" height="28" rx="3" fill="#26678d"/>')
    svg.append(f'<text x="20" y="650" font-size="18">Activities {panel*7+1}–{panel*7+7}; source: researcher-supplied draft.</text></g></svg>')
    (B/f'diagrams/gantt-supplied-{panel+1}.svg').write_text(''.join(svg),encoding='utf-8')
print('Added focused diagram discussions and three supplied-schedule SVGs.')
