#!/usr/bin/env python3
"""
Finobe branding for the patched 2016 player: logo and the text players see. Internal names stay
(RobloxGui, RobloxLocked, <roblox> place XML tags, Roblox/WinInet user agent, registry keys...),
since the client, CoreScripts, places and the site rely on them.

    python3 tools/client/rebrand.py RobloxPlayer

Run after patch_client.py (any order with set_key.py). Safe to run again.

- exe: the app icon (all 6 sizes, overwritten in place), the window title and player-facing
  messages, the file's version info (what Windows shows as the app's name/company)
- CoreScripts inside the exe: purchase prompt, developer console title, help menu text
- content/textures: the Roblox logo images
"""
import codecs, io, struct, sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import rsb1  # noqa: E402

LOGO = HERE / "finobe_logo.png"           # the round F logo (site/launcher logo)
PINK, GREY, WHITE = (248, 40, 96, 255), (112, 112, 112, 255), (255, 255, 255, 255)

# whole strings (ASCII, NUL-terminated in the exe) that players can see
EXE_TEXT = [
    "ROBLOX",                                   # window title
    "ROBLOX Crash",
    "An unexpected error occurred and ROBLOX needs to quit.  We're sorry!",
    "Your graphics drivers seem to be too old for Roblox to use.\n\nVisit http://www.finobe.wtf/drivers for info on how to perform a driver upgrade.",
    "Roblox cannot startup. User code = 0x1.",
    "ROBLOX version is out of date. Please uninstall and try again.",
    "ROBLOX could not initialize input. Please make sure you have a mouse and keyboard plugged in.",
    "Feature not available in offline mode.\nPlease check internet connection and restart Roblox.",
    "Logfiles will be uploaded next time you start Roblox. Please restart roblox now.",
    "Could not find a mouse! ROBLOX requires a mouse.",
    "Could not find a mouse (set property failed)! ROBLOX requires a mouse.",
    "Could not find a mouse (data format failed)! ROBLOX requires a mouse.",
    "Could not find a keyboard! ROBLOX requires a keyboard.",
    "Could not find a keyboard (set property failed)! ROBLOX requires a keyboard.",
    "Could not find a keyboard (data format failed)! ROBLOX requires a keyboard.",
    "Teleporting while using ROBLOX Studio is not permitted.",
    "Game passes can only be queried by a Script running on a ROBLOX game server",
    "Sorry, badges can only be tested if they are disabled on Roblox game servers",
    "Sorry, badges can only be queried by the Roblox game servers",
    "Sorry, badges can only be awarded by Roblox game servers",
]
# UTF-16 strings: version info and the window caption resource
EXE_TEXT_W = [
    "ROBLOX", "ROBLOX Game Client", "ROBLOX Corporation", "Roblox Application",
    " 2013, ROBLOX Corporation.  All rights reserved.", "RobloxApp.exe",
]
# CoreScript string constants players can see
CORESCRIPT_TEXT = {
    "CoreScripts/PurchasePromptScript2", "CoreScripts/PurchasePromptScript3",
    "DeveloperConsoleModule", "Settings/Pages/Help",
}
# the Roblox logo images in content/textures
IMAGES = {
    "content/textures/loading/robloxlogo.png": "wordmark",   # artwork is the strip (0, 95)-(256, 160)
    "content/textures/roblox-logo.png": "wordmark",
    "content/textures/Roblox-loading.png": "logo",
    "content/textures/Roblox-loading@2x.png": "logo",
    "content/textures/Roblox.bmp": "logo-white",
}
WORDMARK_BOX = {"content/textures/loading/robloxlogo.png": (0, 95, 256, 160)}


def finobe(s: str) -> str:
    """Same length: ROBLOX/Roblox -> Finobe, roblox -> finobe, www.roblox.com -> www.finobe.wtf."""
    return (s.replace("www.roblox.com", "www.finobe.wtf").replace("ROBLOX", "Finobe")
             .replace("Roblox", "Finobe").replace("roblox", "finobe"))


# ---------------------------------------------------------------- images

def _font(size):
    from PIL import ImageFont
    for f in ("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
              "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf",
              "C:/Windows/Fonts/arialbd.ttf"):
        if Path(f).exists():
            return ImageFont.truetype(f, size)
    return ImageFont.load_default()


def logo(size: int):
    from PIL import Image
    im = Image.open(LOGO).convert("RGBA")
    im = im.crop(im.getbbox())
    im.thumbnail((size, size), Image.LANCZOS)
    out = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    out.paste(im, ((size - im.width) // 2, (size - im.height) // 2), im)
    return out


def wordmark(w: int, h: int):
    """Round logo + 'Finobe' in the logo's pink with a white outline, fitted to w x h."""
    from PIL import Image, ImageDraw
    pad = max(2, h // 16)
    lh = h - 2 * pad
    mark = logo(lh)
    font_size = int(lh * 0.8)
    while True:
        font = _font(font_size)
        stroke = max(2, font_size // 12)
        box = ImageDraw.Draw(Image.new("RGBA", (1, 1))).textbbox((0, 0), "Finobe", font=font, stroke_width=stroke)
        tw, th = box[2] - box[0], box[3] - box[1]
        total = lh + lh // 6 + tw
        if (total <= w - 2 * pad and th <= lh) or font_size < 8:
            break
        font_size -= 2
    out = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    x = (w - total) // 2
    out.paste(mark, (x, pad), mark)
    ImageDraw.Draw(out).text((x + lh + lh // 6 - box[0], (h - th) // 2 - box[1]), "Finobe", font=font,
                             fill=PINK, stroke_width=stroke, stroke_fill=WHITE)
    return out


def replace_images(folder: Path):
    from PIL import Image
    for rel, kind in IMAGES.items():
        p = folder / rel
        if not p.exists():
            print(f"  {rel}: missing, skipped"); continue
        old = Image.open(p)
        w, h = old.size
        if kind == "wordmark":
            # draw where the Roblox wordmark's artwork is (fixed, so re-running gives the same image)
            box = WORDMARK_BOX.get(rel, (0, 0, w, h))
            new = Image.new("RGBA", (w, h), (0, 0, 0, 0))
            mark = wordmark(box[2] - box[0], box[3] - box[1])
            new.paste(mark, box[:2], mark)
        else:
            new = logo(min(w, h)).resize((w, h))
        if kind == "logo-white" or p.suffix.lower() == ".bmp":
            bg = Image.new("RGBA", (w, h), WHITE)
            bg.alpha_composite(new)
            new = bg.convert(old.mode if old.mode in ("RGB", "L", "P") else "RGB")
        new.save(p)
        print(f"  {rel}: {kind} {w}x{h}")


# ---------------------------------------------------------------- exe

def replace_text(b: bytearray):
    n = 0
    for s in EXE_TEXT:
        old, new = b"\0" + s.encode() + b"\0", b"\0" + finobe(s).encode() + b"\0"
        c = b.count(old)
        b[:] = b.replace(old, new); n += c
    for s in EXE_TEXT_W:
        # UTF-16 strings sit on 2-byte boundaries, NUL (00 00) on both sides
        old, new = s.encode("utf-16-le"), finobe(s).encode("utf-16-le")
        i = 0
        while (i := b.find(old, i)) >= 0:
            if i % 2 == 0 and b[i - 2:i] == b"\0\0" and b[i + len(old):i + len(old) + 2] == b"\0\0":
                b[i:i + len(old)] = new; n += 1
            i += len(old)
    print(f"  exe text: {n} string(s)")


def _dib(img, size):
    """32-bit icon DIB: BITMAPINFOHEADER + bottom-up BGRA + empty AND mask."""
    img = img.resize((size, size))
    px = img.tobytes("raw", "BGRA")
    rows = [px[y * size * 4:(y + 1) * size * 4] for y in range(size)][::-1]
    mask_row = ((size + 31) // 32) * 4
    return (struct.pack("<IiiHHIIiiII", 40, size, size * 2, 1, 32, 0, size * size * 4, 0, 0, 0, 0)
            + b"".join(rows) + b"\0" * (mask_row * size))


def replace_icon(b: bytearray):
    import pefile
    pe = pefile.PE(data=bytes(b), fast_load=False)
    src = logo(256)
    done = 0
    for t in pe.DIRECTORY_ENTRY_RESOURCE.entries:
        if t.id != 3:  # RT_ICON
            continue
        for e in t.directory.entries:
            for l in e.directory.entries:
                d = l.data.struct
                off = pe.get_offset_from_rva(d.OffsetToData)
                data = bytes(b[off:off + d.Size])
                if data[:4] == b"\x89PNG":
                    continue
                w, bpp = struct.unpack_from("<i", data, 4)[0], struct.unpack_from("<H", data, 14)[0]
                if bpp != 32:
                    continue
                new = _dib(src, w)
                if len(new) != d.Size:
                    sys.exit(f"icon {w}x{w}: {len(new)} bytes, resource holds {d.Size}")
                b[off:off + d.Size] = new; done += 1
    print(f"  exe icon: {done} size(s)")


def corescript_entries(b: bytes):
    import pefile
    pe = pefile.PE(data=b, fast_load=True)
    base = pe.OPTIONAL_HEADER.ImageBase

    def off2va(o):
        for s in pe.sections:
            if s.PointerToRawData <= o < s.PointerToRawData + s.SizeOfRawData:
                return base + s.VirtualAddress + o - s.PointerToRawData

    def va2off(v):
        for s in pe.sections:
            if s.VirtualAddress <= v - base < s.VirtualAddress + max(s.Misc_VirtualSize, s.SizeOfRawData):
                return s.PointerToRawData + v - base - s.VirtualAddress

    def name_at(va):
        o = va2off(va)
        if o is None:
            return None
        s = b[o:b.find(b"\0", o)]
        return s if 0 < len(s) < 80 and all(32 < c < 127 for c in s) else None

    def valid(e):
        n, d, sz = struct.unpack_from("<III", b, e)
        return name_at(n) is not None and va2off(d) is not None and 0 < sz < 2_000_000

    anchor = codecs.encode("PlayerlistModule", "rot13").encode() + b"\0"
    ptr = struct.pack("<I", off2va(b.find(anchor)))
    e = next(i for i in range(0, len(b) - 12, 4) if b[i:i + 4] == ptr)
    while valid(e - 12):
        e -= 12
    out = []
    while valid(e):
        n, d, sz = struct.unpack_from("<III", b, e)
        nxt = struct.unpack_from("<III", b, e + 12)[1] if valid(e + 12) else None
        out.append((e, codecs.decode(name_at(n).decode(), "rot13"), va2off(d), sz,
                    (nxt - d) if nxt and nxt > d else sz))
        e += 12
    return out


def replace_corescript_text(b: bytearray):
    for entry, name, off, size, room in corescript_entries(bytes(b)):
        if name not in CORESCRIPT_TEXT:
            continue
        prefix, strings = rsb1.split_strings(rsb1.decode(bytes(b[off:off + size])))
        changed = 0
        for i, s in enumerate(strings):
            t = s.decode("latin1")
            # sentences/titles only: identifiers (RobloxGui, com.roblox.client.) and asset URLs stay
            if " " in t and ("roblox" in t.lower()):
                strings[i] = finobe(t).encode("latin1"); changed += 1
        if not changed:
            print(f"  {name}: already done"); continue
        blob = rsb1.encode(rsb1.join_strings(prefix, strings))
        if len(blob) > room:
            sys.exit(f"{name}: {len(blob)} bytes, only {room} fit")
        b[off:off + room] = blob + b"\0" * (room - len(blob))
        struct.pack_into("<I", b, entry + 8, len(blob))
        assert rsb1.split_strings(rsb1.decode(bytes(b[off:off + len(blob)])))[1] == strings
        print(f"  {name}: {changed} string(s)")


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    folder = Path(sys.argv[1])
    exe = folder / "RobloxPlayerBeta.exe"
    if not exe.is_file():
        sys.exit(f"{folder} doesn't look like a client folder (no RobloxPlayerBeta.exe)")

    b = bytearray(exe.read_bytes())
    print("RobloxPlayerBeta.exe")
    replace_text(b)
    replace_icon(b)
    replace_corescript_text(b)
    exe.write_bytes(bytes(b))
    print("images")
    replace_images(folder)


if __name__ == "__main__":
    main()
