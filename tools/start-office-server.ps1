param(
    [switch]$ConfigureFirewall
)

$ErrorActionPreference = 'Stop'
$httpFirewallRuleName = 'CEIT Digital Logbook - Apache HTTP (Private)'
$httpsFirewallRuleName = 'CEIT Digital Logbook - Apache HTTPS (Private)'
$mobileFirewallRuleName = 'CEIT Digital Logbook - Mobile Vite (Private)'
$projectRoot = Split-Path -Parent $PSScriptRoot

function Find-XamppRoot {
    $directory = Get-Item -LiteralPath $projectRoot
    while ($null -ne $directory) {
        $apachePath = Join-Path $directory.FullName 'apache\bin\httpd.exe'
        $mysqlStartPath = Join-Path $directory.FullName 'mysql_start.bat'
        if ((Test-Path -LiteralPath $apachePath) -and (Test-Path -LiteralPath $mysqlStartPath)) {
            return $directory.FullName
        }
        $directory = $directory.Parent
    }
    throw 'Could not find XAMPP above this project folder. Put the project inside XAMPP''s htdocs folder.'
}

function Test-TcpPort {
    param(
        [string]$ComputerName,
        [int]$Port
    )

    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $client.Connect($ComputerName, $Port)
        return $true
    } catch {
        return $false
    } finally {
        $client.Dispose()
    }
}

function Test-FirewallRule {
    param(
        [string]$RuleName,
        [int]$Port,
        [string]$ApplicationPath,
        [switch]$MatchApplication
    )

    $rule = Get-NetFirewallRule -DisplayName $RuleName -ErrorAction SilentlyContinue
    if ($null -eq $rule) {
        return $false
    }

    foreach ($candidate in $rule) {
        if (-not (
            $candidate.Enabled -eq 'True' -and
            $candidate.Direction -eq 'Inbound' -and
            $candidate.Action -eq 'Allow' -and
            $candidate.Profile -eq 'Private'
        )) {
            continue
        }

        $applicationFilter = Get-NetFirewallApplicationFilter -AssociatedNetFirewallRule $candidate -ErrorAction SilentlyContinue
        $portFilter = Get-NetFirewallPortFilter -AssociatedNetFirewallRule $candidate -ErrorAction SilentlyContinue
        if (
            (-not $MatchApplication -or $applicationFilter.Program -eq $ApplicationPath) -and
            $portFilter.Protocol -eq 'TCP' -and
            $portFilter.LocalPort -contains [string]$Port
        ) {
            return $true
        }
    }

    return $false
}

$xamppRoot = Find-XamppRoot
$apachePath = Join-Path $xamppRoot 'apache\bin\httpd.exe'
$apacheStartPath = Join-Path $xamppRoot 'apache_start.bat'
$mysqlStartPath = Join-Path $xamppRoot 'mysql_start.bat'

if ($ConfigureFirewall) {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Firewall configuration must be run as Administrator.'
    }

    Get-NetFirewallRule -DisplayName $httpFirewallRuleName -ErrorAction SilentlyContinue |
        Remove-NetFirewallRule -ErrorAction SilentlyContinue
    Get-NetFirewallRule -DisplayName $httpsFirewallRuleName -ErrorAction SilentlyContinue |
        Remove-NetFirewallRule -ErrorAction SilentlyContinue
    Get-NetFirewallRule -DisplayName $mobileFirewallRuleName -ErrorAction SilentlyContinue |
        Remove-NetFirewallRule -ErrorAction SilentlyContinue
    New-NetFirewallRule `
        -DisplayName $httpFirewallRuleName `
        -Description 'Allow this XAMPP Apache server on TCP port 80 only on Private networks.' `
        -Direction Inbound `
        -Action Allow `
        -Protocol TCP `
        -LocalPort 80 `
        -Program $apachePath `
        -Profile Private | Out-Null
    New-NetFirewallRule `
        -DisplayName $httpsFirewallRuleName `
        -Description 'Allow this XAMPP Apache server on TCP port 443 only on Private networks.' `
        -Direction Inbound `
        -Action Allow `
        -Protocol TCP `
        -LocalPort 443 `
        -Program $apachePath `
        -Profile Private | Out-Null
    New-NetFirewallRule `
        -DisplayName $mobileFirewallRuleName `
        -Description 'Allow the mobile Vite development server on TCP port 5173 only on Private networks.' `
        -Direction Inbound `
        -Action Allow `
        -Protocol TCP `
        -LocalPort 5173 `
        -Profile Private | Out-Null
    exit 0
}

if (
    -not (Test-FirewallRule -RuleName $httpFirewallRuleName -Port 80 -ApplicationPath $apachePath -MatchApplication) -or
    -not (Test-FirewallRule -RuleName $httpsFirewallRuleName -Port 443 -ApplicationPath $apachePath -MatchApplication) -or
    -not (Test-FirewallRule -RuleName $mobileFirewallRuleName -Port 5173)
) {
    Write-Host 'Private-network-only firewall rules are needed for the office site and mobile app.'
    Write-Host 'Windows will ask for administrator approval. The rules allow Apache on ports 80 and 443, and the mobile app on port 5173, on Private networks only.'
    $scriptPath = $PSCommandPath
    $arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`" -ConfigureFirewall"
    try {
        Start-Process -FilePath 'powershell.exe' -ArgumentList $arguments -Verb RunAs -Wait
    } catch {
        throw 'Firewall setup was cancelled or failed. Approve the Windows prompt, then run this launcher again.'
    }

    if (
        -not (Test-FirewallRule -RuleName $httpFirewallRuleName -Port 80 -ApplicationPath $apachePath -MatchApplication) -or
        -not (Test-FirewallRule -RuleName $httpsFirewallRuleName -Port 443 -ApplicationPath $apachePath -MatchApplication) -or
        -not (Test-FirewallRule -RuleName $mobileFirewallRuleName -Port 5173)
    ) {
        throw 'The Private-network firewall rules were not created. Run the launcher again and approve the Windows prompt.'
    }
}

if (-not (Test-TcpPort -ComputerName '127.0.0.1' -Port 3306)) {
    Write-Host 'Starting XAMPP MySQL...'
    Start-Process -FilePath $mysqlStartPath -WorkingDirectory $xamppRoot
    $databaseReady = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        Start-Sleep -Seconds 1
        if (Test-TcpPort -ComputerName '127.0.0.1' -Port 3306) {
            $databaseReady = $true
            break
        }
    }
    if (-not $databaseReady) {
        throw 'MySQL did not start on port 3306. Check the XAMPP MySQL window for an error.'
    }
}

if (-not (Test-TcpPort -ComputerName '127.0.0.1' -Port 80)) {
    Write-Host 'Starting XAMPP Apache...'
    Start-Process -FilePath $apacheStartPath -WorkingDirectory $xamppRoot
    $webServerReady = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        Start-Sleep -Seconds 1
        if (Test-TcpPort -ComputerName '127.0.0.1' -Port 80) {
            $webServerReady = $true
            break
        }
    }
    if (-not $webServerReady) {
        throw 'Apache did not start on port 80. Check the XAMPP Apache window; another program may already be using that port.'
    }
}

$folderName = Split-Path -Leaf $projectRoot
$urlPath = [Uri]::EscapeDataString($folderName)
$localUrl = "http://localhost/$urlPath/"
$localHttpsUrl = "https://localhost/$urlPath/"
$lanAddresses = @(
    Get-NetIPAddress -AddressFamily IPv4 -AddressState Preferred |
        Where-Object {
            $_.IPAddress -notmatch '^(127\.|169\.254\.)' -and
            $_.IPAddress -match '^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)'
        } |
        Select-Object -ExpandProperty IPAddress -Unique
)

Write-Host ''
Write-Host 'CEIT Digital Logbook is ready.'
Write-Host "This computer: $localUrl"
if (Test-TcpPort -ComputerName '127.0.0.1' -Port 443) {
    Write-Host "This computer (HTTPS): $localHttpsUrl"
}
foreach ($address in $lanAddresses) {
    Write-Host "Other computers on this Private network: http://${address}/$urlPath/"
    if (Test-TcpPort -ComputerName '127.0.0.1' -Port 443) {
        Write-Host "Other computers (HTTPS): https://${address}/$urlPath/"
    }
    Write-Host "Mobile app (HTTPS): https://${address}:5173/ (start it with npm run dev:lan)"
}
if ($lanAddresses.Count -eq 0) {
    Write-Warning 'No Private-network IPv4 address was found. Connect this computer and the other device to the same trusted Wi-Fi/network.'
}

$publicProfiles = Get-NetConnectionProfile -ErrorAction SilentlyContinue |
    Where-Object { $_.NetworkCategory -eq 'Public' }
if ($publicProfiles) {
    Write-Warning 'A connected network is marked Public. The firewall rule intentionally blocks access on Public networks; mark only a trusted office/home network as Private in Windows Settings.'
}

Write-Host ''
Write-Host 'For another computer, open the matching network address above. Keep this host computer and XAMPP running.'
Write-Host 'This launcher does not expose the site to the public internet.'
Start-Process $localUrl
