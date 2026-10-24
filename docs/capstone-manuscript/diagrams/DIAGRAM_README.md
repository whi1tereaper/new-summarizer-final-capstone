# Original diagram source register

These original diagrams derive from audited source and current schema, not AccredX or the researcher draft. Mermaid is editable diagram source; SVG preserves vector geometry; PNG is Word-compatible. The paradigm is PROPOSED DRAFT. DFD/use-case views are SOURCE-SUPPORTED descriptions and do not imply every flow passed functional testing.

## Logical stores and balance

D1 groups users, password_resets, user_tokens, guest_sessions, rate_limits and PHP session state. PHP sessions are runtime/file state, not a SQL table. Administrator OTP and reset verification remain under D1 in both levels. D2 includes summaries, summary_artifacts, summary_jobs and temporary source files. D3 groups documents, document_summaries, nutshell_generations and generated audio. D4 groups feedback, feedback_comments, landing_page_sessions and landing_page_events. D5 is administration audit via admin_audit_logs. D6 groups the 13 actual evaluation_* tables in the data dictionary.

admin_security_challenges exists but is legacy, not current email-OTP storage. schema_migrations records migration execution, not an active end-user flow. Both remain in the complete ERD/data dictionary. Names summary_records and feedback_records from the older draft are not used.

Level 2 expands processes 1.0 and 2.0. Actors, providers and logical stores retain their identities. No store connects directly to an external actor. Local NLP/TTS workers are inside the application boundary; website retrieval, configured email and translation providers are external. Optional external synthesis is disabled in inspected configuration and excluded; enabling it requires a disclosed external-provider flow.

## Roles and print use

Guest is a session context; user/admin are stored roles. Evaluator is an assignment to an existing account. Guest access excludes standalone Nutshell and personal history. Diagram eligibility is scoped to inspected route guards; separate authorization defects remain applicable.

Use five dfd-level1-* focused panels and two dfd-level2-summary-* panels when full overviews are too dense at the narrow manuscript width. Four use-cases-* role panels use ellipses and undirected associations. Full SVG overviews remain available for zooming. Render metadata records intrinsic sizes and estimated printed font sizes; small estimates identify supporting overviews, not appropriate printed figures.

Root evidence: ArticleController, ArticleService, SummaryJobService, AuthController, SourceResolver, HistoryService, FeedbackService, EvaluationService, LocalPythonBridge and local_cli.py. The audit reports record limitations. ERD coverage separately verifies all 31 entities and 35 declared foreign keys.
