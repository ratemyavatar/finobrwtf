#!/usr/bin/env python3
"""
Patch a stock 2016 Roblox Windows player (RobloxPlayerBeta.exe, 0.257) for Finobe.

  * roblox.com -> finobe.wtf everywhere in the exe (www., assetgame., api hosts...; same length,
    so nothing moves)
  * embeds Finobe's script-signing public key (default: the one in tools/rcc/patch_rcc.py) or one
    derived from --key, so the client runs join scripts the site signs
  * AppSettings.xml BaseUrl -> http://www.finobe.wtf
  * per-user player-list badges: the PlayerlistModule CoreScript (compiled into the exe as an
    "RSB1" blob, see rsb1.py) has a CUSTOM_ICONS table of Roblox staff user IDs. Its strings are
    rewritten so user 1 gets Finobe's duck and user 2 their own badge (the other two entries are
    disabled). Users in that table skip the admin check, so every other admin keeps the normal
    admin badge. Only string-table entries change; the script's code is untouched.
      user 1 -> rbxasset://textures/ui/icon_finobe1.png   (tools/client/icon_duck.png)
      user 2 -> rbxasset://textures/ui/icon_finobe2.png   (tools/client/icon_user2.png, if present)

Usage:  python3 patch_client.py <client folder> [--key privateKey1024.pem | --blob <base64>]
"""
import argparse, codecs, re, shutil, struct, sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "rcc"))
sys.path.insert(0, str(HERE))
from patch_rcc import FINOBE_PUBLIC_KEY_BLOB, public_key_blob  # noqa: E402

DOMAIN = b"finobe.wtf"
REPLACEMENTS = [(b"roblox.com", DOMAIN)]

# CUSTOM_ICONS in the 2016 PlayerlistModule: original user-ID strings -> (new user ID, new icon)
CUSTOM_ICONS = {
    b"7210880": (b"1", b"rbxasset://textures/ui/icon_finobe1.png"),   # was Jeditkacheff
    b"13268404": (b"2", b"rbxasset://textures/ui/icon_finobe2.png"),  # was Sorcus
    b"261": (b"-1", None),                                            # was shedlestky: disabled
    b"20396599": (b"-2", None),                                       # was Robloxsai: disabled
}


def patch_player_list(b: bytearray) -> None:
    """Rewrite CUSTOM_ICONS inside the compiled PlayerlistModule, in place."""
    import pefile
    import rsb1

    pe = pefile.PE(data=bytes(b), fast_load=True)
    base = pe.OPTIONAL_HEADER.ImageBase

    def off2va(o):
        for sec in pe.sections:
            if sec.PointerToRawData <= o < sec.PointerToRawData + sec.SizeOfRawData:
                return base + sec.VirtualAddress + o - sec.PointerToRawData

    def va2off(v):
        for sec in pe.sections:
            if sec.VirtualAddress <= v - base < sec.VirtualAddress + max(sec.Misc_VirtualSize, sec.SizeOfRawData):
                return sec.PointerToRawData + v - base - sec.VirtualAddress

    name = codecs.encode("PlayerlistModule", "rot13").encode() + b"\0"
    name_off = bytes(b).find(name)
    if name_off < 0:
        sys.exit("PlayerlistModule not found in the CoreScript table")
    ptr = struct.pack("<I", off2va(name_off))
    entry = next(i for i in range(0, len(b) - 12, 4) if b[i:i + 4] == ptr)   # {name*, data*, size}
    _, data_va, size = struct.unpack_from("<III", b, entry)
    _, next_va, _ = struct.unpack_from("<III", b, entry + 12)
    data_off, room = va2off(data_va), next_va - data_va

    prefix, strings = rsb1.split_strings(rsb1.decode(bytes(b[data_off:data_off + size])))
    strings = list(strings)
    for i, s in enumerate(strings):
        if s in CUSTOM_ICONS:
            new_id, new_icon = CUSTOM_ICONS[s]
            strings[i] = new_id
            if new_icon:
                strings[i + 1] = new_icon   # the icon string follows its key in the table
    blob = rsb1.encode(rsb1.join_strings(prefix, strings))
    if len(blob) > room:
        sys.exit(f"patched PlayerlistModule is {len(blob)} bytes, only {room} fit")

    b[data_off:data_off + room] = blob + b"\0" * (room - len(blob))
    struct.pack_into("<I", b, entry + 8, len(blob))

    check = rsb1.split_strings(rsb1.decode(bytes(b[data_off:data_off + len(blob)])))[1]
    assert all(k not in check for k in CUSTOM_ICONS), "rewrite didn't stick"
    print(f"player list: CUSTOM_ICONS -> user 1 duck, user 2 custom ({size} -> {len(blob)} bytes, verified)")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("folder", type=Path)
    ap.add_argument("--key", type=Path, help="PEM of the site's signing key")
    ap.add_argument("--blob", help="base64 PUBLICKEYBLOB to embed")
    args = ap.parse_args()

    exe = args.folder / "RobloxPlayerBeta.exe"
    b = bytearray(exe.read_bytes())

    for old, new in REPLACEMENTS:
        assert len(old) == len(new)
        print(f"{old.decode()} -> {new.decode()}: {b.count(old)} occurrence(s)")
        b[:] = b.replace(old, new)

    blob = (args.blob or (public_key_blob(args.key).decode() if args.key else FINOBE_PUBLIC_KEY_BLOB)).encode()
    blobs = list(re.finditer(rb"BgIAAACkAABSU0Ex[A-Za-z0-9+/=]+", bytes(b)))
    if len(blobs) != 1 or len(blobs[0].group(0)) != len(blob):
        sys.exit(f"expected one {len(blob)}-char key blob, found {[len(m.group(0)) for m in blobs]}")
    b[blobs[0].start():blobs[0].end()] = blob
    print("public key: replaced")

    patch_player_list(b)

    exe.write_bytes(bytes(b))

    (args.folder / "AppSettings.xml").write_text(
        '<?xml version="1.0" encoding="UTF-8"?>\n<Settings>\n\t<ContentFolder>content</ContentFolder>\n'
        f"\t<BaseUrl>http://www.{DOMAIN.decode()}</BaseUrl>\n</Settings>\n")
    print("AppSettings.xml: BaseUrl -> http://www." + DOMAIN.decode())

    ui = args.folder / "content" / "textures" / "ui"
    shutil.copyfile(HERE / "icon_duck.png", ui / "icon_finobe1.png")
    print("user 1 badge: duck -> textures/ui/icon_finobe1.png")
    if (HERE / "icon_user2.png").exists():
        shutil.copyfile(HERE / "icon_user2.png", ui / "icon_finobe2.png")
        print("user 2 badge: icon_user2.png -> textures/ui/icon_finobe2.png")
    else:
        print("user 2 badge: add tools/client/icon_user2.png and re-run (until then it shows no icon)")

    print("leftover roblox.com:", b.count(b"roblox.com"))


if __name__ == "__main__":
    main()
