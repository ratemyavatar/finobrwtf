# debugging 2012 joins

## what a good join looks like

1. `PlaceLauncher.ashx` goes from status 0 to 1 to 2 and gives back a `joinScriptUrl`. the server takes about 30 to 45 seconds to start
2. `host2012` health says `{"running":1}` and the `servers` row is status 2
3. the server log shows the place loading, the network server starting and the place running on its port
4. the client log shows the join, the connect to the right ip and port, and `PlayerConnect ok=true`
5. udp traffic goes both ways on the game port
6. the server log says `Player 1 added`

## "PlayerConnect ok=false err=Unknown exception"

the client only says this when it can't talk to the address it got. it used to happen when the launcher handed out a server that had already died, because the row stayed after the process was gone.

`app/Services/Matchmaking/PlaceLauncher.php` now only offers a server if it sent a heartbeat in the last 75 seconds. a dead server says "that server has shut down" instead of loading forever. a server stuck starting for too long doesn't count as starting.

## things to check

- the 2012 exe has finobe's signing key, the patched domain and all the code patches
- `/game/clientversion.ashx?placeId=<id>` says `2012`
- the firewall allows udp 53640 to 53740 and the port is bound
- `/game/studio.ashx` and `/asset/getscriptstate.ashx` answer. without them the core gui never loads
- if you test under xvfb with no gpu, a black window is normal. try a real windows pc before chasing it

## where the logs are

- client: `%LOCALAPPDATA%\Roblox\logs\`. under wine that's inside the wineprefix. `print()` from the join script goes there, not to the console
- watch the game traffic with `tcpdump -ni any "udp port <port>"`. the server port is `servers.port`
