# Finobe 2016 client

A stock Roblox 2016 Windows player patched for finobe.wtf. The patched files are on the
`client2016` branch of this repo.

- **Build:** RobloxPlayerBeta.exe **0.257.0.83087** (12 August 2016, from Roblox's gametest2 channel,
  via archive.roblonium.com). Same 0.257 version as the RCC on the `rcc` branch (0.257.0.83946), so
  they speak the same network protocol.
- **Patched by `patch_client.py`:** `roblox.com` -> `finobe.wtf` (16 places, same length), Finobe's
  script-signing public key, `AppSettings.xml` BaseUrl, and the player-list badges below.

## Player-list badges

The stock 2016 player list shows Roblox's normal icons: BC / TBC / OBC for memberships, and the admin
badge for anyone the site says is in group 1200769 (users with `status` `admin`).

Per-user badges (Finobe's duck, a custom badge for one person) can't be added to the stock client: its
player-list script is compiled and encrypted into `RobloxPlayerBeta.exe`. They need the client built
from source (the `client` branch: `content/scripts/Modules/PlayerlistModule.lua`, `ADMINS` table).
The duck image is in `tools/client/icon_duck.png`.

## Signing key

Site, RCC and client must use the same keypair. If the site has a new key, re-run with it:

```bat
python tools\client\patch_client.py <client folder> --key privateKey1024.pem
```

(or `--blob <value printed by tools/rcc/patch_rcc.py --print-blob>`), and do the same for RCC.

## Not done here

- No launcher: the site's Play button opens `finobe-launcher://...` (the Penelope launcher). A stock
  2016 player is started as
  `RobloxPlayerBeta.exe --play -a https://www.finobe.wtf/Login/Negotiate.ashx -t <ticket> -j <PlaceLauncher URL>`.
- The binary is still signed by Roblox; patching breaks that signature. The client only reports this
  to the game server, and the RCC here has that check patched out.
