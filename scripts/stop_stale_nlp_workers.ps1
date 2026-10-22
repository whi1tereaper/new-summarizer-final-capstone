param([int]$Port = 5005)

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$daemonScript = (Resolve-Path (Join-Path $repoRoot 'python-engine\worker_daemon.py')).Path
$venvPython = Join-Path $repoRoot '.venv-nlp\Scripts\python.exe'
$currentIdentity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
function Get-WorkerListenerIds {
    $ids = @()
    try {
        $ids += @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction Stop |
            Select-Object -ExpandProperty OwningProcess)
    } catch {}
    foreach ($line in (netstat -ano -p tcp)) {
        if ($line -match ('^\s*TCP\s+(?:127\.0\.0\.1|0\.0\.0\.0):' + $Port + '\s+\S+\s+LISTENING\s+(\d+)\s*$')) {
            $ids += [int]$Matches[1]
        }
    }
    return @($ids | Select-Object -Unique)
}

$listenerIds = @(Get-WorkerListenerIds)

foreach ($listenerId in $listenerIds) {
    $processInfo = Get-CimInstance Win32_Process -Filter "ProcessId=$listenerId" -ErrorAction SilentlyContinue
    if ($null -eq $processInfo) { continue }
    if ($null -eq $processInfo -or $processInfo.Name -notin @('python.exe', 'pythonw.exe')) {
        throw "Refusing to stop PID $listenerId because it is not a Python process."
    }
    $commandLine = [string]$processInfo.CommandLine
    $absoluteScriptMatch = -not [string]::IsNullOrWhiteSpace($commandLine) -and
        $commandLine.IndexOf($daemonScript, [StringComparison]::OrdinalIgnoreCase) -ge 0
    $relativeScriptMatch = $commandLine -match '(?i)(?:^|\s|")python-engine[\\/]worker_daemon\.py(?:\s|")' -and
        $commandLine -match ('(?i)\s--port\s+' + $Port + '(?:\s|$)')
    if (-not $absoluteScriptMatch -and $relativeScriptMatch) {
        $parentInfo = Get-CimInstance Win32_Process -Filter "ProcessId=$($processInfo.ParentProcessId)" -ErrorAction SilentlyContinue
        $relativeScriptMatch = $null -ne $parentInfo -and
            [string]::Equals([string]$parentInfo.ExecutablePath, $venvPython, [StringComparison]::OrdinalIgnoreCase)
    }
    if (-not $absoluteScriptMatch -and -not $relativeScriptMatch) {
        throw "Refusing to stop PID $listenerId because it is not running this project's worker_daemon.py."
    }

    $owner = Invoke-CimMethod -InputObject $processInfo -MethodName GetOwner -ErrorAction Stop
    $processOwner = if ($owner.ReturnValue -eq 0) { "$($owner.Domain)\$($owner.User)" } else { '' }
    if (-not [string]::Equals($processOwner, $currentIdentity, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to stop PID $listenerId because it belongs to $processOwner, not $currentIdentity."
    }

    $taskkill = Join-Path $env:SystemRoot 'System32\taskkill.exe'
    & $taskkill /F /T /PID $listenerId | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Could not stop stale NLP worker PID $listenerId. Run this script as the account that started it."
    }
    Write-Output "Stopped verified stale LIGHT NLP worker PID $listenerId."
}

for ($attempt = 0; $attempt -lt 40; $attempt++) {
    $remaining = @(Get-WorkerListenerIds)
    if ($remaining.Count -eq 0) {
        Remove-Item -LiteralPath (Join-Path (Split-Path -Parent $PSScriptRoot) 'storage\tmp\nlp-worker.pid') -ErrorAction SilentlyContinue
        Write-Output "No stale LIGHT NLP listener remains on port $Port."
        return
    }
    Start-Sleep -Milliseconds 250
}

throw "Port $Port is still occupied after stopping verified LIGHT NLP worker processes."
