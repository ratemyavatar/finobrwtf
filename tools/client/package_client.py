#!/usr/bin/env python3
"""
Package a patched client folder for the bootstrapper (tools/bootstrapper/FinobeLauncher.exe).

    python3 package_client.py <client folder> [output folder] [--year 2012] [--no-rakfail]

Writes <output>/version-<hash>-FinobePlayer.zip (default output: storage/app/private/setup, which
https://prod1-setup.<domain>/ serves) and prints the version. Set FINOBE_CLIENT_VERSION (2016) or
FINOBE_CLIENT_VERSION_<year> in the site's .env to it, then `php artisan config:clear`; launchers
download it the next time someone plays a game of that year.

Before packaging it applies the RakFail fix (rakfail_patch.py) to the client exe in place, so every
client we hand out is patched against the split-packet game-server crash. It keeps a .bak and is a
no-op if the exe is already patched; pass --no-rakfail to skip it. RCCService.exe is deployed
separately (not packaged here) so patch it directly: python3 rakfail_patch.py RCCService.exe
"""
import hashlib, os, sys, zipfile
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
import rakfail_patch

args = sys.argv[1:]
year = "2016"
no_rakfail = "--no-rakfail" in args
args = [a for a in args if a != "--no-rakfail"]
if "--year" in args:
    i = args.index("--year"); year = args[i + 1]; del args[i:i + 2]
src = Path(args[0]).resolve()
out = Path(args[1]) if len(args) > 1 else Path(__file__).resolve().parents[2] / "storage/app/private/setup"
exe = "RobloxApp.exe" if year == "2012" else "RobloxPlayerBeta.exe"
if not (src / exe).is_file():
    sys.exit(f"{src} doesn't look like a {year} client folder (no {exe})")

# RakFail fix: a crafted split packet overflows the game server's reassembly buffer and crashes it.
# Patch the client exe (it is also the 2012 game server) before we hash and zip it.
if not no_rakfail:
    exe_path = src / exe
    data = exe_path.read_bytes()
    state, variant, _ = rakfail_patch.status(data)
    if state == "vulnerable":
        patched, layout = rakfail_patch.patch(data)
        exe_path.with_suffix(exe_path.suffix + ".bak").write_bytes(data)
        exe_path.write_bytes(patched)
        print(f"RakFail: patched {exe} (layout {layout}), original kept as {exe}.bak")
    elif state == "patched":
        print(f"RakFail: {exe} already patched")
    else:
        sys.exit(f"RakFail: can't find the vulnerable code in {exe} ({state}); "
                 f"re-run with --no-rakfail only if you've confirmed this build isn't affected")

# 2012: FinobeLauncher installs and starts it, so the old stand-alone launcher files stay out
skip = {"install.bat", "FinobeLaunch2012.ps1"} if year == "2012" else set()
# .bak files are the pre-patch originals rakfail_patch.py leaves behind; never ship them
files = sorted(p for p in src.rglob("*") if p.is_file() and not p.name.endswith(".bak")
               and not (p.parent == src and p.name in skip))
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
print(f"FINOBE_CLIENT_VERSION={version}" if year == "2016" else f"FINOBE_CLIENT_VERSION_{year}={version}")
