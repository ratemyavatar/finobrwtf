#!/usr/bin/env python3
"""
Put the site's signing key into an already-patched client (or RCC), so it accepts the join
scripts the site signs. Run it on the web server, where the private key lives:

    python3 tools/client/set_key.py RobloxPlayer /path/to/site/storage/rbx/files/privateKey1024.pem

Takes a client folder or an .exe. Only the 200-char PUBLICKEYBLOB is swapped (same length, so
nothing else moves). Then repackage with package_client.py and update FINOBE_CLIENT_VERSION.
"""
import re, sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "rcc"))
from patch_rcc import public_key_blob  # noqa: E402

if len(sys.argv) != 3:
    sys.exit(__doc__)

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
