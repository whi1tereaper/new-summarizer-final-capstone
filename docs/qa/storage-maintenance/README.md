# Storage & Maintenance QA

See [the integration report](../../storage-maintenance-report.md) for scope, results, limitations and rollback.

Run from the workspace with the existing environment:

```powershell
& '.venv-nlp/Scripts/python.exe' scripts/qa/test_storage_maintenance.py
```

The runner creates restricted synthetic QA schemas and a private runtime/session directory, verifies denial of production database access before mutation, and removes its databases and credentials afterward. It does not run live cleanup. Do not substitute production credentials or manually point its filesystem fixture at application storage.

`summary.json` is the final run; `attempt-*.json` retain earlier attempts. Browser screenshots contain synthetic fixtures only. Core suite wrappers overlap their nested assertion counts.
