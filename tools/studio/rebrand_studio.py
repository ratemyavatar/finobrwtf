#!/usr/bin/env python3
import io, re, struct, sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "client"))
import rebrand

FONTS = HERE.parents[1] / "public" / "fonts" / "OTF"
DARK, PINK, WHITE = (43, 45, 49, 255), (248, 40, 96, 255), (255, 255, 255, 255)

SKIP = ("ROBLOXAPP", "Not affiliated", "Roblox sampleRunningJobCount", "Roblox-Content", "Roblox TaskScheduler",
        "Roblox locked in", "RobloxCrashDump", "Clearing RobloxReplicatedStorage",
        "LoadCommonModules", "RobloxTreeWidgetItem", "Roblox Quick Access Config",
        "Roblox Studio Key Mapping", "Roblox Studio Mouse Mapping")
WIDE = ["Roblox Studio", "Roblox Corporation", " 2012 Roblox Corporation", "RobloxStudio.exe", "Roblox Application"]
LEGAL = {
    b'<p>ROBLOX, "Online Building Toy", characters, logos, names, and all related indicia ':
        b"<p>Finobe Studio is a 2016 build of Studio patched to work with finobe.wtf. ",
    b"are trademarks of ROBLOX Corporation %c2014. ": b"Not affiliated with Roblox Corporation. ",
    b"US Patents 7,874,921. Patents pending.</p>": b"</p>",
}
WORD = re.compile(rb"(?<![A-Za-z])(ROBLOX|Roblox)(?![a-z])")


def brand(s: bytes) -> bytes:
    return WORD.sub(lambda m: b"FINOBE" if m.group(1) == b"ROBLOX" else b"Finobe", s)


def replace_text(b: bytearray):
    n = 0
    for old, new in LEGAL.items():
        i = b.find(b"\0" + old + b"\0")
        if i >= 0:
            b[i + 1:i + 1 + len(old)] = new.ljust(len(old), b" ")
            n += 1
    for m in re.finditer(rb"(?<=\0)[\x09\x0a\x0d\x20-\x7e]{4,}(?=\0)", bytes(b)):
        s = m.group()
        if b" " not in s or not WORD.search(s) or b"\\" in s or b"Action " in s or s.startswith(tuple(x.encode() for x in SKIP)):
            continue
        new = brand(s)
        b[m.start():m.end()] = new.replace(b"FINOBE", b"Finobe")
        n += 1
    for s in WIDE:
        old = s.encode("utf-16-le")
        new = s.replace("Roblox", "Finobe").encode("utf-16-le")
        i = 0
        while (i := b.find(old, i)) >= 0:
            if i % 2 == 0 and b[i - 2:i] in (b"\0\0", b"\xa9\0") and b[i + len(old):i + len(old) + 2] == b"\0\0":
                b[i:i + len(old)] = new
                n += 1
            i += len(old)
    print(f"  text: {n} string(s)")


def font(size, bold=True):
    from PIL import ImageFont
    f = FONTS / ("SourceSansPro-Semibold.otf" if bold else "SourceSansPro-Regular.otf")
    return ImageFont.truetype(str(f), size) if f.exists() else rebrand._font(size)


def splash(w: int, h: int):
    from PIL import Image, ImageDraw
    im = Image.new("RGBA", (w, h), DARK)
    d = ImageDraw.Draw(im)
    d.rectangle([0, h - 6, w, h], fill=PINK)
    size = int(h * 0.42)
    mark = rebrand.logo(size)
    im.paste(mark, (int(w * 0.08), (h - size) // 2 - 6), mark)
    x = int(w * 0.08) + size + int(w * 0.04)
    big, small = font(int(h * 0.15)), font(int(h * 0.065), False)
    d.text((x, h // 2 - int(h * 0.17)), "Finobe Studio", font=big, fill=WHITE)
    d.text((x, h // 2 + int(h * 0.02)), "Build games for finobe.wtf", font=small, fill=(185, 188, 195, 255))
    return im.convert("RGB")


def png_bytes(im) -> bytes:
    out = io.BytesIO()
    im.save(out, "PNG", optimize=True)
    return out.getvalue()


def replace_resources(b: bytearray):
    import pefile
    from PIL import Image
    rsrc = [(x.PointerToRawData, x.PointerToRawData + x.SizeOfRawData)
            for x in pefile.PE(data=bytes(b), fast_load=True).sections if x.Name.rstrip(b"\0") == b".rsrc"]
    done = 0
    for m in list(re.finditer(rb"\x89PNG\r\n\x1a\n", bytes(b))):
        o = m.start()
        if any(lo <= o < hi for lo, hi in rsrc):
            continue
        ln = struct.unpack(">I", b[o - 4:o])[0]
        if ln < 1000 or ln > 2_000_000:
            continue
        try:
            old = Image.open(io.BytesIO(bytes(b[o:o + ln])))
            old.load()
        except Exception:
            continue
        w, h = old.size
        if (w, h) in ((600, 300), (575, 326)) and ln > 20000:
            new = png_bytes(splash(w, h))
        elif (w, h) == (256, 256) and ln > 20000:
            new = png_bytes(rebrand.logo(256))
        else:
            continue
        if len(new) > ln:
            sys.exit(f"resource {w}x{h} at {o:#x}: {len(new)} bytes, only {ln} fit")
        b[o:o + ln] = new + b"\0" * (ln - len(new))
        struct.pack_into(">I", b, o - 4, len(new))
        done += 1
    print(f"  qt resources: {done} image(s)")


def replace_icon(b: bytearray):
    import pefile
    pe = pefile.PE(data=bytes(b), fast_load=False)
    done = 0
    for t in pe.DIRECTORY_ENTRY_RESOURCE.entries:
        if t.id != 3:
            continue
        for e in t.directory.entries:
            for l in e.directory.entries:
                d = l.data.struct
                off = pe.get_offset_from_rva(d.OffsetToData)
                data = bytes(b[off:off + d.Size])
                if data[:4] == b"\x89PNG":
                    w = struct.unpack(">I", data[16:20])[0]
                    new = png_bytes(rebrand.logo(w))
                    if len(new) > d.Size:
                        continue
                    b[off:off + d.Size] = new + b"\0" * (d.Size - len(new))
                    done += 1
                    continue
                w, bpp = struct.unpack_from("<i", data, 4)[0], struct.unpack_from("<H", data, 14)[0]
                if bpp != 32:
                    continue
                new = rebrand._dib(rebrand.logo(256), w)
                if len(new) != d.Size:
                    continue
                b[off:off + d.Size] = new
                done += 1
    print(f"  icon: {done} size(s)")


def replace_scripts(folder: Path):
    n = 0
    for p in list((folder / "content" / "scripts").rglob("*.lua")):
        s = p.read_bytes()
        out = re.sub(rb'"[^"\n]*"', lambda m: brand(m.group()).replace(b"FINOBE", b"Finobe") if b" " in m.group() else m.group(), s)
        if out != s:
            p.write_bytes(out)
            n += 1
    print(f"  scripts: {n} file(s)")


def main():
    if len(sys.argv) != 2:
        sys.exit("usage: rebrand_studio.py <studio folder>")
    folder = Path(sys.argv[1])
    exe = folder / "RobloxStudioBeta.exe"
    if not exe.is_file():
        sys.exit(f"{folder} doesn't look like a studio folder (no RobloxStudioBeta.exe)")
    b = bytearray(exe.read_bytes())
    print("RobloxStudioBeta.exe")
    replace_text(b)
    replace_resources(b)
    replace_icon(b)
    exe.write_bytes(bytes(b))
    print("content")
    rebrand.replace_images(folder)
    replace_scripts(folder)


if __name__ == "__main__":
    main()
