$projectDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location -LiteralPath $projectDirectory

# 1) Already set in the calling environment — use as-is

# 2) Try a local env file (config.local.env) that persists the key across runs
$localEnvFile = Join-Path $projectDirectory 'config.local.env'
if ([string]::IsNullOrWhiteSpace($env:DORMITORY_TOTP_ENCRYPTION_KEY)) {
    if (Test-Path -LiteralPath $localEnvFile) {
        $savedKey = (Get-Content -LiteralPath $localEnvFile -Raw).Trim()
        if (-not [string]::IsNullOrWhiteSpace($savedKey)) {
            $env:DORMITORY_TOTP_ENCRYPTION_KEY = $savedKey
            Write-Host 'TOTP key loaded from config.local.env'
        }
    }
}

# 3) Auto-generate a brand-new key and save it (first-time setup)
if ([string]::IsNullOrWhiteSpace($env:DORMITORY_TOTP_ENCRYPTION_KEY)) {
    $randomBytes = New-Object byte[] 32
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($randomBytes)
    $newKey = [Convert]::ToBase64String($randomBytes)
    $env:DORMITORY_TOTP_ENCRYPTION_KEY = $newKey
    Set-Content -LiteralPath $localEnvFile -Value $newKey -NoNewline
    Write-Host "New TOTP encryption key generated and saved to config.local.env"
    Write-Host "BACKUP THIS KEY: $newKey"
}

try {
    $keyBytes = [Convert]::FromBase64String($env:DORMITORY_TOTP_ENCRYPTION_KEY)
} catch {
    throw 'DORMITORY_TOTP_ENCRYPTION_KEY must be a Base64-encoded 32-byte key.'
}
if ($keyBytes.Length -ne 32) {
    throw 'DORMITORY_TOTP_ENCRYPTION_KEY must be a Base64-encoded 32-byte key.'
}
Write-Host 'Start MySQL in XAMPP, then open http://localhost:8080 — press Ctrl+C to stop.'
$uploadDirectory = Join-Path $projectDirectory 'storage\upload-tmp'
New-Item -ItemType Directory -Force -Path $uploadDirectory | Out-Null
& 'C:\xampp\php\php.exe' -d "upload_tmp_dir=$uploadDirectory" -S localhost:8080 router.php
