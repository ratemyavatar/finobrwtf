# Finobe arbiter (RCC) setup

The website renders thumbnails and starts game servers by calling **RCCService** over SOAP
(`app/Http/Controllers/RobloxArbiterUtilities.php`). The "arbiter" is just a Windows machine
running the patched RCC from the `rcc` branch of this repo.

RCC build: **0.257 (2016)** from `harryzawg/bubbablox-v2/RCCService`, matching
`storage/rbx/files/scripts/gameserver2016.lua`. Already patched in that build: sysstats,
"invalid request", analytics, 10-minute job expiry. `patch_rcc.py` additionally:

- `bbblox.org` / `roblox.com` -> `finobe.wtf` (incl. the SOAP namespace, which must match `storage/rbx/files/RCCService.wsdl`)
- `bbgame.zawg.ca` (third-party trusted domain) -> `cdn.finobe.wtf`
- `AppSettings.xml` BaseUrl -> `http://www.finobe.wtf`
- optional: embeds **your** signing public key (`--key`)

## 1. Machine

- Windows 10/11 or Server 2016+ (64-bit is fine; RCC is 32-bit).
- Install the **Visual C++ 2012 x86** and **2015-2022 x86** redistributables.
- A public IP that players can reach over UDP. It can be the same box as the site
  only if that box runs Windows; otherwise use a separate VPS.

## 2. Get the patched RCC

```bat
git clone --branch rcc --depth 1 https://github.com/yewtubes/finobewtf C:\Finobe\rcc
```

Optional but recommended, so RCC trusts scripts the site signs: copy the site's
`storage/rbx/files/privateKey1024.pem` somewhere temporary and run (needs Python + `pip install cryptography`):

```bat
python tools\rcc\patch_rcc.py C:\Finobe\rcc\RCCService --key C:\path\privateKey1024.pem
```

Delete the key copy afterwards.

## 3. Firewall (do not skip)

RCC's SOAP port executes whatever Lua it is sent. If it is reachable from the internet,
anyone can run code on the machine. In an elevated PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File C:\Finobe\rcc\RCCService\firewall.ps1 -WebServerIp <site server IP>
```

## 4. Run it

Double-click `RCCService\start-rcc.bat` (it restarts RCC if it crashes). To run it as a
service, use NSSM: `nssm install FinobeRCC C:\Finobe\rcc\RCCService\start-rcc.bat`.

## 5. Point the site at it (`.env` on the web server)

```
FINOBE_ARBITER_HOST=<arbiter IP the web server connects to>
FINOBE_ARBITER_PORT=64989
FINOBE_GAMESERVER_HOST=<arbiter public IP players connect to>
FINOBE_GAMESERVER_PORT_MIN=53640
FINOBE_GAMESERVER_PORT_MAX=53740
FINOBE_CLOUD_AUTHORIZATION=<long random string>
```

Then `php artisan config:clear`. `FINOBE_CLOUD_AUTHORIZATION` authenticates the game
server's callbacks (`/api/gameserver/*`); generate one with `openssl rand -hex 32`.

The web server needs PHP's `soap` extension (`php8.3-soap`).

## 6. Test

From the web server:

```bash
php artisan tinker --execute="dump((new App\Http\Controllers\RobloxArbiterUtilities(config('finobe.arbiter.host'), config('finobe.arbiter.port')))->HelloWorld());"
```

It should print `Hello World`. Then upload a hat or shirt and check the thumbnail renders.
