#!/usr/bin/env python3
"""
Patch a stock 2016 Roblox Windows player (RobloxPlayerBeta.exe, 0.257) for Finobe.

  * roblox.com -> finobe.wtf everywhere in the exe (www., assetgame., api hosts...; same length,
    so nothing moves)
  * embeds Finobe's script-signing public key (default: the one in tools/rcc/patch_rcc.py) or one
    derived from --key, so the client runs join scripts the site signs
  * AppSettings.xml BaseUrl -> http://www.finobe.wtf
  * the player list's admin badge stays as shipped; who gets it is decided by the site
    (group 1200769 check). Finobe's duck icon is kept in tools/client/icon_duck.png but not used:
    per-user badges need the player-list script changed, which the stock client compiles in.

Usage:  python3 patch_client.py <client folder> [--key privateKey1024.pem | --blob <base64>]
"""
import argparse, re, sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "rcc"))
from patch_rcc import FINOBE_PUBLIC_KEY_BLOB, public_key_blob  # noqa: E402

DOMAIN = b"finobe.wtf"
REPLACEMENTS = [(b"roblox.com", DOMAIN)]


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

    exe.write_bytes(bytes(b))

    (args.folder / "AppSettings.xml").write_text(
        '<?xml version="1.0" encoding="UTF-8"?>\n<Settings>\n\t<ContentFolder>content</ContentFolder>\n'
        f"\t<BaseUrl>http://www.{DOMAIN.decode()}</BaseUrl>\n</Settings>\n")
    print("AppSettings.xml: BaseUrl -> http://www." + DOMAIN.decode())

    print("leftover roblox.com:", b.count(b"roblox.com"))


if __name__ == "__main__":
    main()
