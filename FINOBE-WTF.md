# Karma 2016 client for finobe.wtf

This branch is [ThugShaker3D/Karma-2016-Client](https://github.com/ThugShaker3D/Karma-2016-Client)
(Finobe's 2016 client source) pointed at **finobe.wtf**.

## What changed

- Every `finobe.net` reference is now `finobe.wtf`: content/asset URLs (`App/util/ContentId.cpp`,
  `LegacyContentTable.cpp`), the trusted-domain checks (`App/util/Shared/Http.cpp`, `WinInet.cpp`,
  `Network/API.cpp`), Studio, the bootstrapper hosts (`prod1-setup.finobe.wtf`, `www.finobe.wtf`)
  and the CoreScripts.
- `AppSettings.xml` for the client, Studio and RCCService default to `http://www.finobe.wtf`
  (they pointed at Roblox / Roblox test sites).
- The launcher waits for a "game started" event named `www.finobe.wtf/robloxStartedEvent`; the
  client signalled `www.roblox.com/robloxStartedEvent`, so the launcher never saw it. Both use the
  same name now.

## Before you build: the signing key

The client only runs join scripts that the site signed with its private key
(`storage/rbx/files/privateKey1024.pem` on the website). The matching public key is built into the
client in `Base/rbx/Crypt.cpp` (`keyB64`). It's still Finobe's original key, so if the site uses a
new key, put the new public key here before building:

1. On the web server: `python3 tools/rcc/patch_rcc.py --key storage/rbx/files/privateKey1024.pem --print-blob`
2. Replace the `keyB64` string in `Base/rbx/Crypt.cpp` with the printed value.
3. Use the same blob for RCC: `patch_rcc.py <RCCService folder> --blob <value>`.

Site, RCC and client must all use the same keypair.

## Building

See `Readme.md`: MSVC 2019, Boost 1.73, Qt 5.15, the DirectX SDK, SDL 2.0.14, VMProtect 3.5.1,
curl 7.43, zlib 1.2.8 and the rest of the listed libraries. Open `Finobe.sln`.

## Things to know

- `WindowsClient/Crypt.cpp` checks that the executable's code-signing certificate has
  `http://www.finobe.wtf ` as its "more info" link. Sign your builds with a certificate that says
  that, or adjust that check.
- `content/scripts/Modules/PlayerlistModule.lua` gives special player-list icons to user IDs 2394 and
  4812 (old Finobe staff), pointing at assets 2625/2626. On the new site those IDs/assets belong to
  someone else; edit or remove those two lines.
- The site's setup routes live on `prod1-setup.finobe.wtf`; that's what the bootstrapper downloads
  builds from.
