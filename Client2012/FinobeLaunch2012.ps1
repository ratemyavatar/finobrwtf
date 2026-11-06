# Finobe 2012 launcher: opened for finobe2012:play/<placeId>/<ticket>[/<jobId>] links (install.bat registers it)
#  - asks PlaceLauncher.ashx for a server until one is ready, then starts RobloxApp.exe -Script "loadfile('<join script URL>')()"
#    (loadfile checks the site's signature on the script and strips it)
param([string]$Uri = "")

$ErrorActionPreference = "Stop"
$Domain = "finobe.wtf"
$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
Add-Type -AssemblyName System.Windows.Forms, System.Drawing

function Fail([string]$Message) {
    [System.Windows.Forms.MessageBox]::Show($Message, "Finobe", "OK", "Error") | Out-Null
    exit 1
}

if ($Uri -notmatch '^finobe2012:/*play/(\d+)/([A-Za-z0-9]+)(?:/([0-9A-Fa-f-]{36}))?/?$') {
    Fail "That isn't a Finobe 2012 game link."
}
$PlaceId = $Matches[1]; $Ticket = $Matches[2]; $JobId = $Matches[3]

$Exe = Join-Path $Here "RobloxApp.exe"
if (-not (Test-Path $Exe)) { Fail "RobloxApp.exe is missing from $Here. Download the 2012 client again." }

# small status window while the server starts
$Form = New-Object System.Windows.Forms.Form
$Form.Text = "Finobe"; $Form.Width = 340; $Form.Height = 120; $Form.StartPosition = "CenterScreen"
$Form.FormBorderStyle = "FixedDialog"; $Form.MaximizeBox = $false; $Form.MinimizeBox = $false; $Form.TopMost = $true
$Label = New-Object System.Windows.Forms.Label
$Label.Text = "Starting Finobe..."; $Label.AutoSize = $false; $Label.Dock = "Fill"; $Label.TextAlign = "MiddleCenter"
$Label.Font = New-Object System.Drawing.Font("Segoe UI", 10)
$Form.Controls.Add($Label)
$Form.Show(); [System.Windows.Forms.Application]::DoEvents()

$Request = if ($JobId) { "RequestGameJob&gameId=$JobId" } else { "RequestGame" }
$LauncherUrl = "https://assetgame.$Domain/game/placelauncher.ashx?request=$Request&placeId=$PlaceId&ticket=$Ticket"

$JoinUrl = $null
$Deadline = (Get-Date).AddSeconds(150)
while ((Get-Date) -lt $Deadline) {
    try {
        $r = Invoke-RestMethod -Uri $LauncherUrl -UseBasicParsing -TimeoutSec 20
    } catch {
        $Form.Close(); Fail "Couldn't reach Finobe. Check your internet connection and try again."
    }
    switch ([int]$r.status) {
        0 { $Label.Text = "Waiting for a server..." }
        1 { $Label.Text = "The server is starting..." }
        2 { $JoinUrl = [string]$r.joinScriptUrl; break }
        default {
            $Form.Close()
            Fail $(if ($r.message) { [string]$r.message } else { "Couldn't join this game." })
        }
    }
    if ($JoinUrl) { break }
    [System.Windows.Forms.Application]::DoEvents()
    Start-Sleep -Seconds 2
}
$Form.Close()

if (-not $JoinUrl -or $JoinUrl -notmatch "^https?://assetgame\.$([regex]::Escape($Domain))/game/join\.ashx\?") {
    Fail "The server took too long to start. Try again in a bit."
}

if ($JoinUrl -match "['\\`"\s]") { Fail "Bad join script URL." }
Start-Process -FilePath $Exe -WorkingDirectory $Here -ArgumentList @("-Script", "`"loadfile('$JoinUrl')()`"")
