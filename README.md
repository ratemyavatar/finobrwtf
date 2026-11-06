# Finobe 2012 client

The 2012 ROBLOX client (RobloxApp.exe **0.65.0.596**, an unprotected build) rebranded for Finobe.
There is no public 2012 RCC, so the same client also hosts the game servers.

```
Client2012/                    the client (FinobeLauncher installs it for players, see below)
  RobloxApp.exe                already patched by tools/client2012/patch2012.py
  FinobeLaunch2012.ps1, install.bat  old stand-alone finobe2012: launcher, not needed any more
tools/client2012/patch2012.py  re-applies every patch to a clean 0.65.0.596 RobloxApp.exe
tools/gameserver2012/host2012.ps1  runs on the game server machine, starts/stops 2012 servers for the site
```

## Publishing it (on the web server)

1. Put the site's signing key in it (the committed exe has the stock key, so join scripts would be rejected):

       python3 tools/client2012/patch2012.py Client2012 --key /path/to/site/storage/rbx/files/privateKey1024.pem

2. Package it for the launcher (from the website checkout):

       python3 tools/client/package_client.py /path/to/Client2012 --year 2012

   and set the printed `FINOBE_CLIENT_VERSION_2012=version-...` in the site's `.env`.

## Players

Same as 2016: press Play on a 2012 place. FinobeLauncher (`finobe-launcher://...+clientversion:2012`)
downloads this build into `%LOCALAPPDATA%\Finobe\Versions\2012\<version>`, waits for a server through
PlaceLauncher and starts `RobloxApp.exe -Script "loadfile('http://assetgame.finobe.wtf/game/join.ashx?...')()"`.
`loadfile` checks the site's signature on the join script before running it.

## Game server machine (Windows)

```
powershell -ExecutionPolicy Bypass -File tools\gameserver2012\host2012.ps1 -ClientDir C:\Finobe\Client2012 -AccessKey <FINOBE_CLOUD_AUTHORIZATION> -Port 64990 -Domain finobe.wtf
```

and on the website set `FINOBE_HOST2012_URL=http://<that machine>:64990`. Open the UDP ports the
servers use (the site picks them like it does for RCC) in the firewall.

When a 2012 place needs a server the site POSTs `/start {jobId, scriptUrl}`; host2012 starts
`RobloxApp.exe -Script "loadfile('<gameserver2012.ashx?token=...>')()"` minimized. That script
(`storage/rbx/files/scripts/gameserver2012.lua` on the website) loads the place, starts the
NetworkServer and reports to `/api/gameserver/{register,alive,verify,visit,update,shutdown}` like the
2016 RCC script. The site POSTs `/stop` when the server shuts down.

## What patch2012.py changes

Same-length byte edits only (nothing moves):

* `roblox.com` → `finobe.wtf` everywhere except the place-file XML namespace and the certificate
* window title / player-facing text ROBLOX → Finobe
* the script-signing public key (default: the Finobe key the 2016 client has; `--key privateKey1024.pem` for another)
* `AppSettings.xml` BaseUrl
* code (see `CODE` in the script):
  * `game:HttpGet`/`HttpPost` security level 5 → 3, command-line scripts run at identity 5
  * the join script may set `Player.userId` and `Player.Name` (needed for `PlayerConnect(<userId>, ...)`)
  * server side: don't kick on the client's SysStats / hack-flag / "rocky" tamper reports (the patched
    client trips them)
  * server side: the physics sender wrote a 32-bit timestamp that every reader reads as 64 bits, which
    made the client drop the connection right after spawning ("packet 27: BitStream >> RakNet::Time
    failed"); it now writes 64 bits

Tested under Wine: a client-hosted server loads a place, a second client joins with a real user id,
spawns, stays connected, and the site endpoints (register, alive, verify, visit, update) are hit.
