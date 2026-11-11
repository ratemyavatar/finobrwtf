# Game servers & client security

Covers the 2012 join fix and the two client vulnerabilities (RakFail, and the "end of the
launcher" bytecode RCE) and exactly what was done about each.

## 1. 2012 games: fixing "Connecting to Server" with no join

**Symptom:** the 2012 bootstrapper shows *Starting the server* then *Connecting to Server* and never
joins (the window hangs on the sky with no error).

### What was wrong

A 2012 game server is the 2012 client itself running as a server (there is no public 2012 RCC):
`tools/gameserver2012/host2012.ps1` launches `RobloxApp.exe -Script <gameserver2012.ashx>` on the
game-server machine, it loads the place, calls `NetworkServer:Start(port)` and registers with the
site, and then the joining client is told to connect to `ip:port` over UDP (RakNet).

The address the client connects to is `servers.ip`, which comes from
`ClientVersions::gameserverHost('2012')`. The `clients.2012` config had **no** `gameserver_host`, so
it fell back to the generic `FINOBE_GAMESERVER_HOST` — the **2016 RCC machine**. If your 2012 host is
a different machine (or that address is wrong/closed for 2012), the client connects to a machine where
no 2012 server is listening, shows *Connecting to Server*, and never joins.

### The fix (config)

`config/finobe.php` now gives 2012 its own game-server address:

```php
'2012' => [
    ...
    // Public address 2012 players connect to for game UDP traffic.
    'gameserver_host' => env('FINOBE_GAMESERVER_HOST_2012', ''),
],
```

- **Same machine** runs the 2012 host and the 2016 game servers → leave `FINOBE_GAMESERVER_HOST_2012`
  unset. 2012 falls back to `FINOBE_GAMESERVER_HOST` (unchanged behaviour).
- **Separate 2012 machine** → set `FINOBE_GAMESERVER_HOST_2012` to **that machine's public address**
  (the one players reach), *not* the private `FINOBE_HOST2012_URL` control address.

### Operator checklist (do all of these)

1. **`FINOBE_HOST2012_URL`** points at `host2012.ps1` (e.g. `http://10.0.0.5:64990`), reachable from
   the web server. If unset, the client shows *2012 game servers are not set up yet*.
2. **`host2012.ps1` is running** on the game-server machine (admin PowerShell):
   `powershell -ExecutionPolicy Bypass -File host2012.ps1 -ClientDir C:\Finobe\Client2012 -AccessKey <FINOBE_CLOUD_AUTHORIZATION>`
3. **UDP ports open for `RobloxApp.exe`.** This is the most common cause of "no join": the inbound
   game UDP range (`FINOBE_GAMESERVER_PORT_MIN`..`FINOBE_GAMESERVER_PORT_MAX`, default 53640–53740)
   must be allowed through the Windows firewall **for RobloxApp.exe** and port-forwarded to the
   game-server machine. If 2016 works but 2012 doesn't, it is almost always this — the firewall rule
   was added for `RCCService.exe`, not `RobloxApp.exe`.
4. **`FINOBE_GAMESERVER_HOST_2012`** set to the 2012 machine's public address **only if** it is a
   different machine from the 2016 game servers.
5. `php artisan config:clear` after changing any of the above.

### How this was verified

The whole site→server→join pipeline was driven end-to-end locally (FinobeLauncher under Wine against
the local site):

- launcher → `PlaceLauncher.ashx` → access/restriction checks → **pass**
- `StartGameServer` → `host2012` → `RobloxApp.exe` started → loaded the place → `NetworkServer:Start`
  → **`/api/gameserver/register` → status 2 (running)**
- the client polled, got `Joining`, fetched `join.ashx`, and received the correctly substituted,
  signed join script pointing at the **right** `ip:port` (the fallback resolved to the configured
  host, confirming the config fix).

**If the client shows "Can't reach game server <ip>:<port>"** (older builds of the join script showed
"Connect is not a valid member of NetworkClient", and the player stayed named "Player"):
`NetworkClient:PlayerConnect` threw straight away because the client couldn't send anything to that
address. Tested on the 2012 client: `localhost` and the machine's real LAN address connect, an unreachable
address throws "Unknown exception". Check the address shown — it must be the game-server machine's address
**as seen from the player's PC** (a public IP for outside players; the LAN IP if you test from inside the
same network and your router doesn't loop the public IP back) — then the UDP port range and firewall above.
The client log (`%LOCALAPPDATA%\Roblox\logs\log_*.txt`) has the full reason on the `PlayerConnect failed:` line.

## 2. RakFail (game-server crash / DoS) — PATCHED

A crafted RakNet split packet overflows the reassembly buffer and crashes the game server
(clients 2012–2018 and RCCService). See the published write-up and patcher at
`https://my-thos.github.io/rakwrite/`.

**What was done:**

- `tools/client/rakfail_patch.py` — a self-contained port of the published patcher. It finds the
  unchecked split-packet copy (three known code layouts), appends a small `.rknpch` section with the
  missing bounds check, and jumps to it so an oversized piece is skipped instead of overflowing.
  - Check only: `python3 tools/client/rakfail_patch.py --check <exe>`
  - Patch in place (keeps `.bak`): `python3 tools/client/rakfail_patch.py <exe> [more…]`
- `tools/client/package_client.py` now **applies this automatically** to the client exe
  (`RobloxApp.exe` / `RobloxPlayerBeta.exe`) before it hashes and zips, so **every client we hand out
  is patched**. It keeps a `.bak`, is a no-op on an already-patched exe, and aborts rather than ship a
  build whose vulnerable code it can't locate (`--no-rakfail` skips it).

**You still have to patch the RCC yourself** — `RCCService.exe` is deployed separately and is not
packaged by `package_client.py`. On the RCC machine:

```
python3 tools/client/rakfail_patch.py RCCService.exe
```

Then restart RCCService. (2012, 2016 and 2017 RCC builds were all confirmed vulnerable and patchable.)

After re-packaging a client, bump `FINOBE_CLIENT_VERSION` / `FINOBE_CLIENT_VERSION_<year>` to the new
version the packager prints and `php artisan config:clear`.

## 3. The "end of the launcher" bytecode RCE — ASSESSED, not exploitable on Finobe's model

Write-up: `https://my-thos.github.io/the-end-of-the-launcher/`.

**The vulnerability:** 2014L-and-later clients run server-compiled Lua **bytecode** that the server
sends them. A **malicious host** can send crafted bytecode that achieves remote code execution on a
joining client. It targets *launchers* — "anything that allows you to host/join on any client version"
and "any `local_rcc` server" — i.e. set-ups where **arbitrary users run their own game servers**. The
recommended fix is to make the server send **source** and have the client compile it.

**Why it does not apply to Finobe:** Finobe does not let users host servers. Every game server is
started by the operator (`StartGameServer` → RCC, or `host2012`), and **every** game-server endpoint
is gated by `FINOBE_CLOUD_AUTHORIZATION` (`RobloxUtilities::IsFinobeCloudAuthorized`, constant-time
`hash_equals`). Without that secret key you cannot register or run a server. The bytecode a client
receives is always compiled by the **trusted** server from the place's own uploaded Lua **source** —
no untrusted party ever supplies the raw bytecode, which is what the exploit requires. `loadstring`
compiles source (not bytecode) and runs under `DFFlagLuaLoadStringStrictSecurity`.

So the primary attack vector (a malicious user-hosted server feeding bytecode to joiners) is closed by
the centralized, key-gated hosting model. The 2012 client is listed as **not affected** by the
advisory regardless.

**If you ever allow community-hosted servers** (anyone running a server that others join), this
protection disappears and you must apply the source-compile patch to the 2016 **client and RCC**
(Kanvas's "TB3 Bytecode clients" patch / `luapatch.zip` referenced by the advisory) before doing so.
Until then, keep server registration behind `FINOBE_CLOUD_AUTHORIZATION` and do not expose a way for
users to register or run their own game servers.

## 4. `clientsettings.api.<domain>` must resolve (fixed 2026-11-08)

Newer clients and **RCC** fetch `http://clientsettings.api.<domain>/Setting/QuietGet/<bucket>`.
`routes/web.php` serves it (`Route::domain('clientsettings.api.' . $domain)`), and nginx already
listed it, but the **Cloudflare DNS record was missing** — so the name was NXDOMAIN and every
client/RCC fetch failed to resolve/connect.

Required for it to work (all three):
- **Cloudflare DNS**: a proxied `CNAME clientsettings.api` → `<tunnel-id>.cfargotunnel.com`
  (same tunnel as the other `*.finobe.wtf` names).
- **Tunnel ingress** (`cloudflared` remote config): a `clientsettings.api.finobe.wtf` → `http://localhost:80`
  entry *before* the `http_status:404` catch-all.
- **nginx** `server_name`: include `clientsettings.api.finobe.wtf` (a bare/unknown Host still matches
  the default server and returns the app, but list it explicitly).

Verify from outside: `curl -s -o /dev/null -w '%{http_code}\n' http://clientsettings.api.finobe.wtf/Setting/QuietGet/ClientAppSettings` → `200`.

## 5. Random logouts — session cookie dropped over plain HTTP (fixed 2026-11-08)

The web session cookie is `Secure` (`SESSION_SECURE_COOKIE=true`). Cloudflare's zone-wide
**Always Use HTTPS** was **off**, so `http://finobe.wtf/` answered 200 over plain HTTP. On any
such HTTP request the browser does not send the `Secure` session cookie, so the app sees no
session and the user looks logged out — then logged in again on the next HTTPS request. That is
the "randomly logged out" symptom.

**Do not turn on zone-wide Always Use HTTPS**: the 2012 client speaks plain `http://` to
`assetgame.finobe.wtf`, `clientsettings.finobe.wtf` and `cdn.finobe.wtf`, and a zone-wide
redirect would 301 those and break the game client. Instead there is a **scoped redirect rule**
in the zone's `http_request_dynamic_redirect` ruleset:

```
(http.host eq "finobe.wtf" or http.host eq "www.finobe.wtf") and not ssl
-> 301 https://<host><path>   (preserve query string)
```

Only the two browser hosts are redirected; the client hosts keep working over http.

## 6. Asset/game IDs "skip" (auto-increment gaps) — expected, not a bug

`assets.id` is `AUTO_INCREMENT`. IDs are **never reused**: a deleted row, a rolled-back insert
(`innodb_autoinc_lock_mode=1`), or a failed insert still consumes the number. Two things make
gaps look large on this site:

- **A hat/gear upload is three assets.** `Asset::createHatOrGear` / `ItemImporter` create the
  **texture** (id n), the **mesh** (id n+1) and then the **item** (id n+2). The item people see
  is the third id; the two before it belong to its texture and mesh. So uploading a hat appears
  to "jump" by 3. A face/shirt/pants upload is texture + item (jump of 2); a place is a single
  asset (jump of 1).
- **A failed insert still burns an id.** Historically the asset proxy cached binary PNG bodies
  into the `cache` table (utf8mb4 text) which errored (`1366 Incorrect string value: '\x89PNG'`)
  and burned ids on every cache miss — fixed in `RobloxAssetProxy` by caching base64
  (commit `3242b72d`). Deleted test items leave their ids behind too.

To stop the gaps growing, fix the failing insert rather than trying to reclaim ids (you cannot,
without `ALTER TABLE ... AUTO_INCREMENT`).
