# finobe notes

## how pages work

- every site page goes through `page()` in `app/Http/Controllers/Controller.php`. pass only the page's own data. `app/View/PageData.php` adds the shared stuff like the user, notifications and alerts
- don't name a controller variable `$data` for page data. most methods use `$data` for the request input already, so page data is `$pageData`
- read settings with `config()`, never `env()`. config caching is safe now
- user content is stored raw and escaped in the controller, then printed with `|raw`. if you add a field that shows user text, escape it with `htmlspecialchars` first, or print it without `|raw`
- links that change something on a GET, like adding a friend, need `RequireLinkToken` on the route and `_token={{ data.csrf_token }}` in the link
- no comments in code. explain things in the docs instead

## 2012 client

- the 2012 client only takes a local address in PlayerConnect. the join script always gives it `localhost` and the launcher runs a udp relay to the real game server
- the launcher has to bind the relay port before it starts RobloxApp.exe. the client binds the server port itself, so a later bind fails with 10048 and the client talks to itself
- the launcher has to stay open while the client runs or the relay dies. when you change it, bump `kLauncherBuild` in `tools/bootstrapper/main.cpp` and `LAUNCHER_BUILD` in `app/Http/Controllers/Client/SetupController.php`
- the 2012 client can't follow the www to https redirect. any url it gets has to use `http://assetgame.finobe.wtf`
- it asks for `/game/studio.ashx` and `/asset/getscriptstate.ashx` on start. without them the core gui never loads, so there's no esc menu and no player list
- game servers run on box2 (game node 2, ports 53700 to 53740). box1 relays those ports to box2 so players use box1's ip. turning on node 1 breaks joins
- worn items live in `users.avatar[0].equippedGearVersionIds`. asset types: 8 hat, 18 face, 2 t-shirt, 19 gear

## heads and packages

- asset type 17 is a head and 32 is a package. a package's items are in `asset_package`. neither has its own file
- a package never reaches the client. `CharacterAppearanceController::expandPackages` swaps it for its items
- equipping a head or package takes off any other worn item of the same type first
- `Asset::renderHead` and `Asset::renderPackage` make their thumbnails. `createPackage()` calls `renderPackage()` itself
- the catalog has to include 17 and 32 in its type filter or those items won't show

## copying roblox bundles and heads

- packages and heads can only be copied from roblox, using the copy form at `/admin/rbxcreatexml`
- bundle details come from `catalog.roblox.com` directly with a roblox user agent. roproxy rate limits that host
- body parts with an r6 version use its CharacterMesh. r15 only body parts get their r15 meshes merged into one r6 CharacterMesh per limb with a texture atlas (`app/Services/ItemImport/R15ToR6.php`). arms get turned to hang down and pushed out past the torso
- dynamic heads (type 79) import as heads marked `dynamicHead`. CharacterFetch then swaps the face for a blank face asset
- animations in bundles get skipped. if nothing usable is left the copy fails with a clear message

## r15 animation pack

- the `r15_2016` pack in `app/Services/AnimationPacks.php` sends a `rig R15` line to the 2016 game server
- the game server then builds the player from `rbxasset://fonts/characterR15.rbxm`, makes the Motor6Ds from the rig attachments and loads the hats, clothes, face, gear and body colors from CharacterFetch itself
- the block meshes live in `storage/rbx/files/r15/` named by their old roblox ids. the animations live in `storage/rbx/files/animations/`
- thumbnails still render the normal r6 body

## rcc and game servers

- the 2016 arbiter is one wine rccservice on box1 in `/opt/finobe-rcc`, soap port 64989. a watchdog restarts it if soap stops answering
- never call `CloseAllJobs`. 2016 game servers are jobs on the same rcc, so it would kill live games. close jobs by id
- `finobe:reap-servers` runs every minute from the scheduler and cleans up game servers that died without saying so. cron needs `php artisan schedule:run` for `www-data`
- rcc leaks a thread for each failed request to a host that doesn't exist. restarting rcc clears them
- rcc idles at about 160% cpu under wine. that's normal
- `servers` rows use php time (america/los_angeles) but mysql `NOW()` is utc. check how old a row is with `now()` in php, not in sql

## 3d avatar view

- the small 3d button on the character and profile pages uses rcc's own obj export (`ThumbnailGenerator:Click("OBJ", ...)`), not a fake model
- `AvatarRenderer::render3d` saves the scene to `storage/app/avatar3d/<userid>-<hash>/` and only renders again when the avatar changes
- three.js r128 shows it. it spins slowly and can be dragged
- users turn it on in /app/theme (`users.view3d`, off by default)

## dances and fling (2016 games)

- `/dance` opens a dance list that looks like the thugshakers admin list window. clicking one says `/e <dance>` in chat
- `/e <dance>` plays it, walking or jumping stops it. only r6 players can dance, the pack is r6
- `/fling` flings yourself
- the dances come from roblox pack 119879382625668. they live in `storage/rbx/files/dances` as gzipped xml with ids from 2140000001 up, listed in `list.txt`
- the 2016 engine only loads animations from number ids, so `/asset/?id=<dance id>` serves them
- the gui script is `storage/rbx/files/scripts/dance2016.lua`, sent as a LocalScript from `/asset/?id=dance&name=client`
- to build the dances again see `tools/dances/README.md`

## uploaded poses

- admins upload a `.rbxm`/`.rbxmx` at `/admin/poses` (a link on the admin panel, no card). it's admin only
- `PoseExtractor` reads a posed r6 or r15 character from its part positions, or a KeyframeSequence (the most posed keyframe unless you pick one), and turns it into r6 joint C0/C1
- poses are saved in `custom_poses` and show up as `custom<id>` in character settings for everyone. the render script gets the joints as its 8th argument
- the upload is capped by php's `upload_max_filesize` and `post_max_size`
