#!/usr/bin/env python3
import re, sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "rcc"))
from patch_rcc import public_key_blob

if len(sys.argv) != 3:
    sys.exit("usage: set_key.py <client folder or exe> <private key pem>")

target, pem = Path(sys.argv[1]), Path(sys.argv[2])
exes = [target] if target.is_file() else [p for p in (target / "RobloxPlayerBeta.exe", target / "RCCService.exe") if p.is_file()]
if not exes:
    sys.exit(f"no RobloxPlayerBeta.exe / RCCService.exe in {target}")

blob = public_key_blob(pem)
for exe in exes:
    b = exe.read_bytes()
    found = list(re.finditer(rb"BgIAAACkAABSU0Ex[A-Za-z0-9+/=]{184}", b))
    if len(found) != 1:
        sys.exit(f"{exe}: expected one key blob, found {len(found)}")
    old = found[0].group(0)
    if old == blob:
        print(f"{exe}: already has this key")
        continue
    exe.write_bytes(b[:found[0].start()] + blob + b[found[0].end():])
    print(f"{exe}: public key replaced")
