#!/usr/bin/env python3
import argparse, base64, re, struct, sys
from pathlib import Path

DOMAIN = b"finobe.wtf"

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
EXPIRY_OLD = bytes.fromhex("0000000000c08240")
EXPIRY_NEW = bytes.fromhex("0000f81f5fa00242")


def set_base_url(settings_path, url: str) -> None:
    raw = settings_path.read_bytes()
    new = re.sub(rb"(<BaseUrl>)[^<]*(</BaseUrl>)", lambda m: m.group(1) + url.encode() + m.group(2), raw, count=1)
    if new == raw and b"<BaseUrl>" not in raw:
        nl = b"\r\n" if b"\r\n" in raw else b"\n"
        new = raw.replace(b"</Settings>", b"\t<BaseUrl>" + url.encode() + b"</BaseUrl>" + nl + b"</Settings>", 1)
    settings_path.write_bytes(new)


def public_key_blob(pem_path: Path) -> bytes:
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
    header = struct.pack("<BBHI", 6, 2, 0, 0x0000A400)
    rsapubkey = struct.pack("<4sII", b"RSA1", bits, nums.e)
    return base64.b64encode(header + rsapubkey + modulus)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("folder", type=Path, nargs="?")
    ap.add_argument("--key", type=Path, help="PEM of Finobe's signing key")
    ap.add_argument("--blob", help="base64 PUBLICKEYBLOB to embed directly, or 'finobe' for the Karma client key")
    ap.add_argument("--print-blob", action="store_true",
                    help="only print the PUBLICKEYBLOB for --key (e.g. on the web server), patch nothing")
    args = ap.parse_args()

    if args.print_blob:
        if not args.key:
            sys.exit("--print-blob needs --key")
        print(public_key_blob(args.key).decode())
        return

    if not args.folder:
        sys.exit("give the RCCService folder to patch")

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

    set_base_url(args.folder / "AppSettings.xml", f"http://www.{DOMAIN.decode()}")
    print("AppSettings.xml: BaseUrl ->", f"http://www.{DOMAIN.decode()}")

    left = [d for d in (b"bbblox", b"zawg", b"roblox.com") if d in b]
    print("leftover references:", left or "none")


if __name__ == "__main__":
    main()
