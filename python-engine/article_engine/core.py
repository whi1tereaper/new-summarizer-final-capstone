"""Source-grounded central-message seed, separate from cost-normalized detail gain."""
from functools import lru_cache
import json
from pathlib import Path
import re
import numpy as np

CORE_DEFAULT = {"enabled": True, "lead": .4, "title": .3, "centroid": .2, "claim": .1, 'mmr_lambda': 1.0}
CLAIM = re.compile(r"\b(?:argue\w*|conclud\w*|announc\w*|found|main finding|central|primary|purpose|aim\w*|should|introduced|demonstrat\w*)\b", re.I)


@lru_cache(maxsize=1)
def core_parameters():
    path = Path(__file__).with_name('refinement_parameters.json')
    if path.is_file():
        artifact = json.loads(path.read_text(encoding='utf8'))
        value = artifact['core_parameters']
        if any(not isinstance(value.get(k), (int, float)) or not 0 <= value[k] <= 1
               for k in ['lead', 'title', 'centroid', 'claim']):
            raise ValueError('Invalid core-selection calibration.')
        lam = artifact.get('selected_lambda',1.0)
        if not isinstance(lam,(int,float)) or not 0 <= lam <= 1 or not isinstance(value.get('enabled'),bool):
            raise ValueError('Invalid core-selection calibration.')
        return dict(value, mmr_lambda=lam)
    return dict(CORE_DEFAULT)


def core_scores(records, analysis, title='', *, config=None):
    config = config or core_parameters()
    title_vector = analysis.vectorizer.transform([title])
    title_score = np.asarray((analysis.matrix @ title_vector.T).toarray()).ravel()
    title_score /= max(float(title_score.max(initial=0)), 1e-12)
    lead = 1 / (1 + np.arange(len(records)))
    claim = np.array([bool(CLAIM.search(s.text)) for s in records], dtype=float)
    return (config['lead'] * lead + config['title'] * title_score
            + config['centroid'] * analysis.relevance + config['claim'] * claim)
