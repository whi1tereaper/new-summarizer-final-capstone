$ErrorActionPreference = 'Continue'
$taskName = 'LIGHT NLP Worker Monitor'
Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$envFile = Join-Path $repoRoot '.env'
$tokenPath = Join-Path $repoRoot 'storage\worker.token'
$port = 5005
$configuredSecret = if ($env:PYTHON_WORKER_SECRET) { $env:PYTHON_WORKER_SECRET.Trim() } else { '' }
if (Test-Path -LiteralPath $envFile) {
    $match = Select-String -Path $envFile -Pattern '^\s*PYTHON_WORKER_PORT\s*=\s*(\d+)\s*$' | Select-Object -First 1
    if ($match) { [void][int]::TryParse($match.Matches[0].Groups[1].Value, [ref]$port) }
    $secretMatch = Select-String -Path $envFile -Pattern '^\s*PYTHON_WORKER_SECRET\s*=\s*(.*?)\s*$' | Select-Object -First 1
    if (-not $configuredSecret -and $secretMatch) {
        $configuredSecret = $secretMatch.Matches[0].Groups[1].Value.Trim().Trim('"').Trim("'")
    }
}
if ((($configuredSecret -ne '') -or (Test-Path -LiteralPath $tokenPath)) -and $port -ge 1 -and $port -le 65535) {
    $token = if ($configuredSecret -ne '') { $configuredSecret } else { (Get-Content -LiteralPath $tokenPath -Raw).Trim() }
    try {
        Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:$port/shutdown" -Headers @{ 'X-Worker-Token' = $token } -ContentType 'application/json' -Body '{}' -TimeoutSec 3 | Out-Null
    } catch {}
}
& (Join-Path $PSScriptRoot 'stop_stale_nlp_workers.ps1') -Port $port
Write-Output "Removed '$taskName' and requested an authenticated worker shutdown if one was running."
