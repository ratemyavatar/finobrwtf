# Finobe notes

## 2012 client: external joins, core GUI, character assets (learned)
- The 2012 client only accepts a LOCAL address in PlayerConnect; the join script always
  hands it `localhost` and FinobeLauncher runs a UDP relay to the real game server.
- Launcher relay MUST bind the target port BEFORE launching RobloxApp.exe: the 2012 client
  binds its local socket to the server port itself, so a later bind gets WSAEADDRINUSE (10048)
  and the client talks to itself. (main.cpp: bindRelay() called before CreateProcessW.)
- The launcher must stay alive while the client runs (WaitForSingleObject(pi.hProcess, INFINITE)),
  otherwise the relay thread dies with it. Build is bumped in tools/bootstrapper/main.cpp
  (kLauncherBuild) and app/Http/Controllers/Client/SetupController.php (LAUNCHER_BUILD).
- 2012 client cannot follow the www.finobe.wtf -> https 301. Any URL handed to the client
  (join script charapp, CharacterFetch body, asset XML contents, core GUI lua) must use
  http://assetgame.finobe.wtf. asset() and the core-GUI branch of asset() rewrite www->assetgame.
- The 2012 client fetches /game/studio.ashx and /asset/getscriptstate.ashx on startup; without
  those routes (they 404'd) the core GUI never initialises - no ESC menu, no player list.
  Both now routed to rbxAPIs::studioAshx / getScriptStateAshx.
- Game servers run on box2 (game_nodes id 2, ports 53700-53740); box1's gost relay
  (relay://:18443) tunnels box1:537xx -> box2 so players connect to box1's public IP.
  Enabling node 1 (box1) breaks joins: box1 has no host2012 and gost owns those ports.
- Character accessories (hat/face/t-shirt) live in users.avatar[0].equippedGearVersionIds;
  asset_type 8=Hat 18=Face 2=TShirt 19=Gear.

## Do NOT run `php artisan config:cache` on this app

This codebase calls `env()` **directly** in controllers/views (e.g. `FINOBE_INVITE_KEYS`,
`FINOBE_CREATE_INVITE_KEYS`, `TURNSTILE_SECRET` in `AccountController`, `InvitesController`,
`AdminToolsController`). Laravel makes `env()` return **null** once the config is cached, so a
cached config silently flips these flags off: invite keys look disabled site-wide, registration
closes, and admin panels show wrong warnings. `bootstrap/cache/config.php` is also not tracked
and is owned by `www-data`; a root-owned cache file makes the breakage confusing to spot.

Use `php artisan config:clear` (never `config:cache`) after changing `.env` or config files.

## Head bundles (17) and packages (32)

- asset_type 17=Head, 32=Package/Bundle. Both are normal `assets` rows; a package's
  contents live in `asset_package(package_asset_id, asset_id)` (migration
  `2026_11_10_000001_create_asset_package`). Neither has a `file` payload of its own.
- A package never reaches the client: `Client\CharacterAppearanceController::expandPackages`
  swaps it for its contents in CharacterFetch, so it can be worn but is stripped before
  the response. Worn items (heads, packages, hats, faces, gear) all share
  `users.avatar[0].equippedGearVersionIds`; the equip branches in `api.php`
  (`head-asset`, `package`) drop any other worn item of the same type first.
- Rendering: `Asset::renderHead` puts the bare SpecialMesh on a grey Head part;
  `Asset::renderPackage` builds a character wearing everything the package contains.
  `Asset::render()` has no case for 32, so `createPackage()` calls `renderPackage()`
  itself. ItemImporter stores the head mesh on the model and returns type 17.
- Catalog sections already map `heads => 17`, `packages => 32`; the listing template
  must include those types in its `asset_type in [...]` filter or the items are invisible.

## Copying Roblox bundles / heads (AssetCopier)

- Packages (32) and heads (17) are copy-only: the /admin/createxml upload form has no
  Head or Package option, and createxml rejects those types. Create them with the copy
  form (/admin/rbxcreatexml), which takes a Roblox link.
- `AssetCopier::bundleIdFromLink()` accepts `roblox.com/bundles/<id>` links; `copyBundle()`
  fetches `catalog.roblox.com/v1/bundles/<id>/details` (roproxy 429s on that host, use
  catalog.roblox.com directly with a Roblox/WinInet User-Agent) and builds a package from
  the bundle items this site can use.
- Roblox bundle items are mostly body parts (27-31) and animations (48-55), which this
  site cannot hold and are skipped. A head bundle (bundleType DynamicHead) maps its type-79
  head; a body-part bundle keeps its head (type 8) and clothing. If nothing usable remains
  the copy fails with a clear message.
- `AssetCopier::importItem()` maps a Roblox type to a site type; a head that arrives
  wrapped as an accessory falls back to a hat rather than failing the whole bundle.

## Arbiter (RCC) and game-server reaping

- The 2016 arbiter is one Wine RCCService on box1 (`/opt/finobe-rcc`, SOAP 64989, restarted by
  `finobe-rcc.service`; `finobe-rcc-watchdog.timer` restarts it if the SOAP port stops answering).
  Do NOT call `CloseAllJobs` on it: RCC game servers for 2016 places run as RCC jobs on the same
  arbiter, so that would kill live games. Close jobs by id.
- A game server that dies without calling `/shutdown` used to leave both its `servers` row and its
  RCC job (or 2012 RobloxApp) running. `PlaceLauncher::cleanup()` only ran when a player launched a
  game, so with no games starting the leaks piled up: an orphaned RCC job keeps ~10 threads busy and
  RCC eventually dies with `boost::thread_resource_error`. A `finobe:reap-servers` command now runs
  every minute via the Laravel scheduler (cron for `www-data`: `php artisan schedule:run`).
- RCC leaks a `rbx_http_stats_` thread per failed `httpGet` to an unresolvable host. The noisy
  `api.w.finobe.wtf` / `versioncompatibility.api.w.finobe.wtf` (err 0x2EE7) comes from RCC's
  `%s://%sapi.%s%s/%s/?apiKey=%s` template; only `api.finobe.wtf` exists, so those retries fail and
  leak threads. Restarting RCC clears them (threads 377 -> 61, RSS 1.1G -> 116M, idle CPU 150% -> 12%).
- RCC has a stable ~160% CPU floor at idle under Wine (32 TaskScheduler threads busy-waiting); that
  is Wine overhead, not a leak. Compare a *fresh* RCC against the current one before calling RCC slow.
- `servers` rows are written with PHP time (America/Los_Angeles) while MySQL `NOW()` is UTC; a plain
  SQL age check is off by the tz offset. Use `now()` in PHP to judge staleness.
