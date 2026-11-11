# finobe renderd

a small c++ program for linux that makes thumbnails and avatars fast and runs 2016 and 2012 game servers.

it runs the real 2016 rcc and the real 2012 client under wine, so renders and games work exactly like they do on windows. the site talks to it like it is one normal rcc plus the old `host2012.ps1`, so nothing in the site has to change.

what it does:

- starts the rcc copies and its own Xvfb screen
- sends each render to a free rcc, more copies means more renders at once
- closes each job after it is done so rcc stays clean
- checks every rcc with HelloWorld and restarts any that crash or hang
- restarts an rcc after a set number of jobs so it never gets slow
- only lets ips you allow connect, and refuses big or bad requests
- shows how it is doing at `/health`

## game servers

2016 games come in as normal rcc jobs. each game gets its own rcc, so one game crashing can't take down other games or renders. one spare rcc is always kept warm so a new game opens in under a second.

- CloseJob, RenewLease, Execute and GetExpiration go to the right game
- a game that crashes or stops answering is cleaned up, and the site sees it go away like before
- a game is closed when its lease runs out
- `--game-max` stops it from starting more games than the box can hold

2012 games use the same `/start`, `/stop` and `/health` api as `host2012.ps1`, on port 64990. every call needs the `AccessKey` header, same as before. it only starts `RobloxApp.exe` with a `gameserver2012.ashx` url from your own domain, and kills any 2012 server that runs longer than `--game-lifetime`.

to turn on 2012, point `--client2012` at the patched 2012 client folder and give it the site's key:

```bash
echo "<FINOBE_CLOUD_AUTHORIZATION>" > /srv/finobe/key && chmod 600 /srv/finobe/key
```

```bash
finobe-renderd --rcc /srv/finobe/rcc --wine-prefix /srv/finobe/wine --workers 4 --client2012 /srv/finobe/client2012 --access-key-file /srv/finobe/key
```

then set `FINOBE_HOST2012_URL=http://127.0.0.1:64990` in the site's `.env` (or the box's ip if it is another machine).

players join the game ports straight over udp, so open the game udp range (53640 to 53740 by default) in your firewall. keep 64989 and 64990 closed to everyone but the web server.

a 2016 game is any job named `GameServer` or with a lease longer than `--job-timeout`. everything else is a render.

## build

you need g++ 9 or newer, cmake, wine (32 bit) and Xvfb.

```bash
sudo apt install g++ cmake make wine32 xvfb
```

```bash
cmake -S tools/renderd -B tools/renderd/build
```

```bash
cmake --build tools/renderd/build -j
```

```bash
sudo install tools/renderd/build/finobe-renderd /usr/local/bin/
```

## rcc files

put the patched 2016 rcc (the `rcc` branch, see tools/rcc) in a folder like `/srv/finobe/rcc`, so `/srv/finobe/rcc/RCCService.exe` exists.

make a wine prefix for it once:

```bash
WINEPREFIX=/srv/finobe/wine wineboot -i
```

## run

```bash
finobe-renderd --rcc /srv/finobe/rcc --wine-prefix /srv/finobe/wine --workers 4
```

it listens on 127.0.0.1:64989, the same port as a normal rcc. the rcc copies use ports 65100 and up, only on localhost.

to run it all the time, copy `finobe-renderd.service` to `/etc/systemd/system/`, change the paths and user, then:

```bash
sudo systemctl enable --now finobe-renderd
```

## site settings

the site uses `FINOBE_ARBITER_HOST` and `FINOBE_ARBITER_PORT` for renders. if renderd runs on the web server you do not need to change anything. if it runs on another box, start it with `--listen 0.0.0.0 --allow <web server ip>` and set those two in `.env`. keep the port closed to everyone else in your firewall.

## options

| option | default | what it does |
| --- | --- | --- |
| `--listen` | 127.0.0.1 | address to listen on |
| `--port` | 64989 | port the site talks to |
| `--workers` | 2 | how many rcc copies to run, about one per cpu core is good |
| `--base-port` | 65100 | first port for the rcc copies |
| `--wine` | wine | wine binary |
| `--wine-prefix` | | wine prefix for rcc |
| `--display` | | use this X display instead of starting Xvfb |
| `--allow` | 127.0.0.1 | ips allowed to connect, split with commas |
| `--queue-timeout` | 120 | seconds a render waits for a free rcc |
| `--job-timeout` | 120 | seconds one render may take |
| `--start-timeout` | 120 | seconds an rcc may take to start |
| `--recycle-after` | 250 | restart an rcc after this many renders |
| `--health-every` | 15 | seconds between health checks |
| `--game-max` | 8 | most 2016 games at once, 0 turns them off |
| `--game-spares` | 1 | rcc copies kept ready for new games |
| `--game-base-port` | 65200 | first soap port for game rcc copies |
| `--game-lifetime` | 21600 | longest a 2012 game may run, in seconds |
| `--client2012` | | 2012 client folder, turns on 2012 games |
| `--port2012` | 64990 | port for the 2012 api |
| `--max2012` | 8 | most 2012 games at once |
| `--access-key-file` | | file with the site's key, or set `RENDERD_ACCESS_KEY` |
| `--domain` | finobe.wtf | your domain, used to check 2012 script urls |

## health

```bash
curl http://127.0.0.1:64989/health
```

it gives back json with every render rcc, every game and every 2012 server. it answers 503 when no render rcc is ready.
