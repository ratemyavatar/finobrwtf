#!/usr/bin/env python3
"""
Patch a 2016 (0.257) RCCService.exe for Finobe.

What it does (all in-place, same-length byte replacements, so no offsets move):
  * every old site domain (bbblox.org, roblox.com) -> finobe.wtf   (10 chars each)
  * bbgame.zawg.ca (a third-party trusted domain left by bubbablox) -> cdn.finobe.wtf
  * optionally swaps the embedded script-signing public key (the "BgIAA..." blob)
    for one derived from YOUR private key, so RCC trusts scripts Finobe signs
  * checks that the 10-minute job-expiry patch is present (applies it if not)
  * rewrites AppSettings.xml BaseUrl

Usage:
  python3 patch_rcc.py <RCCService folder> [--key storage/rbx/files/privateKey1024.pem]

The --key file is the same 1024-bit RSA key Finobe signs scripts with
(app/Http/Controllers/SecurityNotary.php). Needs: pip install cryptography (only for --key).
"""
import argparse, base64, re, struct, sys
from pathlib import Path

DOMAIN = b"finobe.wtf"

# Finobe's script-signing public key, from Base/rbx/Crypt.cpp in ThugShaker3D/Karma-2016-Client
FINOBE_PUBLIC_KEY_BLOB = (
    "BgIAAACkAABSU0ExAAQAAAEAAQAphSpUVOk+A75OmYA+sa2I0DXyXZhvM5dha2/31i5BiJZsWmvIwAmWgo9jhG8W"
    "fNvGOZnGFwz6JnrRmcy9WP19a3xElt4c1zr4GNw8Y88HZg+6i5NWZ/fp2lS4cV+gnH4ewAcrRbKJZGF+EuhX0bzM"
    "CFyf4dAp1rmsk2WeFoASzQ=="
)
REPLACEMENTS = [
    (b"bbblox.org", DOMAIN),
    (b"roblox.com", DOMAIN),
    (b"bbgame.zawg.ca", b"cdn.finobe.wtf"),
]
EXPIRY_OLD = bytes.fromhex("0000000000c08240")   # 600.0 seconds
EXPIRY_NEW = bytes.fromhex("0000f81f5fa00242")   # effectively never


def public_key_blob(pem_path: Path) -> bytes:
    """Build a Windows CryptoAPI PUBLICKEYBLOB (base64) from an RSA private or public key PEM."""
    from cryptography.hazmat.primitives import serialization
    data = pem_path.read_bytes()
    try:
        key = serialization.load_pem_private_key(data, password=None).public_key()
    except ValueError:
        key = serialization.load_pem_public_key(data)
    nums = key.public_numbers()
    bits = key.key_size
    if bits != 1024:
        sys.exit(f"key is {bits}-bit; this RCC expects a 1024-bit key")
    modulus = nums.n.to_bytes(bits // 8, "little")
    # BLOBHEADER: PUBLICKEYBLOB(6), CUR_BLOB_VERSION(2), reserved, CALG_RSA_KEYX(0xA400)
    header = struct.pack("<BBHI", 6, 2, 0, 0x0000A400)
    rsapubkey = struct.pack("<4sII", b"RSA1", bits, nums.e)
    return base64.b64encode(header + rsapubkey + modulus)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("folder", type=Path)
    ap.add_argument("--key", type=Path, help="PEM of Finobe's signing key")
    ap.add_argument("--blob", help="base64 PUBLICKEYBLOB to embed directly (e.g. FINOBE_PUBLIC_KEY_BLOB)")
    args = ap.parse_args()

    exe = args.folder / "RCCService.exe"
    b = bytearray(exe.read_bytes())

    for old, new in REPLACEMENTS:
        assert len(old) == len(new), (old, new)
        n = b.count(old)
        b[:] = b.replace(old, new)
        print(f"{old.decode():>16} -> {new.decode():<16} {n} occurrence(s)")

    if b.count(EXPIRY_NEW):
        print("job-expiry patch: already applied")
    elif b.count(EXPIRY_OLD) == 1:
        b[:] = b.replace(EXPIRY_OLD, EXPIRY_NEW)
        print("job-expiry patch: applied")
    else:
        print("job-expiry patch: pattern NOT found - jobs may stop after 10 minutes")

    if args.key or args.blob:
        blobs = [m for m in re.finditer(rb"BgIAAACkAABSU0Ex[A-Za-z0-9+/=]+", bytes(b))]
        blob = FINOBE_PUBLIC_KEY_BLOB if args.blob == "finobe" else args.blob
        new = blob.encode() if blob else public_key_blob(args.key)
        if len(blobs) != 1 or len(blobs[0].group(0)) != len(new):
            sys.exit(f"expected one {len(new)}-char key blob, found {[len(m.group(0)) for m in blobs]}")
        b[blobs[0].start():blobs[0].end()] = new
        print("public key: replaced", "with blob" if args.blob else f"with key from {args.key}")
    else:
        print("public key: left unchanged (pass --key to use Finobe's signing key)")

    exe.write_bytes(bytes(b))

    settings = args.folder / "AppSettings.xml"
    settings.write_text(
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        "<Settings>\n"
        "\t<ContentFolder>content</ContentFolder>\n"
        f"\t<BaseUrl>http://www.{DOMAIN.decode()}</BaseUrl>\n"
        "</Settings>\n"
    )
    print("AppSettings.xml: BaseUrl ->", f"http://www.{DOMAIN.decode()}")

    left = [d for d in (b"bbblox", b"zawg", b"roblox.com") if d in b]
    print("leftover references:", left or "none")


if __name__ == "__main__":
    main()
