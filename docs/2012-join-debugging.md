# 2012 join debugging - handoff (2026-11-09)

## Where it stands

The 2012 **server side works end to end**. A joining client is created, connects over UDP, and the
game server registers the player. The remaining problem is on the **client render/join side**: the
joining client window never gets past the sky (screenshot is a black/white window, no 3D scene).

## What was proven working (reproduced locally, not guessed)

Driven from the web box with a Wine joiner (`/opt/finobe-2012/wineprefix-joiner`, DISPLAY :78):

1. `PlaceLauncher.ashx` (RequestGame, placeId=33) -> status 0 -> 1 -> **2**, returns a real
   `joinScriptUrl`. The server starts in ~30-45s.
2. `host2012` /health reports `{"running":1}`; `servers` row goes to status 2.
3. The 2012 **server** log shows the whole pipeline:
   - `DataModel Loading http://www.finobe.wtf/asset/?id=33&accesskey=...`
   - `Started network server 127.0.0.1|53696`
   - `Finobe 2012 server <jobId> running place 33 on port 53696`
4. The joining **client** log shows the join script ran and connected:
   - `! Joining game '<jobId>' place 33 at 13.140.232.195`
   - `! DBG: connect ip=13.140.232.195 port=53696 uid=1 sleep=15`
   - `Connecting to 13.140.232.195:53696`
   - `! DBG: PlayerConnect ok=true err=Player`
5. **tcpdump on the game port: 1972 packets client->server and 1972 server->client.** So the UDP
   path (RakNet) is up and bidirectional.
6. **Server log: `Player 1 added` (then later `Player 1 leaving`).** The server did register the
   player - verify/reportPlayers/visit all ran.

So: launcher, place launcher, host2012, game server script, join script, RakNet connect, and the
site's /api/gameserver endpoints are all fine.

## Root cause of `PlayerConnect ok=false err=Unknown exception`

The client only raises that when it cannot talk to the address it was handed. The launcher was
handing out servers that had already died: `PlaceLauncher` treated `servers.status = 2` as
"running", but the row outlives its process. The heartbeat is every 30s and `ALIVE_TIMEOUT` was
150s, so a server that died kept being offered for up to 2.5 minutes.

Reproduced by inserting a `status=2` row on a port with no listener: the launcher returned that
`jobId` and the client logged `PlayerConnect ok=false err=Unknown exception`.

Fixed in `app/Services/Matchmaking/PlaceLauncher.php`:

- `ALIVE_TIMEOUT` 150 -> 75 (two missed heartbeats).
- New `isAlive()`: a server counts as joinable only if it heartbeated within the window.
- Auto-join and explicit join-by-id both use it; a dead server now reports `GameEnded`
  ("That server has shut down.") instead of `Loading`.
- A `status=1` row past `START_TIMEOUT` no longer counts as "starting".

## The remaining symptom

The joining client window never renders the scene. Screenshot at
`.agent_tmp/2012-joiner-screenshot.png` is a 1280x800 window that is only black + white (154 unique
colors, no scene). Note the joiner was run headless under **Xvfb** with the D3D9 renderer, which on
a headless X server often cannot render - **this may be a test-harness artifact, not the real bug**.
Confirm on a real Windows/GPU client before chasing it.

## Client-side clue worth following

Client log, in order:
```
Warning: httpGet http://www.finobe.wtf/game/studio.ashx failed. Error: Not Found.
Error:   Not Found
Script "Studio.ashx", Line 1
```
`/game/studio.ashx` returns 404. The 2016 path does not need it. Harmless-looking (it is fetched
during startup, before the join), but it is the one clear HTTP failure in the client log.

## Things already checked and NOT the cause

- Patches: the 2012 client exe (build folder AND the served zip, same MD5 f641eb3f...) has Finobe's
  real signing key (not the stock key), the domain patched (22x finobe.wtf, 2 roblox.com left on
  purpose: the .xsd namespace + the cert), and all 10 code patches (identity-5 -Script,
  Player.userId/Name settable, no-kick on SysStats/hack-flag/rocky, 64-bit physics timestamp).
- Endpoints: `/game/clientversion.ashx?placeId=33` -> `2012`; `prod1-setup/version` and
  `?year=2012` -> the right versions. Launcher URIs carry `clientversion` (Place.twig) or fall back
  to ClientVersion.ashx.
- Firewall: host iptables + nft allow `udp dpts:53640:53740`; the port is bound and receiving.
- `FINOBE_GAMESERVER_HOST_2012` unset -> 2012 uses `FINOBE_GAMESERVER_HOST` = 13.140.232.195, which
  is this box's public IP, and the client does reach it.

## Where to look next

1. Reproduce with a real GPU client (not Xvfb) - the black screenshot is likely the harness.
2. If it is real: the client connects and the server sees `Player 1 added`, but the client never
   finishes. Compare against the 2016 join (which works) for the step after PlayerConnect -
   character spawn / `CharacterAppearance` / `Load`. The 2012 join script takes the player from
   `Players.LocalPlayer` after `PlayerConnect` (it throws "Unknown exception" on this build).
3. `/game/studio.ashx` 404 - check whether the 2012 client needs a real response there.
4. Client logs live in the wineprefix:
   `/opt/finobe-2012/wineprefix-joiner/drive_c/users/root/AppData/Local/Roblox/logs/`
   (server side: `/opt/finobe-2012/wineprefix/.../logs/`). `print()` from the join script goes to
   these log files, NOT to the wine stdout.
5. Watch UDP: `tcpdump -ni any "udp port <serverport>"` - client port is the high one, server port
   is `servers.port`.
