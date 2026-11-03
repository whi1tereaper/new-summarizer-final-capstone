# Build clean release archive
$ErrorActionPreference = "Stop"
$root = $PSScriptRoot + "\.."
Set-Location $root

$zipPath = Join-Path $root "depended_production_bundle.zip"
if (Test-Path $zipPath) { Remove-Item -Force $zipPath }

$tempDir = Join-Path $env:TEMP "depended_release_temp"
if (Test-Path $tempDir) { Remove-Item -Recurse -Force $tempDir }
New-Item -ItemType Directory -Path $tempDir | Out-Null

$includeDirs = @("app", "database", "python-engine", "vendor", "scripts", "tools")
foreach ($dir in $includeDirs) {
    Copy-Item -Recurse (Join-Path $root $dir) -Destination $tempDir
}

$includeFiles = @(".htaccess", "index.php", ".env.example", "composer.json", "composer.lock")
foreach ($file in $includeFiles) {
    Copy-Item (Join-Path $root $file) -Destination $tempDir
}

# Clean development test debris
Get-ChildItem -Path (Join-Path $tempDir "app\uploads") -File | Remove-Item -Force -ErrorAction SilentlyContinue
Remove-Item -Recurse -Force (Join-Path $tempDir "python-engine\output") -ErrorAction SilentlyContinue
Remove-Item -Recurse -Force (Join-Path $tempDir "python-engine\.tmp") -ErrorAction SilentlyContinue
Get-ChildItem -Path $tempDir -Recurse -Include __pycache__,*.pyc | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

Compress-Archive -Path (Join-Path $tempDir "*") -DestinationPath $zipPath -Force
Remove-Item -Recurse -Force $tempDir

$sizeMB = [math]::Round(((Get-Item $zipPath).Length / 1MB), 2)
Write-Host "Created $zipPath ($sizeMB MB)"

