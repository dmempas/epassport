# Generate a self-signed SSL certificate for E-PASSPORT on your LAN IP.
# Run in PowerShell AS ADMINISTRATOR:
#   cd C:\xampp\htdocs\E-PASSPORT\setup
#   .\generate-lan-ssl.ps1
# Optional: .\generate-lan-ssl.ps1 -LanIp 192.168.1.103

param(
    [string]$LanIp = ""
)

$ErrorActionPreference = "Stop"

$xamppRoot = "C:\xampp"
$openssl   = Join-Path $xamppRoot "apache\bin\openssl.exe"
$sslCrtDir = Join-Path $xamppRoot "apache\conf\ssl.crt"
$sslKeyDir = Join-Path $xamppRoot "apache\conf\ssl.key"
$setupDir  = $PSScriptRoot
$cnfTemplate = Join-Path $setupDir "openssl-lan.cnf"
$cnfTemp   = Join-Path $setupDir "openssl-lan.generated.cnf"

if (-not (Test-Path $openssl)) {
    Write-Host "OpenSSL not found at $openssl" -ForegroundColor Red
    Write-Host "Edit `$xamppRoot if XAMPP is installed elsewhere."
    exit 1
}

if (-not $LanIp) {
    $LanIp = (
        Get-NetIPAddress -AddressFamily IPv4 |
        Where-Object {
            $_.IPAddress -notlike "127.*" -and
            $_.PrefixOrigin -ne "WellKnown"
        } |
        Select-Object -First 1 -ExpandProperty IPAddress
    )
}

if (-not $LanIp) {
    Write-Host "Could not detect LAN IP. Run: .\generate-lan-ssl.ps1 -LanIp 192.168.1.103" -ForegroundColor Yellow
    exit 1
}

Write-Host "Using LAN IP: $LanIp" -ForegroundColor Cyan

$cnfContent = Get-Content $cnfTemplate -Raw
$cnfContent = $cnfContent.Replace("LAN_IP_PLACEHOLDER", $LanIp)
Set-Content -Path $cnfTemp -Value $cnfContent -Encoding ASCII

$keyFile = Join-Path $sslKeyDir "server.key"
$crtFile = Join-Path $sslCrtDir "server.crt"
$keyBackup = Join-Path $sslKeyDir "server.key.bak"
$crtBackup = Join-Path $sslCrtDir "server.crt.bak"

if (Test-Path $keyFile) { Copy-Item $keyFile $keyBackup -Force }
if (Test-Path $crtFile) { Copy-Item $crtFile $crtBackup -Force }

& $openssl req -x509 -nodes -days 825 -newkey rsa:2048 `
    -keyout $keyFile `
    -out $crtFile `
    -config $cnfTemp `
    -extensions v3_req

if ($LASTEXITCODE -ne 0) {
    Write-Host "Certificate generation failed." -ForegroundColor Red
    exit 1
}

Remove-Item $cnfTemp -ErrorAction SilentlyContinue

Write-Host ""
Write-Host "SUCCESS - certificate installed for:" -ForegroundColor Green
Write-Host "  https://localhost/E-PASSPORT/"
Write-Host "  https://${LanIp}/E-PASSPORT/"
Write-Host ""
Write-Host "NEXT STEPS:" -ForegroundColor Yellow
Write-Host "1. Open XAMPP Control Panel -> Stop Apache -> Start Apache"
Write-Host "2. On your phone open:"
Write-Host "   https://${LanIp}/E-PASSPORT/student/dashboard.php"
Write-Host "3. You will still see a privacy warning (self-signed cert)."
Write-Host "   Tap ADVANCED -> Proceed to ${LanIp} (unsafe)"
Write-Host "4. Then allow Location when the app asks."
Write-Host ""
Write-Host "Backups of old cert: server.crt.bak and server.key.bak" -ForegroundColor DarkGray
