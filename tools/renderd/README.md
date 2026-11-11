# finobe renderd

a small c++ program for linux that makes thumbnails and avatars fast.

it keeps a few copies of the real 2016 rcc running under wine, so renders look exactly like they do on windows. the site talks to it like it is one normal rcc, so nothing in the site has to change.

what it does:

- starts the rcc copies and its own Xvfb screen
- sends each render to a free rcc, more copies means more renders at once
- closes each job after it is done so rcc stays clean
- checks every rcc with HelloWorld and restarts any that crash or hang
- restarts an rcc after a set number of jobs so it never gets slow
- only lets ips you allow connect, and refuses big or bad requests
- shows how it is doing at `/health`

game servers do not go through this. it is only for renders.

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

## health

```bash
curl http://127.0.0.1:64989/health
```

it gives back json with every rcc, its state, how many jobs it did and how many times it restarted. it answers 503 when no rcc is ready.
