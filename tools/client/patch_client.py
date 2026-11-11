#!/usr/bin/env python3
import argparse, codecs, re, shutil, struct, sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "rcc"))
sys.path.insert(0, str(HERE))
from patch_rcc import FINOBE_PUBLIC_KEY_BLOB, public_key_blob

DOMAIN = b"finobe.wtf"
REPLACEMENTS = [(b"roblox.com", DOMAIN)]

CUSTOM_ICONS = {
    b"7210880": (b"1", b"rbxasset://textures/ui/icon_finobe1.png"),
    b"13268404": (b"2", b"rbxasset://textures/ui/icon_finobe2.png"),
    b"261": (b"-1", None),
    b"20396599": (b"-2", None),
}


API_HOST_FORMATS = [
    (b"%s://%sapi.finobe.wtf/%s/?apiKey=%s\0", b"%s://%sfinobe.wtf/%s/?apiKey=%s\0"),
    (b"%s://%sapi.%s%s/%s/?apiKey=%s\0", b"%s://%s%s%s/%s/?apiKey=%s\0"),
    (b"%s://%sapi.sitetest3%s/%s/?apiKey=%s\0", b"%s://%ssitetest3%s/%s/?apiKey=%s\0"),
]


def fix_api_hosts(b: bytearray) -> None:
    n = 0
    for old, new in API_HOST_FORMATS:
        new = new + b"\0" * (len(old) - len(new))
        n += b.count(old)
        b[:] = b.replace(old, new)
    print(f"API hosts: {n} format string(s) -> <service>.finobe.wtf")


CERT_SUBJECT_CHECK = (
    bytes.fromhex("807ddb00" "0f84e4030000" "8d4520508d85f8feffff506a2057"),
    bytes.fromhex("807ddb00" "90e9e4030000" "8d4520508d85f8feffff506a2057"),
)


def skip_cert_subject_check(b: bytearray) -> None:
    old, new = CERT_SUBJECT_CHECK
    if b.count(new) == 1:
        print("cert subject check: already skipped")
        return
    if b.count(old) == 0 and b"\0mc\0" not in b:
        print("cert subject check: not in this build (nothing to skip)")
        return
    if b.count(old) != 1:
        sys.exit(f"cert subject check: expected 1 match, found {b.count(old)}")
    b[:] = b.replace(old, new)
    print("cert subject check: skipped (fixes 'mc' / Failed to request game)")


def set_base_url(settings_path, url: str) -> None:
    raw = settings_path.read_bytes()
    new = re.sub(rb"(<BaseUrl>)[^<]*(</BaseUrl>)", lambda m: m.group(1) + url.encode() + m.group(2), raw, count=1)
    if new == raw and b"<BaseUrl>" not in raw:
        nl = b"\r\n" if b"\r\n" in raw else b"\n"
        new = raw.replace(b"</Settings>", b"\t<BaseUrl>" + url.encode() + b"</BaseUrl>" + nl + b"</Settings>", 1)
    settings_path.write_bytes(new)


def patch_player_list(b: bytearray) -> None:
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
    entry = next(i for i in range(0, len(b) - 12, 4) if b[i:i + 4] == ptr)
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
                strings[i + 1] = new_icon
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
    fix_api_hosts(b)
    skip_cert_subject_check(b)

    exe.write_bytes(bytes(b))

    set_base_url(args.folder / "AppSettings.xml", "http://www." + DOMAIN.decode())
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
