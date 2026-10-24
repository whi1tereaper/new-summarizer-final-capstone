"""Acquire licensed external article prose without touching engine settings."""
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from hashlib import sha256
import json
from pathlib import Path
import re
import sys
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
sys.path = [p for p in sys.path if p != str(Path(__file__).resolve().parent)]
from evaluation.independent_validation import OUT, verify, write

# Selected by category/licensing, never by summary performance.
CANDIDATES = [
    dict(id='IV-NEWS', category='news', publisher='USGS',
         url='https://www.usgs.gov/news/state-news-release/usgs-deploys-aftershock-kits-study-whitehouse-station-earthquakes',
         license='US federal government-authored prose; public domain in the US',
         license_url='https://www.usgs.gov/information-policies-and-instructions/copyrights-and-credits'),
    dict(id='IV-SCIENCE', category='scientific', publisher='NIH/NCI',
         url='https://www.nih.gov/news-events/news-releases/healthy-adults-taking-multivitamins-daily-not-associated-lower-risk-death',
         download='https://www.cancer.gov/news-events/press-releases/2024/multivitamins-do-not-lower-risk-of-death',
         license='NIH federal government-authored prose; public domain in the US',
         license_url='https://www.nih.gov/important-links/website-policies'),
    dict(id='IV-RESEARCH', category='research', publisher='PLOS One',
         url='https://journals.plos.org/plosone/article?id=10.1371/journal.pone.0357605',
         download='https://journals.plos.org/plosone/article/file?id=10.1371/journal.pone.0357605&type=manuscript',
         license='CC BY 4.0; attribution to authors/title/DOI retained',
         license_url='https://journals.plos.org/plosone/s/licenses-and-copyright'),
    dict(id='IV-BUSINESS', category='business', publisher='US EIA',
         url='https://www.eia.gov/TodayinEnergy/detail.php?id=65524',
         license='US federal government-authored prose; public domain in the US',
         license_url='https://www.eia.gov/about/copyrights_reuse.php'),
    dict(id='IV-EDUCATION', category='educational', publisher='Our World in Data',
         url='https://ourworldindata.org/safest-sources-of-energy',
         license='CC BY 4.0; article prose only, no third-party chart datasets/images',
         license_url='https://ourworldindata.org/faqs#how-is-our-work-copyrighted'),
    dict(id='IV-TECHNICAL', category='technical', publisher='Python PEPs',
         url='https://peps.python.org/pep-0684/',
         license='CC0 1.0, per article Copyright section',
         license_url='https://peps.python.org/pep-0684/#copyright'),
    dict(id='IV-EDITORIAL', category='editorial', publisher='Frontiers in Climate',
         url='https://www.frontiersin.org/journals/climate/articles/10.3389/fclim.2024.1355110/full',
         license='CC BY, per article copyright statement; authors credited',
         license_url='https://www.frontiersin.org/journals/climate/articles/10.3389/fclim.2024.1355110/full#h10'),
    dict(id='IV-FEATURE', category='feature', publisher='NASA Earth Observatory',
         url='https://science.nasa.gov/earth/earth-observatory/record-setting-retreat-of-hektoria-glacier/',
         license='NASA-authored federal government prose; public domain in the US; images excluded',
         license_url='https://www.nasa.gov/nasa-brand-center/images-and-media/'),
]


def fetch(candidate):
    import requests
    from bs4 import BeautifulSoup
    response = requests.get(candidate.get('download', candidate['url']), timeout=75,
                            headers={'User-Agent': 'LIGHT-Research-Validation/1.0 (licensed prose evaluation)'})
    response.raise_for_status()
    raw = response.content
    soup = BeautifulSoup(raw.decode('utf8'), 'lxml') if candidate['id'] != 'IV-RESEARCH' else None
    if soup is None:
        title = ''
    else:
        title = soup.find('h1')
        title = title.get_text(' ', strip=True) if title else (soup.title.get_text(' ', strip=True) if soup.title else '')
    selector = ''
    metadata = {}
    if candidate['id'] == 'IV-RESEARCH':
        tree = ET.fromstring(raw)
        title = ''.join(tree.find('.//article-title').itertext())
        for parent in tree.iter():
            for child in list(parent):
                if child.tag in {'fig', 'table-wrap', 'supplementary-material'}: parent.remove(child)
        paragraphs = []
        for section in [tree.find('.//abstract'), tree.find('body')]:
            for node in section.iter():
                if node.tag in {'p', 'title'}:
                    text = ''.join(node.itertext()).strip()
                    if text: paragraphs.append(text)
        selector = 'JATS abstract + body titles/p; excludes reference list, table cells and figure media'
        metadata['authors'] = [' '.join(''.join(n.itertext()) for n in author.findall('name'))
                               for author in tree.findall('.//contrib[@contrib-type="author"]')]
        metadata['publication_dates'] = ['-'.join(''.join(d.itertext()).split()) for d in tree.findall('.//pub-date')]
        metadata['license_statement'] = ' '.join(''.join(n.itertext()) for n in tree.findall('.//license'))
    else:
        selectors = {
            'IV-NEWS': 'main', 'IV-SCIENCE': 'article .usa-prose--ncids-full-html',
            'IV-BUSINESS': '.tie-article', 'IV-EDUCATION': 'article',
            'IV-TECHNICAL': '#pep-content', 'IV-EDITORIAL': '.ArticleContent',
            'IV-FEATURE': '.entry-content',
        }
        selector = selectors[candidate['id']]
        node = soup.select_one(selector)
        if not node:
            # Acquisition failure is retained; never silently substitute another source.
            raise ValueError('Missing selector ' + selector + '; candidate classes: ' +
                             str([n.get('class') for n in soup.find_all('article')][:8]))
        if candidate['id'] == 'IV-TECHNICAL':
            title = node.find('h1').get_text(' ', strip=True)
            for toc in node.select('section#contents,nav.contents,div.contents,section#references'):
                toc.decompose()
        if candidate['id'] == 'IV-BUSINESS':
            title = soup.select('h1')[-1].get_text(' ', strip=True)
        if candidate['id'] == 'IV-NEWS':
            node = BeautifulSoup(''.join(str(n) for n in soup.select('.field-intro,.field-text-with-media')), 'html.parser')
        for remove in node.select('script,style,nav,aside,figure,figcaption,table,pre,footer,.references,.reference-list'):
            remove.decompose()
        for remove in node.select('.contents,.toc,.article-block__citation'):
            remove.decompose()
        paragraphs = []
        for part in node.select('h2,h3,h4,p,li'):
            if part.find_parent(['p', 'li']): continue
            text = part.get_text(' ', strip=True)
            if part.name.startswith('h') and text.lower().strip('¶ #') in {'references', 'references & resources', 'endnotes', 'footnotes',
                    'cite this work', 'reuse this work freely', 'author contributions', 'funding', 'copyright'}:
                break
            if text.startswith('Principal contributor:'): break
            if text: paragraphs.append(text)
        metadata['authors'] = [n.get('content') for n in soup.select('meta[name="author"],meta[name="citation_author"]')]
        metadata['publication_dates'] = [n.get('content') for n in soup.select('meta[property="article:published_time"],meta[name="citation_publication_date"],meta[name="DC.date.issued"]')]
        metadata['visible_times'] = [n.get_text(' ', strip=True) for n in soup.select('time')][:5]
        metadata['license_statement'] = next((n.get_text(' ', strip=True) for n in soup.select('p')
             if 'Creative Commons' in n.get_text() or 'CC0-1.0' in n.get_text()), None)
    text = title + '\n\n' + '\n\n'.join(paragraphs)
    return {**candidate, **metadata, 'title': title, 'source_text': text,
            'prose_words': len(re.findall(r"\b[\w]+(?:['’-][\w]+)*\b", text)),
            'retrieved_utc': datetime.now(timezone.utc).isoformat(), 'http_final_url': response.url,
            'http_sha256': sha256(raw).hexdigest(), 'source_sha256': sha256(text.encode()).hexdigest(),
            'extraction_selector': selector, 'partition': 'prospective_external_non_tuning_pilot',
            'independent_annotation_status': 'pending', 'profile': 'general'}


if __name__ == '__main__':
    verify()
    if (OUT / 'corpus.json').exists(): raise RuntimeError('Corpus exists; refusing to overwrite acquisition')
    def safe(candidate):
        try:
            data = fetch(candidate)
            write('acquisition-' + candidate['id'] + '.json', data)
            print(candidate['id'], data['prose_words'], data['title'], flush=True)
            return data
        except Exception as error:
            data = {**candidate, 'acquisition_error': str(error), 'exception': type(error).__name__}
            write('acquisition-' + candidate['id'] + '.json', data)
            print(candidate['id'], type(error).__name__, str(error)[:300], flush=True)
            return data
    results = list(ThreadPoolExecutor(max_workers=4).map(safe, CANDIDATES))
    write('acquisition-attempt-' + datetime.now(timezone.utc).strftime('%H%M%S') + '.json', results)
