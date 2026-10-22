$ErrorActionPreference = 'Continue'
$startScript = Join-Path $PSScriptRoot 'start_nlp_worker.ps1'
$logPath = Join-Path (Split-Path -Parent $PSScriptRoot) 'storage\logs\nlp-worker-monitor.log'

while ($true) {
    try {
        & $startScript -Quiet
    } catch {
        Add-Content -LiteralPath $logPath -Value "$(Get-Date -Format o) supervisor check failed: $($_.Exception.Message)"
    }
    Start-Sleep -Seconds 30
}
