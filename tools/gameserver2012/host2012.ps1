# Finobe 2012 game server host
#  - there's no 2012 RCC, so 2012 game servers are the 2012 client itself running gameserver2012.lua:
#    RobloxApp.exe -Script "loadfile('http://assetgame.<domain>/game/gameserver2012.ashx?token=...')()"
#  - the site (App\Services\Host2012) calls this to start and stop them:
#      POST /start {"jobId": "...", "scriptUrl": "..."}   POST /stop {"jobId": "..."}   GET /health
#    every request needs the AccessKey header = the site's FINOBE_CLOUD_AUTHORIZATION
#
#  Run in an admin PowerShell on the game server machine (the one in FINOBE_GAMESERVER_HOST):
#    powershell -ExecutionPolicy Bypass -File host2012.ps1 -ClientDir C:\Finobe\Client2012 -AccessKey <key>
#  Keep -Port private (firewall it to the web server), and open the game UDP ports (53640-53740) for RobloxApp.exe.
param(
    [Parameter(Mandatory = $true)][string]$ClientDir,
    [Parameter(Mandatory = $true)][string]$AccessKey,
    [int]$Port = 64990,
    [string]$Domain = "finobe.wtf"
)

$Exe = Join-Path $ClientDir "RobloxApp.exe"
if (-not (Test-Path $Exe)) { throw "No RobloxApp.exe in $ClientDir" }
$ScriptPrefix = "http://assetgame.$Domain/game/gameserver2012.ashx?token="
$Servers = @{}   # jobId -> Process

$Listener = New-Object System.Net.HttpListener
$Listener.Prefixes.Add("http://+:$Port/")
$Listener.Start()
Write-Host "host2012 listening on port $Port, client $Exe"

function Reply($Ctx, [int]$Code, [string]$Body) {
    $bytes = [Text.Encoding]::UTF8.GetBytes($Body)
    $Ctx.Response.StatusCode = $Code
    $Ctx.Response.ContentType = "application/json"
    $Ctx.Response.OutputStream.Write($bytes, 0, $bytes.Length)
    $Ctx.Response.Close()
}

while ($Listener.IsListening) {
    $ctx = $Listener.GetContext()
    try {
        # forget servers whose process has ended
        foreach ($id in @($Servers.Keys)) { if ($Servers[$id].HasExited) { $Servers.Remove($id) } }

        if ($ctx.Request.Headers["AccessKey"] -ne $AccessKey) { Reply $ctx 403 '{"error":"forbidden"}'; continue }

        $path = $ctx.Request.Url.AbsolutePath.TrimEnd("/")
        $body = @{}
        if ($ctx.Request.HasEntityBody) {
            $reader = New-Object IO.StreamReader($ctx.Request.InputStream)
            $body = $reader.ReadToEnd() | ConvertFrom-Json
        }

        if ($path -eq "/health") {
            Reply $ctx 200 (@{ running = $Servers.Count } | ConvertTo-Json -Compress)
        }
        elseif ($path -eq "/start" -and $ctx.Request.HttpMethod -eq "POST") {
            $jobId = [string]$body.jobId; $url = [string]$body.scriptUrl
            if ($jobId -notmatch '^[0-9A-Fa-f-]{36}$' -or -not $url.StartsWith($ScriptPrefix) -or $url -notmatch '^[\x21-\x7e]+$' -or $url -match "['`"\\]") {
                Reply $ctx 400 '{"error":"bad request"}'; continue
            }
            $p = Start-Process -FilePath $Exe -WorkingDirectory $ClientDir -ArgumentList @("-Script", "`"loadfile('$url')()`"") -WindowStyle Minimized -PassThru
            $Servers[$jobId] = $p
            Write-Host "started $jobId (pid $($p.Id))"
            Reply $ctx 200 (@{ pid = $p.Id } | ConvertTo-Json -Compress)
        }
        elseif ($path -eq "/stop" -and $ctx.Request.HttpMethod -eq "POST") {
            $jobId = [string]$body.jobId
            if ($Servers.ContainsKey($jobId)) {
                Stop-Process -Id $Servers[$jobId].Id -Force -ErrorAction SilentlyContinue
                $Servers.Remove($jobId)
                Write-Host "stopped $jobId"
            }
            Reply $ctx 200 '{"ok":true}'
        }
        else { Reply $ctx 404 '{"error":"not found"}' }
    } catch {
        Write-Warning $_
        try { Reply $ctx 500 '{"error":"internal"}' } catch {}
    }
}
