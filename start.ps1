$projectDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location -LiteralPath $projectDirectory
if ([string]::IsNullOrWhiteSpace($env:DORMITORY_TOTP_ENCRYPTION_KEY)) {
    $apacheConfigPath = 'C:\xampp\apache\conf\httpd.conf'
    if (-not (Test-Path -LiteralPath $apacheConfigPath)) {
        throw 'DORMITORY_TOTP_ENCRYPTION_KEY is missing and the XAMPP Apache config was not found.'
    }
    $apacheConfig = Get-Content -LiteralPath $apacheConfigPath -Raw
    $keySetting = [regex]::Match($apacheConfig, '(?m)^\s*SetEnv\s+DORMITORY_TOTP_ENCRYPTION_KEY\s+"([A-Za-z0-9+/]{43}=)"\s*$')
    if (-not $keySetting.Success) {
        throw 'Configure DORMITORY_TOTP_ENCRYPTION_KEY in the XAMPP Apache config before starting the system.'
    }
    $env:DORMITORY_TOTP_ENCRYPTION_KEY = $keySetting.Groups[1].Value
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
