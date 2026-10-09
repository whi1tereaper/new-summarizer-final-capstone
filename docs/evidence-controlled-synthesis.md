# Evidence-controlled synthesis

## Default behavior

The language-model stage is off unless the operator configures it and a user checks **Use evidence-controlled AI synthesis** for that request. The existing ranking, coverage, and extractive summary path remains the default and fallback. The setting is unavailable for Structured Sections, which remain extractive.

The model receives selected source sentences, their section labels, and literal number/date/qualifier/negation/comparison markers. It does not receive the complete source document. Every proposed sentence must cite one or more selected evidence IDs. The candidate is rejected as a whole if it has malformed citations, weak content-word overlap, unsupported literal fact spans, missing literal uncertainty/comparison markers, invalid sentence boundaries, duplicate sentences, or exceeds configured word limits.

These checks are lexical heuristics. They can reject faithful paraphrases and can fail to detect unsupported claims. They do not prove semantic entailment or factual accuracy. No accuracy percentage is reported. Rejected, unavailable, or timed-out model requests return the existing extractive summary and expose the fallback state.

## Operator configuration

Add values to the private `.env` file. The checked-in `.env.example` documents every setting. The default endpoint is local Ollama; `SUMMARIZER_LLM_ENABLED` remains `false` until deliberately changed.

```dotenv
SUMMARIZER_LLM_ENABLED=true
SUMMARIZER_LLM_PROVIDER=openai_compatible
SUMMARIZER_LLM_BASE_URL=http://127.0.0.1:11434/v1
SUMMARIZER_LLM_MODEL=your-installed-model
SUMMARIZER_LLM_API_KEY=
SUMMARIZER_LLM_ALLOW_REMOTE=false
SUMMARIZER_LLM_TIMEOUT_SECONDS=30
SUMMARIZER_LLM_MAX_OUTPUT_TOKENS=1200
SUMMARIZER_LLM_MAX_TOKEN_FIELD=max_tokens
```

For a remote provider, set an HTTPS base URL and `SUMMARIZER_LLM_ALLOW_REMOTE=true`. Store credentials only in `.env` or the process secret store, never in PHP templates or browser settings. The request UI tells users that selected passages go to the configured endpoint; users must opt in each time. An external endpoint can receive document content off-device and may charge for requests. Evaluation runs that select `hybrid_llm` make provider calls for each item and may incur additional charges.

The endpoint must implement the OpenAI-compatible `/chat/completions` request/response shape and JSON mode. [Ollama documents its local chat-completions compatibility, including JSON mode and `max_tokens`](https://docs.ollama.com/api/openai-compatibility). Set `SUMMARIZER_LLM_MAX_TOKEN_FIELD=max_completion_tokens` only for compatible providers that require that field; the default `max_tokens` works with the local Ollama-compatible endpoint.

## Evaluation

`hybrid_llm` is a separate evaluation system from the extractive `proposed` system and from the Lead-N, TF-IDF, and TextRank baselines. It uses the proposed system's stored word count as the comparison budget when available. If synthesis is unavailable or rejected, the hybrid evaluation item fails instead of silently scoring the extractive fallback under the model label. Model name, endpoint scope, output-token setting, and code fingerprint are recorded without storing API keys.

Evaluation metrics remain corpus- and annotation-dependent. No benchmark, human-review result, factuality score, or quality improvement is available from this implementation alone.
