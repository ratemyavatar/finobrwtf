# Finobe 2016 client

A stock Roblox 2016 Windows player patched for finobe.wtf. The patched files are on the
`client2016` branch of this repo.

- **Build:** RobloxPlayerBeta.exe **0.257.0.83087** (12 August 2016, from Roblox's gametest2 channel,
  via archive.roblonium.com). Same 0.257 version as the RCC on the `rcc` branch (0.257.0.83946), so
  they speak the same network protocol.
- **Patched by `patch_client.py`:** `roblox.com` -> `finobe.wtf` (16 places, same length), Finobe's
  script-signing public key, `AppSettings.xml` BaseUrl, and the player-list badges below.

## Player-list badges

| Who | Badge | Where it comes from |
| --- | --- | --- |
| User 1 | Finobe's duck | client: `CUSTOM_ICONS` -> `textures/ui/icon_finobe1.png` (`icon_duck.png`) |
| User 2 | their own badge | client: `CUSTOM_ICONS` -> `textures/ui/icon_finobe2.png` (`icon_user2.png`) |
| Other admins | normal admin badge | site says they're in group 1200769 (`status` = `admin`) |
| BC / TBC / OBC | membership badges | `MembershipType` in the join script |

The player list script (PlayerlistModule) is compiled into the exe as an encrypted "RSB1" blob.
`rsb1.py` decodes/encodes that format (from `App/script/LuaSerializer.inl` in the 2016 source), and
`patch_client.py` rewrites only the strings of its `CUSTOM_ICONS` table (Roblox's four staff entries)
to user IDs 1 and 2 with Finobe icons; the script's code isn't touched. Users in that table skip the
admin check, so other admins keep the normal badge.

User 2's badge is `tools/client/icon_user2.png`: the head of `icon_user2_source.jpg`, background
removed, 16x16 (the whole figure is unreadable at that size; `icon_user2_fullbody.png` is that version
if you prefer it: copy it over `icon_user2.png` and re-run).

Needs `pip install pefile xxhash lz4 cryptography`.

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

## Finobe branding (`rebrand.py`)

    python3 tools/client/rebrand.py RobloxPlayer

Swaps the app icon, the Roblox logo images (`content/textures/roblox-logo.png`,
`loading/robloxlogo.png`, `Roblox-loading*.png`, `Roblox.bmp`) for the Finobe logo
(`finobe_logo.png`), and changes the text players see to "Finobe": window title, error
messages, the exe's version info, and the purchase prompt / developer console / help menu
CoreScripts. Internal names (RobloxGui, RobloxLocked, the `<roblox>` place tags, the
`Roblox/WinInet` user agent, registry keys) are left alone because things depend on them.
Safe to run again. `RobloxPlayer/` in this branch is already rebranded.

## Signing key (`set_key.py`)

    python3 tools/client/set_key.py RobloxPlayer <site>/storage/rbx/files/privateKey1024.pem

The client only accepts join scripts signed with the key built into it, so run this on the web
server (where the private key is) before packaging.
