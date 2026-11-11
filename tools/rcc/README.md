# finobe arbiter (rcc)

the site makes thumbnails and starts game servers by talking to rccservice over soap. the "arbiter" is just a windows machine running the patched rcc from the `rcc` branch.

the rcc build is 0.257 from 2016. `patch_rcc.py` changes its domains to `finobe.wtf`, sets the base url and puts in finobe's script signing key. the `rcc` branch already has all of that.

## 1. machine

- windows 10, 11 or server 2016 or newer. rcc is 32 bit but a 64 bit windows is fine
- install the visual c++ 2012 x86 and 2015 to 2022 x86 redistributables
- a public ip players can reach over udp

## 2. get rcc

```bat
git clone --branch rcc --depth 1 https://github.com/yewtubes/finobewtf C:\Finobe\rcc
```

## signing key

the site signs join scripts and tickets with `storage/rbx/files/privateKey1024.pem`. never commit that file. rcc and the client check the signature with the public key built into them, so all three need the same key pair.

on the web server, from the site folder:

```bash
openssl genrsa -traditional -out storage/rbx/files/privateKey1024.pem 1024
```

```bash
chmod 640 storage/rbx/files/privateKey1024.pem
```

```bash
python3 tools/rcc/patch_rcc.py --key storage/rbx/files/privateKey1024.pem --print-blob
```

the last one prints the public half, which is safe to copy. on the arbiter, put it in:

```bat
python tools\rcc\patch_rcc.py C:\Finobe\rcc\RCCService --blob <the printed blob>
```

then restart rcc.

## 3. firewall (don't skip this)

rcc's soap port runs any lua it gets. if the internet can reach it, anyone can run code on the machine. in an admin powershell:

```powershell
powershell -ExecutionPolicy Bypass -File C:\Finobe\rcc\RCCService\firewall.ps1 -WebServerIp <site server ip>
```

## 4. run it

double click `RCCService\start-rcc.bat`. it restarts rcc if it crashes. to run it as a service, use nssm:

```bat
nssm install FinobeRCC C:\Finobe\rcc\RCCService\start-rcc.bat
```

## 5. point the site at it

in `.env` on the web server:

```
FINOBE_ARBITER_HOST=<arbiter ip the web server uses>
FINOBE_ARBITER_PORT=64989
FINOBE_GAMESERVER_HOST=<arbiter public ip players use>
FINOBE_GAMESERVER_PORT_MIN=53640
FINOBE_GAMESERVER_PORT_MAX=53740
FINOBE_CLOUD_AUTHORIZATION=<long random string>
```

then run `php artisan config:clear`. make the key with `openssl rand -hex 32`. the web server needs php's soap extension.

## 6. test

from the web server:

```bash
php artisan tinker --execute="dump((new App\Http\Controllers\RobloxArbiterUtilities(config('finobe.arbiter.host'), config('finobe.arbiter.port')))->HelloWorld());"
```

it should print `Hello World`. then upload a hat or shirt and check that the thumbnail shows up.

## how joining works

1. play opens the client, which keeps asking `Game/PlaceLauncher.ashx`
2. if no server is running, the site adds a `servers` row and starts a `gameserver2016.lua` job on rcc. the client says "starting a server" and keeps asking
3. the game server loads the place and calls `/api/gameserver/register/<job>`. the next ask gives the client its signed join script
4. when a player connects, the game server checks them with `/api/gameserver/verify/<job>/<user>` and kicks anyone the site didn't send there

if the client keeps loading or can't start a server:

- `FINOBE_GAMESERVER_HOST` has to be the rcc machine's real public ip. cloudflare doesn't carry game traffic, so never put the domain there
- `FINOBE_CLOUD_AUTHORIZATION` has to be set, or the server never becomes joinable and gets dropped after 2 minutes
- the web server has to reach rcc's soap port, and players have to reach the udp range
- run `php artisan migrate` and `php artisan config:clear`
- failed starts show up in `storage/logs/laravel.log`
