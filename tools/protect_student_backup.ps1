param([Parameter(Mandatory=$true)][string]$Directory,[ValidateSet('database.dump','database.sql')][string]$ArchiveName='database.dump')
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Security
$resolvedBackup=(Resolve-Path -LiteralPath $Directory).Path
$allowedBackupRoot=Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'LightSummarizer\Backups'
if (-not $resolvedBackup.StartsWith($allowedBackupRoot+'\',[StringComparison]::OrdinalIgnoreCase) -or
    (Split-Path -Leaf $resolvedBackup) -notmatch '\Astudent-access-(?:mysql-)?\d{8}T\d{6}Z\z' -or
    -not (Get-Acl -LiteralPath $resolvedBackup).AreAccessRulesProtected) {
    throw 'A protected, scoped private backup directory is required.'
}
$encryptedOutputs=@()
foreach ($backupName in @($ArchiveName,'baseline-private.json')) {
    $sourceBackup=Join-Path $resolvedBackup $backupName
    $protectedBackup=$sourceBackup+'.dpapi'
    if (Test-Path -LiteralPath $protectedBackup) { throw 'Refusing to overwrite an encrypted backup.' }
    $plainBackupBytes=[IO.File]::ReadAllBytes($sourceBackup)
    $encryptedBackupBytes=[Security.Cryptography.ProtectedData]::Protect($plainBackupBytes,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser)
    $backupStream=[IO.File]::Open($protectedBackup,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
    try { $backupStream.Write($encryptedBackupBytes,0,$encryptedBackupBytes.Length) } finally { $backupStream.Dispose() }
    $verifiedBackupBytes=[Security.Cryptography.ProtectedData]::Unprotect([IO.File]::ReadAllBytes($protectedBackup),$null,[Security.Cryptography.DataProtectionScope]::CurrentUser)
    $backupHasher=[Security.Cryptography.SHA256]::Create()
    try {
        if ([Convert]::ToBase64String($backupHasher.ComputeHash($plainBackupBytes)) -ne [Convert]::ToBase64String($backupHasher.ComputeHash($verifiedBackupBytes))) { throw 'Encrypted backup round-trip mismatch.' }
    } finally { $backupHasher.Dispose() }
    [Array]::Clear($plainBackupBytes,0,$plainBackupBytes.Length)
    [Array]::Clear($verifiedBackupBytes,0,$verifiedBackupBytes.Length)
    Remove-Item -LiteralPath $sourceBackup -ErrorAction Stop
    $encryptedOutputs+=[pscustomobject]@{file=(Split-Path -Leaf $protectedBackup);sha256=(Get-FileHash -LiteralPath $protectedBackup -Algorithm SHA256).Hash.ToLower();round_trip_verified=$true}
}
# Exact temporary credentials only, after the restore container has been removed.
foreach ($temporaryBackupName in @('pgpass.conf','pg_service.conf','restore-password','mysql-client.cnf','restore-client.cnf')) {
    $temporaryBackupPath=Join-Path $resolvedBackup $temporaryBackupName
    if (Test-Path -LiteralPath $temporaryBackupPath) { Remove-Item -LiteralPath $temporaryBackupPath -ErrorAction Stop }
}
[pscustomobject]@{encryption='Windows DPAPI CurrentUser';encrypted=$encryptedOutputs;plaintext_archives_removed=$true;temporary_credentials_removed=$true;private_acl_protected=$true} | ConvertTo-Json -Depth 4 -Compress
