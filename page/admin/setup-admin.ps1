$ErrorActionPreference = 'Stop'

$pageDirectory = Split-Path -Parent $PSScriptRoot
$php = 'C:\xampp\php\php.exe'
$apacheSettings = Join-Path $pageDirectory '.htaccess'

if (-not (Test-Path -LiteralPath $php -PathType Leaf)) {
    throw "XAMPP PHP was not found at $php. Update the PHP path in this script if XAMPP is installed elsewhere."
}
if (Test-Path -LiteralPath $apacheSettings) {
    throw "Refusing to overwrite $apacheSettings. Add the two ASSETS_ADMIN SetEnv lines to the existing file instead."
}

$securePassword = Read-Host 'Choose an admin password (12 or more characters)' -AsSecureString
$password = [System.Net.NetworkCredential]::new('', $securePassword).Password
if ($password.Length -lt 12) {
    Remove-Variable password, securePassword -ErrorAction SilentlyContinue
    throw 'Choose a password with at least 12 characters, then run the setup helper again.'
}

$secureConfirmation = Read-Host 'Confirm the admin password' -AsSecureString
$confirmation = [System.Net.NetworkCredential]::new('', $secureConfirmation).Password
if ($password -cne $confirmation) {
    Remove-Variable password, confirmation, securePassword, secureConfirmation -ErrorAction SilentlyContinue
    throw 'The passwords did not match. Run the setup helper again.'
}

$env:ASSETS_ADMIN_SETUP_PASSWORD = $password
try {
    $hash = (& $php -r 'echo password_hash(getenv($argv[1]), PASSWORD_DEFAULT);' 'ASSETS_ADMIN_SETUP_PASSWORD')
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($hash)) {
        throw 'PHP could not generate the admin password hash.'
    }

    $content = "SetEnv ASSETS_ADMIN_USERNAME kradmin`r`nSetEnv ASSETS_ADMIN_PASSWORD_HASH `"$hash`"`r`n"
    [System.IO.File]::WriteAllText($apacheSettings, $content, [System.Text.Encoding]::ASCII)
    Write-Output 'Admin sign-in is configured. Sign in with username kradmin and the password you chose.'
    Write-Output 'The hash is stored in page\.htaccess and is not displayed.'
}
finally {
    Remove-Item Env:ASSETS_ADMIN_SETUP_PASSWORD -ErrorAction SilentlyContinue
    Remove-Variable password, confirmation, hash, securePassword, secureConfirmation -ErrorAction SilentlyContinue
}
