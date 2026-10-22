$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$monitorScript = Join-Path $PSScriptRoot 'monitor_nlp_worker.ps1'
$powershellExe = (Get-Command powershell.exe -ErrorAction Stop).Source
$taskName = 'LIGHT NLP Worker Monitor'
$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$actionArgs = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "{0}"' -f $monitorScript

$action = New-ScheduledTaskAction -Execute $powershellExe -Argument $actionArgs -WorkingDirectory $repoRoot
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $currentUser
$principal = New-ScheduledTaskPrincipal -UserId $currentUser -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
Start-ScheduledTask -TaskName $taskName
Write-Output "Installed '$taskName' for $currentUser with limited privileges. It checks worker health every 30 seconds."
