"""Normalize caption references in final source order and validate local assets."""
from pathlib import Path
import re,json
BASE=Path(__file__).resolve().parents[1]
names=['PRELIMINARY_PAGES.md','CHAPTER_1.md','CHAPTER_2.md','CHAPTER_3.md','CHAPTER_4.md','REFERENCES.md','APPENDICES_A_E.md','APPENDIX_F_USER_MANUAL.md','APPENDIX_G_MAINTENANCE_MANUAL.md','EVALUATION_INSTRUMENTS.md','ADDITIONAL_APPENDICES.md']
files=[];lines=[]
def visit(p):
    if p in files:raise ValueError('Repeated include '+str(p))
    files.append(p)
    for line in p.read_text(encoding='utf-8-sig').splitlines():
        m=re.match(r'<!-- INCLUDE (.+) -->',line)
        if m:visit((p.parent/m[1]).resolve())
        else:lines.append((p,line))
for n in names:visit(BASE/n)
cap=re.compile(r'^(Figure|Table)\s+([A-Za-z0-9-]+)\.\s+(.+)$')
captions=[];mapping={};counts={'Figure':0,'Table':0}
for p,line in lines:
    m=cap.match(line.strip('*'))
    if m:
        kind,old,title=m.groups();key=kind+' '+old
        if key in mapping:raise ValueError('Duplicate caption '+key)
        counts[kind]+=1;mapping[key]=kind+' '+str(counts[kind])
        captions.append({'kind':kind,'old':key,'number':counts[kind],'title':title,'source':str(p.relative_to(BASE))})
# Add an explicit cross-reference when a section's explanation does not already name its figure/table.
texts={p:p.read_text(encoding='utf-8-sig') for p in files}
for c in captions:
    p=BASE/c['source'];text=texts[p];old=c['old'];occ=len(re.findall(re.escape(old)+r'\b',text))
    if occ<2:
        needle=old+'. '+c['title']
        if c['kind']=='Figure':
            text=text.replace(needle,needle+'\n\n'+old+' illustrates '+c['title'][0].lower()+c['title'][1:],1)
        else:
            # Insert after its complete Markdown table, retaining caption-before-table convention.
            start=text.index(needle)+len(needle);tail=text[start:]
            m=re.search(r'\n\|[^\n]+\n\|[-: |]+\|\n(?:\|[^\n]+\n)+',tail)
            if m:
                pos=start+m.end();text=text[:pos]+'\n'+old+' presents '+c['title'][0].lower()+c['title'][1:]+' The stated status and qualifications govern its interpretation.\n'+text[pos:]
            else:raise ValueError('Caption without table '+old)
        texts[p]=text
pattern=re.compile(r'\b(?:'+ '|'.join(re.escape(k) for k in sorted(mapping,key=len,reverse=True))+r')\b')
for p,s in texts.items():
    s=pattern.sub(lambda m:mapping[m[0]],s)
    p.write_text(s,encoding='utf-8')
# Check captions in expanded order, images, tables and references after numbering.
images=[];uncaptioned=[];bad=[];missing=[];table_count=0
for p in files:
    s=p.read_text(encoding='utf-8');rows=s.splitlines()
    for i,line in enumerate(rows):
        im=re.match(r'^!\[(.*?)\]\((.+)\)$',line)
        if im:
            path=(p.parent/im[2]).resolve();images.append(str(path.relative_to(BASE)))
            if not path.exists():missing.append(str(path))
            following=next((l for l in rows[i+1:] if l.strip()),'')
            if not re.match(r'^Figure \d+\.',following):uncaptioned.append(str(p)+':'+str(i+1))
        if line.startswith('|') and i+1<len(rows) and re.match(r'^\|[-: |]+\|$',rows[i+1]):
            table_count+=1;previous=next((l for l in reversed(rows[:i]) if l.strip()),'')
            if not re.match(r'^Table \d+\.',previous) and '<!-- NO_TABLE_CAPTION -->' not in previous:uncaptioned.append(str(p)+':'+str(i+1)+' TABLE')
    for kind,number in re.findall(r'\b(Figure|Table)\s+(\d+)\b',s):
        if not 1<=int(number)<=counts[kind]:bad.append(kind+' '+number)
    if '\ufffd' in s:bad.append(str(p)+' replacement character')
report={'files':[str(p.relative_to(BASE)) for p in files],'captions':captions,'counts':counts,'image_occurrences':len(images),'unique_images':len(set(images)),'markdown_tables':table_count,'missing_assets':missing,'uncaptioned':uncaptioned,'invalid_references':bad,'research_status':'Human evaluation pending; proposed instruments and institutional placeholders retained.'}
(BASE/'build/SOURCE_VALIDATION.json').write_text(json.dumps(report,indent=2,ensure_ascii=False),encoding='utf-8')
print(json.dumps({k:v for k,v in report.items() if k not in ['captions','files']},ensure_ascii=True))
if missing or uncaptioned or bad:raise SystemExit(1)
