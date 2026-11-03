# Summarization workspace redesign QA

These artifacts exercise the existing PHP application through real local Apache/PHP 8.3 and the existing Python NLP worker. Browser automation uses the already installed Playwright package and Microsoft Edge. No application dependency was added.

All HTTP requests, including the before screenshots, use a newly created synthetic MySQL database and a database user denied access to the application database. Uploads, sessions, logs, and worker output use a private temporary directory outside Apache. The existing isolation harness hashes application rows before and after testing. No migrations run against the application database.

Run from the repository root with the existing NLP environment:

```powershell
.venv-nlp/Scripts/python.exe scripts/qa/test_workspace_redesign.py setup
.venv-nlp/Scripts/python.exe scripts/qa/test_workspace_redesign.py start
.venv-nlp/Scripts/python.exe scripts/qa/test_workspace_redesign.py browser
.venv-nlp/Scripts/python.exe scripts/qa/test_workspace_redesign.py stop
```

The `before` action captures the current source as a baseline when run before applying the redesign. Existing `before-*.png` files were captured before this implementation. The `browser` action captures the redesigned source as `after-*.png` and writes `browser.json` with individual assertions. The `stop` action stops only the spawned QA processes, removes the isolated schemas/user, and writes `integrity.json`. Credentials stay in the private temporary directory and are removed after cleanup.

Authenticated navigation checks use PHP sessions for seeded synthetic QA accounts. They test role visibility and the logout confirmation, not password login or second-factor delivery. Accessibility checks cover semantic labels and landmarks, live regions, source selection states, visible keyboard focus, and mobile menu Escape behavior; they do not replace a manual screen-reader audit.

The real generation cases use pasted text, a generated PDF, a generated DOCX, a public NASA article URL, and the inline Nutshell action. Successful responses are never mocked. A real restricted local URL request checks server rejection and draft retention. Additional browser checks cover both themes at 360, 390, 768, 1024, 1280, and 1440 pixels, configured upload/text limits, source exclusivity, settings mappings, and duplicate-submission prevention.

Final execution: 45 main browser checks and 12 supplementary checks passed. The supplementary action was run separately after the main suite and adds restored Focus/depth state, persistent invalid-file feedback, authorized admin navigation capture, and three real Nutshell generations to exhaustion. The three-use limit hides new-generation controls while preserving the last result and its copy action. The complete `browser` action now includes those supplementary checks; `supplementary` can rerun only that group.

`persistence.json` confirms the optional title and Focus text reached a completed stored summary, all four input types produced stored results, and actual Nutshell output used `nutshell-extractive-v2`. `contrast.json` records sampled text and control contrast in both themes; it is not a complete accessibility audit. `integrity.json` confirms original users, summaries, and feedback rows remained present and unchanged, and that tests used a restricted isolated database. It also records that unrelated live database activity occurred during the run; it does not claim the entire live database was frozen.

Owned QA Apache/NLP processes were stopped, isolated schemas and their database user were removed, and the private database credential file was deleted after the final run. The temporary preview URL is no longer serving.
