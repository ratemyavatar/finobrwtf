#!/usr/bin/env python3
import argparse, re, sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "rcc"))
sys.path.insert(0, str(HERE.parent / "client"))
from patch_rcc import FINOBE_PUBLIC_KEY_BLOB, public_key_blob
from patch_client import fix_api_hosts, skip_cert_subject_check, set_base_url

DOMAIN = b"finobe.wtf"

DATA_HOST = (
    b"\0data\0\0\0\0/ide/publish/UploadNewMesh?name=RenderMesh&description=RenderMesh&isPublic",
    b"\0www\0\0\0\0\0/ide/publish/UploadNewMesh?name=RenderMesh&description=RenderMesh&isPublic",
)

ONLINE_CHECK = (
    bytes.fromhex("558bec6aff6879090d0164a100000000506489250000000083ec2453568d45ec5750ff15289f2101"),
    bytes.fromhex("b001c3"),
)


def patch_once(b, name, old, new):
    if b.count(old) == 1:
        b[:] = b.replace(old, new)
        print(f"{name}: patched")
    elif b.count(new) >= 1 and b.count(old) == 0:
        print(f"{name}: already patched")
    else:
        sys.exit(f"{name}: expected 1 match, found {b.count(old)}")


def online_check(b):
    old, stub = ONLINE_CHECK
    new = stub + old[len(stub):]
    patch_once(b, "online check", old, new)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("folder", type=Path)
    ap.add_argument("--key", type=Path)
    ap.add_argument("--blob")
    args = ap.parse_args()

    exe = args.folder / "RobloxStudioBeta.exe"
    if not exe.is_file():
        sys.exit(f"no RobloxStudioBeta.exe in {args.folder}")
    b = bytearray(exe.read_bytes())

    print(f"roblox.com -> finobe.wtf: {b.count(b'roblox.com')}")
    b[:] = b.replace(b"roblox.com", DOMAIN)

    blob = (args.blob or (public_key_blob(args.key).decode() if args.key else FINOBE_PUBLIC_KEY_BLOB)).encode()
    blobs = list(re.finditer(rb"BgIAAACkAABSU0Ex[A-Za-z0-9+/=]+", bytes(b)))
    if len(blobs) != 1 or len(blobs[0].group(0)) != len(blob):
        sys.exit(f"expected one {len(blob)} char key blob, found {[len(m.group(0)) for m in blobs]}")
    b[blobs[0].start():blobs[0].end()] = blob
    print("public key: replaced")

    fix_api_hosts(b)
    skip_cert_subject_check(b)
    patch_once(b, "data host -> www", *DATA_HOST)
    online_check(b)
    exe.write_bytes(bytes(b))

    set_base_url(args.folder / "AppSettings.xml", "http://www." + DOMAIN.decode())
    print("AppSettings.xml: BaseUrl -> http://www." + DOMAIN.decode())


if __name__ == "__main__":
    main()
