# Mathematical formulation matching the article implementation

## Current v2.1 central-message stage

Before the coverage loop, `core.py:core_scores` computes

```text
t_i = cosine(X_i, title_vector) / max_j cosine(X_j, title_vector)
p_i = 1 / (i + 1)
h_i = 1 if the source sentence contains a registered central-claim cue, else 0
c_i = alpha_p p_i + alpha_t t_i + alpha_r r_i + alpha_h h_i
seed = feasible context-closed bundle whose anchor maximizes c_i
```

Development selected `(alpha_p, alpha_t, alpha_r, alpha_h)=(0,.6,.2,.2)`. A missing/zero-overlap title contributes zero; no undisclosed renormalization occurs. The source title is a source-document hint or a short non-sentence source heading, not an arbitrary display-title instruction. Ties prefer earlier evidence indices. Every seed word and sentence counts against the existing limits. If no bundle fits, the original typed failure path remains available; no clause is clipped to force a main idea.

This seed is not divided by word cost. Then optimize incremental F exactly as described below with the seed's concepts already covered. Focus skips the unrelated global seed and uses source-supported query-conditioned coverage. No fixed proportion of the word budget is reserved. The combined pipeline is a heuristic two-stage policy, not a new proof of global maximum coverage.

The current development-selected residual MMR value is `.95`; `refinement_parameters.json` and its twelve trials document the choice. The v2 coverage-group masses and word-cost exponent remain unchanged. The independent explicit-config/no-MMR ablation uses lambda=1. There was no diagnostic output difference across all 90 cases, despite the development gain; no universal MMR benefit is claimed.

WordNet expansion uses at most two senses and two upward hierarchy steps plus relational/derivational links. Accept a semantic link only when the original source/query anchor appears in the other's expansion; an arbitrary shared ancestor is insufficient. Require support for topical modifiers even when a generic scope word is present. These are bounded retrieval heuristics, not entailment checks or confidence probabilities. The exact code is `focus.py`; the remaining TF-IDF/LSA/coverage equations below continue to apply.

Let V be the source sentences, C the extracted concept universe, and X the L2-normalized sentence-by-feature TF-IDF matrix. Stopwords and optional lemmas affect X only; source evidence is immutable. Without TF-IDF, the controlled ablation substitutes L2-normalized term counts.

## Relevance and concepts

Document relevance is `r_i = (X_i · mean(X)) / max_j(X_j · mean(X))`. Sparse truncated SVD uses `X ≈ U_k Σ_k V_k^T`, `k=min(4,n−1,d−1)`, and a fixed random state. The latent sentence coordinates are `Z=U_kΣ_k`. Identical input rows do not require SVD.

For lexical feature k, coverage is `a_ik = X_ik / max_j X_jk`. For latent component k, `a_ik = |Z_ik| / max_j |Z_jk|`. Section, role, quantity, entity, and qualification concepts have binary source membership. Thus every `a_ik` is in [0,1]. Lexical importance is the feature's document centroid weight; latent importance is its squared singular value; structural importance is uniform within its group. Contextual quantities and qualifications use `.5 + .5 r_i`, taking the maximum across supporting sentences; relevant entities use `r_i`.

Each group's measured mass is divided among its concepts in proportion to those nonnegative importance values. Normalize the resulting weights across nonempty groups so `w_k≥0` and `Σw_k=1`. An unavailable entity group, for example, contributes no imaginary concepts.

The exact objective implemented by `coverage_value` and the selector is:

```text
F(S) = Σ[k∈C] w_k max[i∈S] a_ik,     max over an empty set = 0
Δ(B|S) = F(S ∪ B) − F(S)
```

F is bounded, monotone and submodular on sentences for fixed weights. Those mathematical properties do not establish that the extracted concepts equal human importance. Dependency bundles, budget constraints and optional MMR also prevent importing a textbook approximation guarantee for the complete implementation.

## Budgets and optional MMR

For a context-closed bundle B, charge only newly added words:

```text
cost(B|S) = Σ[i∈B\S] number_of_TOKEN_matches(source_sentence_i)
sum of selected words ≤ requested budget
number of selected sentences ≤ the existing requested/depth limit
```

For the optional modular rank mixture, add `α/n_limit * Σ[i∈B\S] rank_i`, where rank is normalized TF-IDF/LSA relevance with a small analysis-mode cue. This addition is disabled when development selects `rank_mix=0`.

Let g(B) be that marginal gain, and g_max the maximum feasible gain at this step. Residual similarity R is the maximum TF-IDF cosine between a newly added sentence and a selected sentence; R is set to zero when B adds a previously uncovered protected quantity or qualification. The actual selection rule is:

```text
utility(B|S) = [λ g(B)/g_max − (1−λ) R(B,S)] / cost(B|S)^p
```

Ties prefer earlier source indices. Coverage-only sets λ=1 and α=0. A separate experiment uses the best nontrivial development MMR lambda. Normalizing marginal gains at each step does not alter the coverage-only ordering, but the MMR extension is not itself presented as maximizing a fixed submodular objective.

## Focus-conditioned coverage

Stem-normalized query similarity uses both direct TF-IDF overlap and cosine in the existing LSA subspace. Their equal mixture is an engineering choice, not a human-validated semantic confidence. Whole-query support gates prevent an incidental word from answering a largely absent topic. Generic focus scopes also match existing claim roles.

Let q_i≥0 be the focus relevance and A the allowed sentence set. Weight concept k by `w'_k ∝ w_k max_i(a_ik q_i)`, normalize, and optimize the same F with those weights. Ordinary zero-overlap sentences can enter only as required context or as sufficiently supported latent-theme evidence in a directly supported paragraph. No supported candidates yields the explicit unsupported-focus status.

The tuned group masses, p, λ and α, complete development grid and decision rule are in `article_engine/coverage_parameters.json`. They are experimental estimates on engineering-authored data. Academic provenance and limits are documented in [the methodology](algorithm-improvement-methodology.md).
