param([switch]$Quiet)

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$envFile = Join-Path $repoRoot '.env'
$engineDirectory = Join-Path $repoRoot 'python-engine'
$daemonScript = Join-Path $engineDirectory 'worker_daemon.py'
$storageDirectory = Join-Path $repoRoot 'storage'
$tokenPath = Join-Path $storageDirectory 'worker.token'
$logDirectory = Join-Path $storageDirectory 'logs'
$temporaryDirectory = Join-Path $storageDirectory 'tmp'

function Get-ProjectEnvValue([string]$Name, [string]$DefaultValue = '') {
    if (-not (Test-Path -LiteralPath $envFile)) { return $DefaultValue }
    foreach ($line in Get-Content -LiteralPath $envFile) {
        if ($line -match ('^\s*' + [regex]::Escape($Name) + '\s*=\s*(.*?)\s*$')) {
            $value = $Matches[1].Trim()
            if ($value.Length -ge 2 -and (($value[0] -eq '"' -and $value[-1] -eq '"') -or ($value[0] -eq "'" -and $value[-1] -eq "'"))) {
                return $value.Substring(1, $value.Length - 2)
            }
            return $value
        }
    }
    return $DefaultValue
}

$pythonSetting = Get-ProjectEnvValue 'LOCAL_PYTHON_BIN'
$pythonPath = ''
if ($pythonSetting) {
    if ([System.IO.Path]::IsPathRooted($pythonSetting)) {
        $pythonPath = $pythonSetting
    } else {
        $projectPythonPath = Join-Path $repoRoot $pythonSetting
        if (Test-Path -LiteralPath $projectPythonPath -PathType Leaf) {
            $pythonPath = $projectPythonPath
        } else {
            $pythonCommand = Get-Command -Name $pythonSetting -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($pythonCommand) { $pythonPath = $pythonCommand.Source }
        }
    }
}
if (-not $pythonPath) {
    $venvPython = Join-Path $repoRoot '.venv-nlp\Scripts\python.exe'
    if (Test-Path -LiteralPath $venvPython -PathType Leaf) {
        $pythonPath = $venvPython
    } else {
        $pythonCommand = Get-Command -Name python -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($pythonCommand) { $pythonPath = $pythonCommand.Source }
    }
}
$portSetting = if ($env:PYTHON_WORKER_PORT) { $env:PYTHON_WORKER_PORT } else { Get-ProjectEnvValue 'PYTHON_WORKER_PORT' '5005' }
$deadlineSetting = if ($env:LOCAL_PYTHON_TIMEOUT) { $env:LOCAL_PYTHON_TIMEOUT } else { Get-ProjectEnvValue 'LOCAL_PYTHON_TIMEOUT' '180' }
$port = 5005
$deadline = 180
if (-not [int]::TryParse([string]$portSetting, [ref]$port) -or $port -lt 1 -or $port -gt 65535) {
    throw 'PYTHON_WORKER_PORT must be a valid TCP port.'
}
if (-not [int]::TryParse([string]$deadlineSetting, [ref]$deadline)) { $deadline = 180 }
$deadline = [Math]::Max(30, [Math]::Min(300, $deadline))
$projectSecret = Get-ProjectEnvValue 'PYTHON_WORKER_SECRET'
$workerSecret = if ($env:PYTHON_WORKER_SECRET) { $env:PYTHON_WORKER_SECRET.Trim() } elseif ($projectSecret) { $projectSecret.Trim() } else { '' }

if (-not (Test-Path -LiteralPath $pythonPath -PathType Leaf)) {
    throw "Configured local Python executable was not found: $pythonPath"
}
if (-not (Test-Path -LiteralPath $daemonScript -PathType Leaf)) {
    throw "NLP worker daemon script was not found: $daemonScript"
}
if (-not $workerSecret -and (-not (Test-Path -LiteralPath $tokenPath -PathType Leaf) -or (Get-Item -LiteralPath $tokenPath).Length -eq 0)) {
    throw 'The authenticated worker token file is missing or empty.'
}
New-Item -ItemType Directory -Force -Path $logDirectory, $temporaryDirectory | Out-Null

$token = if ($workerSecret) { $workerSecret } else { (Get-Content -LiteralPath $tokenPath -Raw).Trim() }
$headers = @{ 'X-Worker-Token' = $token }
$healthUrl = "http://127.0.0.1:$port/health"
$selfTestUrl = "http://127.0.0.1:$port/self-test"

function Get-NlpWorkerHealth {
    try {
        return Invoke-RestMethod -Uri $healthUrl -Headers $headers -TimeoutSec 2
    } catch {
        return $null
    }
}

$existingHealth = Get-NlpWorkerHealth
if ($existingHealth -and $existingHealth.status -eq 'ok' -and $existingHealth.protocol -eq 3 -and
    $existingHealth.supervised -eq $true -and $existingHealth.execution_deadline_seconds -ge $deadline) {
    if (-not $Quiet) { Write-Output "LIGHT NLP supervisor is already healthy on 127.0.0.1:$port." }
    return
}

$probe = New-Object System.Net.Sockets.TcpClient
try {
    $connect = $probe.BeginConnect('127.0.0.1', $port, $null, $null)
    if ($connect.AsyncWaitHandle.WaitOne(250)) {
        try { $probe.EndConnect($connect) } catch {}
        if ($probe.Connected) {
            & (Join-Path $PSScriptRoot 'stop_stale_nlp_workers.ps1') -Port $port | Out-Null
        }
    }
} finally {
    $probe.Close()
}

$stdoutPath = Join-Path $logDirectory 'nlp-worker.log'
$stderrPath = Join-Path $logDirectory 'nlp-worker-error.log'
$pidPath = Join-Path $temporaryDirectory 'nlp-worker.pid'
$arguments = '"{0}" --port {1} --deadline {2}' -f $daemonScript, $port, $deadline
$previousWorkerSecret = $env:PYTHON_WORKER_SECRET
if ($workerSecret) { $env:PYTHON_WORKER_SECRET = $workerSecret }
try {
    $process = Start-Process -FilePath $pythonPath -ArgumentList $arguments -WorkingDirectory $engineDirectory -WindowStyle Hidden -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath -PassThru
} finally {
    if ($null -eq $previousWorkerSecret) {
        Remove-Item Env:\PYTHON_WORKER_SECRET -ErrorAction SilentlyContinue
    } else {
        $env:PYTHON_WORKER_SECRET = $previousWorkerSecret
    }
}

$ready = $false
for ($attempt = 0; $attempt -lt 40; $attempt++) {
    Start-Sleep -Milliseconds 500
    $health = Get-NlpWorkerHealth
    if ($health -and $health.status -eq 'ok' -and $health.protocol -eq 3 -and
        $health.supervised -eq $true -and $health.execution_deadline_seconds -ge $deadline) {
        $ready = $true
        break
    }
    if ($process.HasExited) { break }
}

if (-not $ready) {
    $details = if (Test-Path -LiteralPath $stderrPath) { (Get-Content -LiteralPath $stderrPath -Tail 20) -join [Environment]::NewLine } else { '' }
    throw "LIGHT NLP supervisor failed to become healthy. Review storage\logs\nlp-worker-error.log. $details"
}

# Verify that this account can actually create the supervised child process.
# A healthy HTTP listener alone does not catch Windows child-process ACL failures.
try {
    $selfTest = Invoke-RestMethod -Method Post -Uri $selfTestUrl -Headers $headers `
        -ContentType 'application/json' -Body '{}' -TimeoutSec ($deadline + 5)
    if ($selfTest.status -ne 'ok' -or $selfTest.worker -ne 'local_cli') {
        throw 'The supervised Python child returned an unexpected self-test response.'
    }
} catch {
    try {
        Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:$port/shutdown" -Headers $headers `
            -ContentType 'application/json' -Body '{}' -TimeoutSec 3 | Out-Null
    } catch {}
    throw "LIGHT NLP supervisor started but failed its child-process self-test: $($_.Exception.Message)"
}

Set-Content -LiteralPath $pidPath -Value ([string]$process.Id) -Encoding ASCII
if (-not $Quiet) {
    Write-Output "LIGHT NLP supervisor is healthy on 127.0.0.1:$port (deadline $($deadline)s, PID $($process.Id))."
}
