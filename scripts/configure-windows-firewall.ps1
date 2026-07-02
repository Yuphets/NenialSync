#Requires -RunAsAdministrator

$ErrorActionPreference = 'Stop'
$displayName = 'Nenial Local POS (TCP 8080)'
$existingRule = Get-NetFirewallRule -DisplayName $displayName -ErrorAction SilentlyContinue

if ($existingRule) {
    Set-NetFirewallRule -DisplayName $displayName -Enabled True -Profile Private `
        -Direction Inbound -Action Allow
    Set-NetFirewallAddressFilter -AssociatedNetFirewallRule $existingRule -RemoteAddress LocalSubnet
    Set-NetFirewallPortFilter -AssociatedNetFirewallRule $existingRule -Protocol TCP -LocalPort 8080
}
else {
    New-NetFirewallRule -DisplayName $displayName -Direction Inbound -Action Allow `
        -Protocol TCP -LocalPort 8080 -Profile Private -RemoteAddress LocalSubnet | Out-Null
}

Write-Output 'Nenial POS is allowed on TCP 8080 for the Private local subnet only.'
