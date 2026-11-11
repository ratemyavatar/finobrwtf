# game servers and client security

## 1. 2012 games stuck on "connecting to server"

a 2012 game server is just the 2012 client running as a server, since there's no 2012 rcc. `host2012.ps1` starts `RobloxApp.exe -Script <gameserver2012.ashx>`, it loads the place, starts the server and tells the site. then the player's client connects to that ip and port over udp.

the ip comes from `ClientVersions::gameserverHost('2012')`. 2012 used to fall back to `FINOBE_GAMESERVER_HOST`, the 2016 rcc machine. if the 2012 host is another machine, players connected to the wrong place and never joined.

now 2012 has its own key:

- if one machine runs both the 2012 host and the 2016 game servers, leave `FINOBE_GAMESERVER_HOST_2012` empty
- if 2012 runs on its own machine, set `FINOBE_GAMESERVER_HOST_2012` to that machine's public ip. not the private `FINOBE_HOST2012_URL`

### checklist

1. `FINOBE_HOST2012_URL` points at `host2012.ps1`, like `http://10.0.0.5:64990`, and the web server can reach it
2. `host2012.ps1` is running on the game server machine:
   `powershell -ExecutionPolicy Bypass -File host2012.ps1 -ClientDir C:\Finobe\Client2012 -AccessKey <FINOBE_CLOUD_AUTHORIZATION>`
3. the game udp range (53640 to 53740 by default) is open in the windows firewall for `RobloxApp.exe` and forwarded to the machine. this is the most common problem. if 2016 works and 2012 doesn't, the rule was probably only added for `RCCService.exe`
4. `FINOBE_GAMESERVER_HOST_2012` is set if 2012 runs on its own machine
5. run `php artisan config:clear` after any change

if the client says "can't reach game server", it couldn't send anything to that address. the address has to be the game server as the player's pc sees it. that's the public ip for outside players, or the lan ip if you test from inside the same network. the client log in `%LOCALAPPDATA%\Roblox\logs` has the full reason on the `PlayerConnect failed:` line.

## 2. rakfail (game server crash), patched

a crafted raknet split packet overflows a buffer and crashes the game server. it hits 2012 to 2018 clients and rccservice.

- `tools/client/rakfail_patch.py` adds the missing size check. `--check` only tells you if a file is vulnerable
- `tools/client/package_client.py` runs it on the client exe before zipping, so every client we hand out is patched. it stops instead of shipping a build it can't patch. `--no-rakfail` skips it

you still have to patch rcc yourself, since it isn't packaged:

```
python3 tools/client/rakfail_patch.py RCCService.exe
```

then restart rcc. after you package a new client, put the new version in `.env` and run `php artisan config:clear`.

## 3. the bytecode rce, not a risk here

newer clients run lua bytecode the server sends them. a bad server can send crafted bytecode and run code on the player's pc. it targets setups where anyone can host a server.

finobe doesn't let users host servers. every server is started by us, and every game server endpoint needs `FINOBE_CLOUD_AUTHORIZATION`. so the bytecode always comes from our own server compiling the place's lua source. the 2012 client isn't affected anyway.

if you ever let the community host servers, patch the 2016 client and rcc to compile source first.

## 4. `clientsettings.api.<domain>` has to resolve

newer clients and rcc fetch `http://clientsettings.api.<domain>/Setting/QuietGet/<bucket>`. the route exists, but it also needs:

- a proxied cloudflare dns `CNAME clientsettings.api` to the tunnel
- a tunnel ingress entry for it before the `http_status:404` catch all
- the name in the nginx `server_name`

check it with `curl -s -o /dev/null -w '%{http_code}\n' http://clientsettings.api.finobe.wtf/Setting/QuietGet/ClientAppSettings`. it should print 200.

## 5. random logouts

the session cookie is secure only. if the browser loads the site over plain http, it doesn't send the cookie, so the user looks logged out until the next https page.

don't turn on cloudflare's zone wide "always use https". the 2012 client talks plain http to `assetgame`, `clientsettings` and `cdn`, and a redirect breaks it. use a redirect rule for just the browser hosts:

```
(http.host eq "finobe.wtf" or http.host eq "www.finobe.wtf") and not ssl
-> 301 https://<host><path>
```

## 6. asset ids skip numbers, and that's fine

asset ids never get reused. a deleted row or a failed insert still uses up a number. also, a hat or gear upload makes three assets: the texture, the mesh and then the item. so the item id jumps by 3. a face, shirt or pants jumps by 2, and a place by 1.

## 7. how players are checked when they join

- the play button, the launcher and the client never see the account token. they get a ticket that lasts 15 minutes. it's signed with the app key, so it can't be faked or moved to another account, and it stops working if the account token is rotated
- `join.ashx` puts a one time join key in the character appearance url
- the game server reads that key from the player, asks `/api/gameserver/verify/<job>/<user>?key=<key>`, and kicks the player if the key is missing, wrong or already used. if the site can't be reached, the player is kicked too
- the site answers with the real username. the server sets the name and the appearance url itself, then loads the character. characters never load from a url the client picked
- a second connection with the same user id is kicked

old game servers that are still running don't send the key, so restart them after you update.

## 8. filtering

2016 servers turn on `FilteringEnabled` so clients can't change the game for everyone. if an old place breaks with it on, you can turn it off for all 2016 games:

```
FINOBE_FORCE_FILTERING=false
```

2012 has no filtering. the 2012 server removes any server script that wasn't in the place when it loaded, except scripts that show up while the server is loading a character (hats, gear and the default character scripts). place scripts that insert new server scripts while the game runs won't work on 2012.

## 9. unapproved assets

assets waiting for review are only sent to admins and our own servers. a server counts if it sends the access key or its ip is the arbiter, a game server host, the 2012 host, or listed in:

```
FINOBE_SERVER_IPS=1.2.3.4,5.6.7.8
```

## 10. launcher

launcher build 9 only binds the 2012 relay to `127.0.0.1`, only takes replies from the game server, and refuses play links that don't point at finobe or that try to sneak in extra arguments. players get it on their next launch.


## 11. thumbnails and avatars

every render runs on the 2016 rcc with the same `ThumbnailGenerator:Click` finobe used, so framing, lighting and the clear background come straight from the engine.

- avatars, hats, gear and heads render at 768 by 768. places render at 768 by 432
- shirts, t-shirts, pants and packages render on the classic body colors (yellow head and arms, blue torso, green legs), like the old catalog
- render and cdn urls follow `FINOBE_DOMAIN` and `FINOBE_CDN_URL`, so renders work on any domain
- rcc loads the character url through winhttp. if you test with a self signed certificate, rcc can't load it over https. production behind cloudflare is fine

## 12. client login api

`POST https://api.finobe.wtf/login/v1` with `username=<name>&password=<password>` logs a client in, like the old roblox login api. it also takes an email in `username`.

- success gives `{"userId":..,"membership":..,"username":..,"isUnder13":false,"countryCode":"US"}` and a normal site session
- a wrong name or password gives a 403 with `{"errors":[{"code":1,"message":"Incorrect username or password. Please try again."}]}`
- banned accounts and accounts with two step verification are refused, so this can't be used to skip a 2fa code
- each ip gets 10 tries a minute

`POST /mobileapi/login` on www, assetgame and api takes the same `username=&password=` and works the same way, but answers like the old mobile api:

- success gives `{"Status":"OK","UserInfo":{"userId":..,"username":..,"RobuxBalance":..,"IsAnyBuildersClubMember":..,"ThumbnailUrl":..}}`
- otherwise `Status` is `InvalidPassword`, `MissingRequiredField`, `AccountNotApproved` (banned) or `TwoStepVerificationRequired`
- RobuxBalance is the user's dius
