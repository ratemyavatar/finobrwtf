# Finobe 2016 client

A stock Roblox 2016 Windows player patched for finobe.wtf. The patched files are on the
`client2016` branch of this repo.

- **Build:** RobloxPlayerBeta.exe **0.257.0.83087** (12 August 2016, from Roblox's gametest2 channel,
  via archive.roblonium.com). Same 0.257 version as the RCC on the `rcc` branch (0.257.0.83946), so
  they speak the same network protocol.
- **Patched by `patch_client.py`:** `roblox.com` -> `finobe.wtf` (16 places, same length), Finobe's
  script-signing public key, `AppSettings.xml` BaseUrl, and the player-list badges below.

## Player-list badges

The stock 2016 player list can't be edited (its scripts are compiled and encrypted into the exe), so
badges use the two things it already reads: the player's membership type (sent by the site's join
script) and the "is in group 1200769" admin check (answered by the site).

| Who | Badge | How |
| --- | --- | --- |
| User 1 | the duck | MembershipType OBC; `icon_OBC-16.png` is the duck (`tools/client/icon_duck.png`) |
| User 2 | their custom badge | MembershipType TBC; `icon_TBC-16.png` is `tools/client/icon_user2.png` (add it, then re-run) |
| Other admins | normal admin badge | site says they're in group 1200769; `icon_admin-16.png` as shipped |

Who gets what is in `app/Services/PlayerBadges.php` on the website.

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
