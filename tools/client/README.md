# finobe 2016 client

a normal roblox 2016 windows player, patched for finobe.wtf. the patched files are on the `client2016` branch.

- build: RobloxPlayerBeta.exe 0.257.0.83087 from august 2016. it's the same 0.257 version as the rcc, so they speak the same network protocol
- `patch_client.py` changes `roblox.com` to `finobe.wtf`, puts in finobe's script signing key, sets the base url in `AppSettings.xml` and adds the player list badges below

you need `pip install pefile xxhash lz4 cryptography`.

## player list badges

- user 1 gets finobe's duck (`icon_duck.png`)
- user 2 gets their own badge (`icon_user2.png`)
- other admins get the normal admin badge, because the site says they're in group 1200769
- bc, tbc and obc get their membership badges from the join script

the player list script is built into the exe as an encrypted "rsb1" blob. `rsb1.py` reads and writes that format. `patch_client.py` only changes the strings in its `CUSTOM_ICONS` table to user ids 1 and 2 with the finobe icons. the script's code stays the same.

`icon_user2.png` is the head from `icon_user2_source.jpg` at 16x16. if you want the whole body instead, copy `icon_user2_fullbody.png` over it and run the patch again.

## signing key

the site, rcc and the client all need the same key pair. if the site gets a new key, run this again with it:

```bat
python tools\client\patch_client.py <client folder> --key privateKey1024.pem
```

you can also pass `--blob` with the value `tools/rcc/patch_rcc.py --print-blob` prints. do the same for rcc.

`set_key.py` only swaps the key in a client that's already patched. run it on the web server, where the private key is:

```bash
python3 tools/client/set_key.py RobloxPlayer <site>/storage/rbx/files/privateKey1024.pem
```

## branding

```bash
python3 tools/client/rebrand.py RobloxPlayer
```

this swaps the app icon and the roblox logo images for the finobe logo, and changes the text players see to finobe. that means the window title, error messages, the exe's version info and the purchase prompt, developer console and help menu. internal names like RobloxGui, RobloxLocked and the registry keys stay, because things depend on them. it's safe to run more than once.

## rakfail

```bash
python3 tools/client/rakfail_patch.py RCCService.exe
```

this adds the missing size check to raknet's split packet code, so a crafted packet can't crash the game server. it keeps a `.bak`. use `--check` to only see if a file is vulnerable.

## 2007 client

```bash
python3 tools/client/patch2007.py Roblox.exe
```

this only works on the original 0.3.676.0 `Roblox.exe` from december 2007 (sha256 `f16069ab...6cc`). it keeps a `.bak`. use `--check` to see if a file is patched.

we found these by reading the openrbx decompile (github.com/Asphaltian/OpenRBX), which gives the real name and address of each function:

- lua bytecode. `loadstring` and `load` ran precompiled lua bytecode, which can be used to run any code on the player's pc. the patch makes lua always use the text parser, so bytecode just fails with a syntax error. 2007 never sends bytecode, so nothing breaks
- split packets. raknet 3.0 read the piece number and piece count without checking them. now a piece must have `index < count` and the count can't be over 32768, or the packet is thrown away
- duplicate pieces. sending the same piece twice leaked memory forever. now the new piece replaces the old one and the old one is freed
- stuck split messages. only unreliable ones were cleaned up, so reliable ones that never finish piled up until the game server or client ran out of memory. now any split message that gets no new piece for the connection timeout is cleaned up

the raknet fixes are in the same code the 2007 game server uses, so patch the exe the server runs too.

things that were already safe in 2007: game scripts can't use `settings()`, `loadfile`, `dofile` or turn on `io`. only `-Script` command line scripts and com calls get those, so the launcher has to only pass our own urls to `-Script` when 2007 gets hooked up.

## not done here

- the exe is still signed by roblox and patching breaks that signature. the client only tells the game server about it, and our rcc has that check patched out
