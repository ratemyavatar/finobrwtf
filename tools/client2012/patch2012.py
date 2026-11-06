#!/usr/bin/env python3
"""
Patch the 2012 client (RobloxApp.exe 0.65.0.596, unprotected) for Finobe. Same-length, in-place
byte replacements only, so nothing moves.

    python3 tools/client2012/patch2012.py Client2012 [--key /path/to/privateKey1024.pem] [--blob BASE64]

  * site domain: roblox.com -> finobe.wtf (both 10 chars); the place-file XML namespace
    (http://www.roblox.com/roblox.xsd) and the Authenticode certificate are left alone
  * window title and player-facing text: ROBLOX -> Finobe
  * script-signing key: the "BgIAA..." PUBLICKEYBLOB, so the client accepts the join scripts
    finobe.wtf signs. Default: the same key the 2016 client has. --key = the site's
    storage/rbx/files/privateKey1024.pem if yours differs (run it on the web server)
  * AppSettings.xml BaseUrl -> http://www.finobe.wtf
  * code patches so the client can host a game server and players can join it (see CODE below)

Safe to run again. Needs `pip install cryptography` only for --key.
"""
import argparse, base64, re, struct, sys
from pathlib import Path

DOMAIN_OLD, DOMAIN_NEW = b"roblox.com", b"finobe.wtf"
KEEP = [b"http://www.roblox.com/roblox.xsd"]           # place XML namespace, written into .rbxl files
BASE_URL = "http://www.finobe.wtf"
# the key the 2016 client ships with (Finobe's script-signing key), used when --key/--blob isn't given
FINOBE_PUBLIC_KEY_BLOB = (
    "BgIAAACkAABSU0ExAAQAAAEAAQAphSpUVOk+A75OmYA+sa2I0DXyXZhvM5dha2/31i5BiJZsWmvIwAmWgo9jhG8W"
    "fNvGOZnGFwz6JnrRmcy9WP19a3xElt4c1zr4GNw8Y88HZg+6i5NWZ/fp2lS4cV+gnH4ewAcrRbKJZGF+EuhX0bzM"
    "CFyf4dAp1rmsk2WeFoASzQ=="
)

# code patches for 0.65.0.596: (virtual address, original bytes, patched bytes, what it does).
# Found by testing a client-hosted server + client join under Wine.
CODE = [
    (0xAF5965, "6a05", "6a03", "game:HttpGet needs security level 3 instead of 5"),
    (0xAF59B5, "6a05", "6a03", "game:HttpPost needs security level 3 instead of 5"),
    (0x48662F, "6a01", "6a05", "-Script runs at identity 5 (can call Load, PlayerConnect, NetworkServer:Start)"),
    (0x53A687, "7519", "eb19", "Player.userId can be set by the join script (PlayerConnect with a real user id)"),
    (0x520A2A, "7519", "eb19", "Player.Name can be set by the join script"),
    (0x552D50, "558d6c", "c20c00", "server: Players::onRemoteSysStats doesn't kick (patched clients trip the checks)"),
    (0x58EEB9, "8a5c2478", "32db9090", "server: ignore the client's hack-flag byte in RequestCharacter"),
    (0x593688, "8d042450e8", "e99a000000", "server: ignore 'rocky' items (client tamper report) instead of disconnecting"),
    # physics sender wrote ID_TIMESTAMP with a 32-bit time but every reader wants 64 bits, so the
    # client dropped the connection ("packet 27: BitStream >> RakNet::Time failed"); write 64 bits
    (0x5AF2F3, "e848880000" "50" "53" "e8f1cbfbff" "6885000000",
               "e848880000" "52" "50" "54" "53" "e8afc9fbff" "6a85" "90", "server: 64-bit physics timestamp"),
    (0x5AF30A, "83c418", "83c420", "server: 64-bit physics timestamp (stack cleanup)"),
]

# ASCII, NUL-terminated strings players can see
TEXT = [
    "An unexpected error occurred and ROBLOX needs to quit.  We're sorry!",
    "ROBLOX Crash",
    "Wrong ROBLOX version",
    "Your graphics drivers seem to be too old for Roblox to use.\n\nVisit http://www.roblox.com/drivers for info on how to perform a driver upgrade.",
]
# UTF-16 fragments in the resources (window title = the main frame's document string "ROBLOX\nPlace\n...")
TEXT_W = [
    "ROBLOX\nPlace\n",
    "Publish to ROBLOX...", "Publish Selection to ROBLOX...",
    "settings may make ROBLOX unstable.", "change any ROBLOX settings", "by a ROBLOX engineer.",
    "ROBLOX is uploading an image.", "until you restart ROBLOX", "ROBLOX crashed on your",
    "make ROBLOX better.", "May ROBLOX send", "ROBLOX is uploading a video.",
    "ROBLOX Game", "ROBLOX Corporation",
]


def finobe(s: str) -> str:
    return s.replace("www.roblox.com", "www.finobe.wtf").replace("ROBLOX", "Finobe").replace("Roblox", "Finobe")


def public_key_blob(pem_path: Path) -> bytes:
    """Windows CryptoAPI PUBLICKEYBLOB (base64) for a 1024-bit RSA key PEM (private or public)."""
    from cryptography.hazmat.primitives import serialization
    data = pem_path.read_bytes()
    try:
        key = serialization.load_pem_private_key(data, password=None).public_key()
    except ValueError:
        key = serialization.load_pem_public_key(data)
    n = key.public_numbers()
    if key.key_size != 1024:
        sys.exit(f"key is {key.key_size}-bit; the client expects a 1024-bit key")
    blob = struct.pack("<BBHI", 6, 2, 0, 0xA400) + struct.pack("<4sII", b"RSA1", 1024, n.e) + n.n.to_bytes(128, "little")
    return base64.b64encode(blob)


def replace_text(b: bytearray, old: bytes, new: bytes, label: str) -> int:
    count, i = 0, 0
    while (i := b.find(old, i)) != -1:
        b[i:i + len(old)] = new
        i += len(new)
        count += 1
    print(f"  {label}: {count}")
    return count


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("folder", type=Path)
    ap.add_argument("--key", type=Path, help="the site's signing key PEM")
    ap.add_argument("--blob", help="base64 PUBLICKEYBLOB to embed directly")
    a = ap.parse_args()

    exe = a.folder / "RobloxApp.exe"
    b = bytearray(exe.read_bytes())

    # domain (skip the kept strings and anything inside the Authenticode certificate at the end)
    cert_start = len(b)
    import pefile
    pe = pefile.PE(data=bytes(b), fast_load=True)
    sec = pe.OPTIONAL_HEADER.DATA_DIRECTORY[4]  # IMAGE_DIRECTORY_ENTRY_SECURITY
    if sec.VirtualAddress:
        cert_start = sec.VirtualAddress
    keep = [(m.start(), m.end()) for k in KEEP for m in re.finditer(re.escape(k), b)]
    n = 0
    for enc in ("ascii", "utf-16le"):
        old, new = DOMAIN_OLD.decode().encode(enc), DOMAIN_NEW.decode().encode(enc)
        for m in list(re.finditer(re.escape(old), b)):
            if m.start() >= cert_start or any(s <= m.start() < e for s, e in keep):
                continue
            b[m.start():m.end()] = new
            n += 1
    print(f"  domain roblox.com -> finobe.wtf: {n}")

    for t in TEXT:
        t = t.replace("www.roblox.com", "www.finobe.wtf")      # the domain pass above already ran
        replace_text(b, b"\0" + t.encode("latin1") + b"\0", b"\0" + finobe(t).encode("latin1") + b"\0", repr(t[:40]))
    # the app title string resource (what the window caption shows): length-prefixed UTF-16 "Roblox"
    replace_text(b, b"\x06\x00" + "Roblox".encode("utf-16le"), b"\x06\x00" + "Finobe".encode("utf-16le"), "app title (W)")
    for t in TEXT_W:
        replace_text(b, t.encode("utf-16le"), finobe(t).encode("utf-16le"), repr(t[:40]) + " (W)")

    for va, old, new, what in CODE:
        old, new = bytes.fromhex(old), bytes.fromhex(new)
        assert len(old) == len(new), what
        off = pe.get_offset_from_rva(va - pe.OPTIONAL_HEADER.ImageBase)
        cur = bytes(b[off:off + len(old)])
        if cur == new:
            print(f"  {what}: already patched")
        elif cur == old:
            b[off:off + len(old)] = new
            print(f"  {what}: patched")
        else:
            sys.exit(f"unexpected bytes at {va:#x} ({what}): {cur.hex()} - is this 0.65.0.596?")

    blob = public_key_blob(a.key) if a.key else (a.blob or FINOBE_PUBLIC_KEY_BLOB).encode()
    if blob:
        found = list(re.finditer(rb"BgIAAACkAABSU0Ex[A-Za-z0-9+/=]{184}", b))
        if len(found) != 1 or len(blob) != 200:
            sys.exit(f"expected one 200-char key blob, found {len(found)}")
        b[found[0].start():found[0].end()] = blob
        print("  signing key replaced")
        if not a.key and not a.blob:
            print("  WARNING: that's the stock Finobe key, not your site's. Players will be kicked (join script\n"
                  "           rejected) unless you run this again with --key .../storage/rbx/files/privateKey1024.pem")
    else:
        print("  signing key NOT changed (pass --key with the site's privateKey1024.pem)")

    exe.write_bytes(b)

    settings = a.folder / "AppSettings.xml"
    raw = settings.read_bytes()
    settings.write_bytes(re.sub(rb"(<BaseUrl>)[^<]*(</BaseUrl>)", lambda m: m.group(1) + BASE_URL.encode() + m.group(2), raw, count=1))
    print(f"  AppSettings.xml BaseUrl -> {BASE_URL}")


if __name__ == "__main__":
    main()
