#!/usr/bin/env python3
"""
Package a patched client folder for the bootstrapper (tools/bootstrapper/FinobeLauncher.exe).

    python3 package_client.py <client folder> [output folder]

Writes <output>/version-<hash>-FinobePlayer.zip (default output: storage/app/private/setup, which
https://prod1-setup.<domain>/ serves) and prints the version. Set FINOBE_CLIENT_VERSION in the
site's .env to it, then `php artisan config:clear`; launchers download it on their next start.
"""
import hashlib, os, sys, zipfile
from pathlib import Path

src = Path(sys.argv[1]).resolve()
out = Path(sys.argv[2]) if len(sys.argv) > 2 else Path(__file__).resolve().parents[2] / "storage/app/private/setup"
if not (src / "RobloxPlayerBeta.exe").is_file():
    sys.exit(f"{src} doesn't look like a client folder (no RobloxPlayerBeta.exe)")

files = sorted(p for p in src.rglob("*") if p.is_file())
h = hashlib.sha1()
for p in files:
    h.update(str(p.relative_to(src)).replace(os.sep, "/").encode()); h.update(p.read_bytes())
version = "version-" + h.hexdigest()[:16]

out.mkdir(parents=True, exist_ok=True)
zip_path = out / f"{version}-FinobePlayer.zip"
with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as z:
    for p in files:
        z.write(p, str(p.relative_to(src)).replace(os.sep, "/"))

print(f"{zip_path} ({zip_path.stat().st_size // 1048576} MB, {len(files)} files)")
print(f"FINOBE_CLIENT_VERSION={version}")
