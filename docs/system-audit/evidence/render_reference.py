import sys
sys.path.insert(0,r'docs/system-audit/evidence/tool-dependencies')
import fitz
from PIL import Image,ImageDraw
from pathlib import Path
p=fitz.open(r'C:\Downloads\AccredX-Manuscript-Final.pdf'); out=Path('docs/capstone-manuscript/reference-analysis/rendered');out.mkdir(exist_ok=True)
for start in range(0,len(p),12):
    canvas=Image.new('RGB',(1680,1860),'#cccccc');d=ImageDraw.Draw(canvas)
    for slot,i in enumerate(range(start,min(start+12,len(p)))):
        pix=p[i].get_pixmap(matrix=fitz.Matrix(0.76,0.76),alpha=False)
        im=Image.frombytes('RGB',[pix.width,pix.height],pix.samples)
        x=(slot%4)*420;y=(slot//4)*620
        canvas.paste(im,(x,y+22)); d.text((x+5,y+3),f'PDF page {i+1}',fill='black')
    canvas.save(out/f'contact-{start+1:03}-{min(start+12,len(p)):03}.jpg',quality=88)
for n in [1,10,20,30,41,53,69,71,73,90,91,103,138,145,146,161,164,172,177]:
    p[n-1].get_pixmap(matrix=fitz.Matrix(1.4,1.4),alpha=False).save(out/f'page-{n:03}.png')
print('Rendered',len(p),'pages')
