# Sidebar navigation redesign QA

This suite exercises the existing PHP application through local Apache/PHP, Microsoft Edge/Playwright, and the existing Python NLP worker. It adds no application dependencies.

It creates a fresh synthetic MySQL database and restricted database user for each run. The synthetic account cannot access the application database. Sessions, uploads, logs, and worker output are placed in a private temporary directory outside Apache.

From the repository root, run:

```powershell
.venv-nlp\Scripts\python.exe scripts\qa\test_sidebar_redesign.py setup
.venv-nlp\Scripts\python.exe scripts\qa\test_sidebar_redesign.py start
.venv-nlp\Scripts\python.exe scripts\qa\test_sidebar_redesign.py browser
.venv-nlp\Scripts\python.exe scripts\qa\test_sidebar_redesign.py stop
```

`browser` writes individual assertions to `browser.json` and captures after screenshots. The checked-in before screenshots were captured before this redesign. `stop` terminates only QA-owned Apache/NLP processes, verifies the application-row snapshot, copies `integrity.json`, removes the isolated schemas and database user, and deletes the private QA configuration. If unrelated live database activity prevents a preservation assertion, teardown still completes and `stop` returns a nonzero verification status.

The final run recorded **57 passed, 0 failed**. It checks account identity and roles, permission-aware destinations, active and hash-deep-link state, public-route exclusion, collapse persistence, light/dark viewport geometry from 360px through 1440px, mobile drawer focus and background isolation, profile-menu keyboard behavior, theme persistence, logout confirmation, actual text/Nutshell generation, and browser-script errors.
