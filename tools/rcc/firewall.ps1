param(
    [Parameter(Mandatory = $true)][string]$WebServerIp,
    [int]$SoapPort = 64989,
    [string]$GamePorts = "53640-53740"
)

Get-NetFirewallRule -DisplayName "Finobe RCC*" -ErrorAction SilentlyContinue | Remove-NetFirewallRule

New-NetFirewallRule -DisplayName "Finobe RCC SOAP (web server only)" -Direction Inbound -Protocol TCP `
    -LocalPort $SoapPort -RemoteAddress $WebServerIp -Action Allow | Out-Null
New-NetFirewallRule -DisplayName "Finobe RCC SOAP (block everyone else)" -Direction Inbound -Protocol TCP `
    -LocalPort $SoapPort -Action Block | Out-Null
New-NetFirewallRule -DisplayName "Finobe RCC game servers" -Direction Inbound -Protocol UDP `
    -LocalPort $GamePorts -Action Allow | Out-Null

Write-Host "SOAP $SoapPort allowed only from $WebServerIp; UDP $GamePorts open for players."
