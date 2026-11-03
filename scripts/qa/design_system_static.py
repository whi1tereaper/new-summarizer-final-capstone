"""Source inventory for semantic-token drift and basic CSS structural errors."""
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CSS = ROOT / 'app/public/assets/css'
REPORT = ROOT / 'docs/qa/design-system/static-results.json'


def structure_errors(source):
    stack=[]; quote=None; escaped=False; comment=False; line=1; errors=[]; i=0
    while i<len(source):
        char=source[i]; nxt=source[i:i+2]
        if char=='\n': line+=1
        if comment:
            if nxt=='*/': comment=False; i+=2; continue
        elif quote:
            if escaped: escaped=False
            elif char=='\\': escaped=True
            elif char==quote: quote=None
        elif nxt=='/*': comment=True; i+=2; continue
        elif char in '\"\'': quote=char
        elif char=='{': stack.append(line)
        elif char=='}':
            if not stack: errors.append({'line':line,'message':'Unmatched closing brace'})
            else: stack.pop()
        i+=1
    if comment: errors.append({'line':line,'message':'Unclosed comment'})
    if quote: errors.append({'line':line,'message':'Unclosed string'})
    errors.extend({'line':start,'message':'Unclosed block'} for start in stack)
    return errors


def main():
    sources={path:path.read_text(encoding='utf8') for path in CSS.glob('*.css')}
    clean={path:re.sub(r'/\*.*?\*/','',text,flags=re.S) for path,text in sources.items()}
    defined={name for text in clean.values() for name in re.findall(r'(--[\w-]+)\s*:',text)}
    defined.update(name for path in (ROOT/'app/public').rglob('*.php') for name in re.findall(r'(--[\w-]+)\s*:',path.read_text(encoding='utf8')))
    defined.update(name for path in (ROOT/'app/public/assets/js').glob('*.js') for name in re.findall(r"setProperty\(['\"](--[\w-]+)['\"]",path.read_text(encoding='utf8')))
    undefined=[]; palette=[]; literals=[]; structure=[]
    color_pattern=re.compile(r'(?<![\w-])#[0-9a-fA-F]{3,8}\b|\b(?:rgb|rgba|hsl|hsla)\([^)]*\)')
    for path,text in sources.items():
        relative=path.relative_to(ROOT).as_posix()
        structure.extend({'file':relative,**error} for error in structure_errors(text))
        for number,line in enumerate(text.splitlines(),1):
            if line.strip().startswith(('/*','*','//')): continue
            for name in re.findall(r'var\(\s*(--[\w-]+)',line):
                if name not in defined: undefined.append({'file':relative,'line':number,'token':name})
            if path.name=='design-tokens.css': continue
            for name,value in re.findall(r'(--color-[\w-]+)\s*:\s*([^;}]+)',line):
                palette.append({'file':relative,'line':number,'token':name,'value':value.strip()})
            for match in color_pattern.finditer(line):
                if 'var(' in match.group(): continue
                literals.append({'file':relative,'line':number,'color':match.group(),'context':line.strip()[:180]})
    result={'css_files':len(sources),'undefined_tokens':undefined,'page_palette_definitions':palette,'literal_colors_outside_tokens':literals,'structural_errors':structure}
    REPORT.parent.mkdir(parents=True,exist_ok=True);REPORT.write_text(json.dumps(result,indent=2),encoding='utf8')
    print(json.dumps({key:len(value) if isinstance(value,list) else value for key,value in result.items()},indent=2))
    if undefined or palette or literals or structure: raise SystemExit(1)


if __name__=='__main__': main()
