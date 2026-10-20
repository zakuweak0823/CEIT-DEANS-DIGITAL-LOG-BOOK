$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot

function Find-XamppRoot {
    $directory = Get-Item -LiteralPath $projectRoot
    while ($null -ne $directory) {
        $opensslPath = Join-Path $directory.FullName 'apache\bin\openssl.exe'
        $mysqlStartPath = Join-Path $directory.FullName 'mysql_start.bat'
        if ((Test-Path -LiteralPath $opensslPath) -and (Test-Path -LiteralPath $mysqlStartPath)) {
            return $directory.FullName
        }
        $directory = $directory.Parent
    }
    throw 'Could not find XAMPP OpenSSL above this project folder. Put the project inside XAMPP''s htdocs folder.'
}

function Invoke-OpenSsl {
    param(
        [string]$OpenSslPath,
        [string[]]$Arguments,
        [string]$FailureMessage
    )

    & $OpenSslPath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw $FailureMessage
    }
}

$xamppRoot = Find-XamppRoot
$openSslPath = Join-Path $xamppRoot 'apache\bin\openssl.exe'
$certificateDirectory = Join-Path $xamppRoot 'apache\conf\ssl.crt'
$keyDirectory = Join-Path $xamppRoot 'apache\conf\ssl.key'
$caCertificatePath = Join-Path $certificateDirectory 'ceit-logbook-ca.crt'
$caKeyPath = Join-Path $keyDirectory 'ceit-logbook-ca.key'
$serverCertificatePath = Join-Path $certificateDirectory 'ceit-logbook-lan.crt'
$serverKeyPath = Join-Path $keyDirectory 'ceit-logbook-lan.key'
$publicCaPath = Join-Path $projectRoot 'ceit-logbook-local-ca.crt'

$privateAddresses = @(
    Get-NetIPAddress -AddressFamily IPv4 -AddressState Preferred |
        Where-Object {
            $_.IPAddress -match '^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)'
        }
)
$defaultRoute = Get-NetRoute -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue |
    Sort-Object RouteMetric |
    Select-Object -First 1
$lanAddresses = @()
if ($null -ne $defaultRoute) {
    $lanAddresses = @(
        $privateAddresses |
            Where-Object { $_.InterfaceIndex -eq $defaultRoute.InterfaceIndex } |
            Select-Object -ExpandProperty IPAddress -Unique
    )
}
if ($lanAddresses.Count -eq 0) {
    $lanAddresses = @($privateAddresses | Select-Object -ExpandProperty IPAddress -Unique)
}
if ($lanAddresses.Count -eq 0) {
    throw 'No Private-network IPv4 address was found. Connect this computer to the office Wi-Fi/network and try again.'
}

New-Item -ItemType Directory -Path $certificateDirectory, $keyDirectory -Force | Out-Null
$hasCaCertificate = Test-Path -LiteralPath $caCertificatePath
$hasCaKey = Test-Path -LiteralPath $caKeyPath
if (-not $hasCaCertificate -and $hasCaKey) {
    throw 'The local CA private key exists without its certificate. Restore the matching certificate from a secure backup or create a new local CA and reinstall it on trusted phones.'
}

$temporaryDirectory = Join-Path ([System.IO.Path]::GetTempPath()) ('ceit-mobile-camera-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporaryDirectory | Out-Null

try {
    if (-not $hasCaCertificate) {
        $caConfigPath = Join-Path $temporaryDirectory 'ca.cnf'
        @'
[req]
prompt = no
distinguished_name = ca_dn
x509_extensions = ca_ext

[ca_dn]
CN = CEIT Logbook Local Development CA

[ca_ext]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
'@ | Set-Content -LiteralPath $caConfigPath -Encoding ASCII

        Invoke-OpenSsl `
            -OpenSslPath $openSslPath `
            -Arguments @(
                'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-sha256', '-days', '3650',
                '-keyout', $caKeyPath, '-out', $caCertificatePath, '-config', $caConfigPath
            ) `
            -FailureMessage 'Could not create the local development CA. Check the XAMPP Apache OpenSSL installation.'
        $hasCaCertificate = $true
        $hasCaKey = $true
        Write-Host 'Created a new host-specific local development CA.'
    }

    $existingCertificateValid = $false
    if ((Test-Path -LiteralPath $serverCertificatePath) -and (Test-Path -LiteralPath $serverKeyPath)) {
        $verificationOutput = & $openSslPath verify -CAfile $caCertificatePath $serverCertificatePath 2>&1 | Out-String
        $verificationExitCode = $LASTEXITCODE
        $sanOutput = & $openSslPath x509 -in $serverCertificatePath -noout -ext subjectAltName 2>&1 | Out-String
        $sanExitCode = $LASTEXITCODE
        $certificatePublicKey = & $openSslPath x509 -in $serverCertificatePath -pubkey -noout 2>&1 | Out-String
        $certificatePublicKeyExitCode = $LASTEXITCODE
        $serverPublicKey = & $openSslPath pkey -in $serverKeyPath -pubout 2>&1 | Out-String
        $serverPublicKeyExitCode = $LASTEXITCODE
        $existingCertificateValid = $verificationExitCode -eq 0 -and
            $sanExitCode -eq 0 -and
            $certificatePublicKeyExitCode -eq 0 -and
            $serverPublicKeyExitCode -eq 0 -and
            $certificatePublicKey.Trim() -eq $serverPublicKey.Trim()
        foreach ($address in $lanAddresses) {
            if ($sanOutput -notmatch "IP Address:\s*$([regex]::Escape($address))") {
                $existingCertificateValid = $false
            }
        }
    }

    if (-not $existingCertificateValid -and $hasCaCertificate -and -not $hasCaKey) {
        $replacementCaConfigPath = Join-Path $temporaryDirectory 'ca.cnf'
        @'
[req]
prompt = no
distinguished_name = ca_dn
x509_extensions = ca_ext

[ca_dn]
CN = CEIT Logbook Local Development CA

[ca_ext]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
'@ | Set-Content -LiteralPath $replacementCaConfigPath -Encoding ASCII

        $replacementCaCertificatePath = Join-Path $temporaryDirectory 'ceit-logbook-ca.crt'
        $replacementCaKeyPath = Join-Path $temporaryDirectory 'ceit-logbook-ca.key'
        Invoke-OpenSsl `
            -OpenSslPath $openSslPath `
            -Arguments @(
                'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-sha256', '-days', '3650',
                '-keyout', $replacementCaKeyPath, '-out', $replacementCaCertificatePath,
                '-config', $replacementCaConfigPath
            ) `
            -FailureMessage 'Could not replace the unavailable local CA key. Check the XAMPP Apache OpenSSL installation.'
        $replacementCaPublicKey = & $openSslPath x509 -in $replacementCaCertificatePath -pubkey -noout 2>&1 | Out-String
        $replacementCaCertificateExitCode = $LASTEXITCODE
        $replacementCaPrivateKey = & $openSslPath pkey -in $replacementCaKeyPath -pubout 2>&1 | Out-String
        $replacementCaKeyExitCode = $LASTEXITCODE
        if ($replacementCaCertificateExitCode -ne 0 -or
            $replacementCaKeyExitCode -ne 0 -or
            $replacementCaPublicKey.Trim() -ne $replacementCaPrivateKey.Trim()) {
            throw 'The replacement local CA certificate and private key did not match.'
        }

        $previousCaCertificatePath = Join-Path $certificateDirectory (
            'ceit-logbook-ca.previous-' + (Get-Date -Format 'yyyyMMddHHmmss') + '.crt'
        )
        Copy-Item -LiteralPath $caCertificatePath -Destination $previousCaCertificatePath
        Move-Item -LiteralPath $replacementCaCertificatePath -Destination $caCertificatePath -Force
        Move-Item -LiteralPath $replacementCaKeyPath -Destination $caKeyPath -Force
        $hasCaKey = $true
        Write-Warning 'The previous local CA private key was unavailable, so a new local CA was created. Re-download this computer''s certificate in Admin Settings and install/trust it on phones again.'
    }

    if (-not $existingCertificateValid) {
        if (-not $hasCaKey) {
            throw 'The local server certificate is missing or does not match this computer, and the local CA private key is unavailable. Restore the CA private key from a secure backup or create a new local CA and reinstall it on trusted phones.'
        }
        $altNames = @('DNS.1 = localhost')
        for ($index = 0; $index -lt $lanAddresses.Count; $index++) {
            $altNames += "IP.$($index + 1) = $($lanAddresses[$index])"
        }
        $altNamesText = $altNames -join "`r`n"
        $serverConfigPath = Join-Path $temporaryDirectory 'server.cnf'
        @"
[req]
prompt = no
distinguished_name = server_dn
req_extensions = server_req_ext

[server_dn]
CN = $($lanAddresses[0])

[server_req_ext]
subjectAltName = @alt_names

[server_cert]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
subjectAltName = @alt_names

[alt_names]
$altNamesText
"@ | Set-Content -LiteralPath $serverConfigPath -Encoding ASCII

        $requestPath = Join-Path $temporaryDirectory 'server.csr'
        $temporaryServerKeyPath = Join-Path $temporaryDirectory 'ceit-logbook-lan.key'
        $temporaryServerCertificatePath = Join-Path $temporaryDirectory 'ceit-logbook-lan.crt'
        Invoke-OpenSsl `
            -OpenSslPath $openSslPath `
            -Arguments @(
                'req', '-new', '-newkey', 'rsa:2048', '-nodes', '-sha256',
                '-keyout', $temporaryServerKeyPath, '-out', $requestPath, '-config', $serverConfigPath
            ) `
            -FailureMessage 'Could not create the LAN server certificate request.'
        Invoke-OpenSsl `
            -OpenSslPath $openSslPath `
            -Arguments @(
                'x509', '-req', '-in', $requestPath, '-CA', $caCertificatePath, '-CAkey', $caKeyPath,
                '-CAcreateserial', '-out', $temporaryServerCertificatePath, '-days', '825', '-sha256',
                '-extfile', $serverConfigPath, '-extensions', 'server_cert'
            ) `
            -FailureMessage 'Could not sign the LAN server certificate with the local CA.'
        Invoke-OpenSsl `
            -OpenSslPath $openSslPath `
            -Arguments @('verify', '-CAfile', $caCertificatePath, '-verify_ip', $lanAddresses[0], $temporaryServerCertificatePath) `
            -FailureMessage 'The generated LAN certificate did not verify against the local CA.'

        Move-Item -LiteralPath $temporaryServerCertificatePath -Destination $serverCertificatePath -Force
        Move-Item -LiteralPath $temporaryServerKeyPath -Destination $serverKeyPath -Force
        Write-Host 'Created a LAN certificate for:'
        $lanAddresses | ForEach-Object { Write-Host "  https://${_}:5173/" }
    }

    Copy-Item -LiteralPath $caCertificatePath -Destination $publicCaPath -Force
    Write-Host ''
    Write-Host 'The mobile app will start over HTTPS. To enable phone camera access, open Admin Settings and download the mobile camera certificate, then install and trust it on the phone.'
} finally {
    Remove-Item -LiteralPath $temporaryDirectory -Recurse -Force
}
