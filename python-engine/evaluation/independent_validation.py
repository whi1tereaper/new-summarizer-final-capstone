"""Prospective Article 2.1 validation artifacts; never tunes production code."""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
from hashlib import sha256
import json
import os
from pathlib import Path
import sys

ENGINE = Path(__file__).resolve().parents[1]
REPO = ENGINE.parent
OUT = Path(os.environ.get('LIGHT_IV_OUTPUT_DIR', str(REPO / 'docs/research/independent-validation'))).resolve()
sys.path.insert(0, str(ENGINE))
sys.path = [p for p in sys.path if p != str(Path(__file__).resolve().parent)]


def digest(path):
    return sha256(path.read_bytes()).hexdigest()


def write(name, data):
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / name).write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n', encoding='utf8')


def protected_files():
    files = set()
    for folder in ['article_engine', 'summarizer_core']:
        files.update(p for p in (ENGINE / folder).rglob('*') if p.suffix in {'.py', '.json'})
    files.update(ENGINE / name for name in ['local_cli.py', 'article_detector.py', 'summary_engine_router.py'])
    files.update(p for p in (REPO / 'app').rglob('*') if p.suffix in {'.php', '.css', '.js'})
    files.update(REPO / name for name in ['Dockerfile', 'render.yaml', '.env.example'])
    files.update(ENGINE.glob('requirements*.txt'))
    return sorted(p for p in files if p.is_file())


def audit():
    """Record historical source identity, not credential-containing configuration."""
    paths = list((ENGINE / 'evaluation/corpus').glob('*.json'))
    paths += list((ENGINE / 'tests/fixtures').glob('*evaluation*.json'))
    paths += [OUT.parent / name for name in ['external-source-candidates.json',
              'coverage-results.json', 'refinement-results.json', 'article-engine-results.json']]
    inventories = []
    def walk(value, found):
        if isinstance(value, dict):
            if ('source_text' in value or 'source_url' in value or 'url' in value) and value.get('id'):
                found.append({k: value[k] for k in ['id', 'title', 'source_url', 'url', 'category', 'provenance'] if k in value})
            for child in value.values(): walk(child, found)
        elif isinstance(value, list):
            for child in value: walk(child, found)
    for path in paths:
        if not path.exists(): continue
        found = []
        walk(json.loads(path.read_text(encoding='utf8')), found)
        unique = {json.dumps(row, sort_keys=True): row for row in found}
        inventories.append({'path': str(path.relative_to(REPO)), 'sha256': digest(path), 'sources': list(unique.values())})
    from evaluation.refinement_calibration import calibration_documents
    from evaluation.coverage_corpus import development, heldout
    from evaluation.article_engine_experiment import quality_documents
    groups = {'v2_development': development(), 'v2_synthetic_once_heldout_now_inspected': heldout(),
              'v21_development': calibration_documents(), 'historical_quality': quality_documents()}
    for name, docs in groups.items():
        inventories.append({'code_group': name, 'sources': [{k: d[k] for k in ['id', 'title', 'source_url', 'category', 'provenance'] if k in d} for d in docs]})
    write('prior-data-audit.json', {'all_excluded_from_new_evaluation': True, 'inventories': inventories,
          'notes': ['Source-first synthetic labels are implementer-authored, not independent review.',
                    'The calibration script name does not change its role: it tunes v2.1 on 12 development articles.',
                    'All existing pilots, detector fixtures and candidate nominations are inspected/excluded.']})


def freeze():
    if (OUT / 'freeze.json').exists():
        raise RuntimeError('Refusing to replace an existing freeze')
    import platform
    from importlib.metadata import version, PackageNotFoundError
    from article_engine import ENGINE_VERSION
    versions = {}
    for name in ['numpy', 'scipy', 'scikit-learn', 'nltk', 'spacy', 'en-core-web-sm', 'sumy', 'pypdf', 'python-docx', 'torch', 'sentence-transformers']:
        try: versions[name] = version(name)
        except PackageNotFoundError: versions[name] = None
    data = {'engine': ENGINE_VERSION, 'utc': datetime.now(timezone.utc).isoformat(),
            'python': sys.version, 'platform': platform.platform(), 'packages': versions,
            'protected_sha256': {p.relative_to(REPO).as_posix(): digest(p) for p in protected_files()},
            'protocol_sha256': digest(OUT / 'protocol.md'),
            'routing_policy': 'disabled globally; preserve existing server-owned opt-in only',
            'independent_human_review': 'pending; no independent reviewer available in this session'}
    write('freeze.json', data)
    audit()
    print('Frozen', ENGINE_VERSION, len(data['protected_sha256']), 'files')


def verify():
    data = json.loads((OUT / 'freeze.json').read_text(encoding='utf8'))
    changes = [name for name, expected in data['protected_sha256'].items()
               if not (REPO / name).exists() or digest(REPO / name) != expected]
    # Shared-workspace UI work is outside the evaluation and must not be reverted.
    unrelated = [name for name in changes if '/assets/' in name or name in {
        'app/public/analytics.php', 'app/public/partials/site-nav.php'}]
    relevant = [name for name in changes if name not in unrelated]
    if relevant: raise RuntimeError('Frozen evaluation-relevant files changed: ' + ', '.join(relevant))
    if digest(OUT / 'protocol.md') != data['protocol_sha256']: raise RuntimeError('Registered protocol changed')
    return {'protected_files': len(data['protected_sha256']), 'evaluation_relevant_changes': relevant,
            'concurrent_unrelated_ui_changes_preserved': unrelated, 'protocol_unchanged': True}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['freeze', 'verify'])
    args = parser.parse_args()
    if args.command == 'freeze': freeze()
    else: print(json.dumps(verify()))
