#!/usr/bin/env python3
"""
RakFail fix for 2012-2018 clients and RCCService (https://my-thos.github.io/rakwrite/patcher).

RakNet copies each piece of a split packet into the reassembly buffer without checking that
the piece fits, so a crafted packet writes past the buffer and crashes the game server.
This finds that copy (three known code layouts), adds a small `.rknpch` section with the
missing bounds check and jumps to it; a piece that doesn't fit is skipped instead of copied.
Same byte patterns and check as the published patcher.

  python3 tools/client/rakfail_patch.py RCCService.exe [more.exe ...]      patches in place (keeps a .bak)
  python3 tools/client/rakfail_patch.py --check RobloxApp.exe              only says whether it's vulnerable
"""
import struct, sys, shutil

def align(v, a):
    return (v + a - 1) // a * a

VARIANTS = [
    dict(sig=bytes([0x8B,0x50,0x18,0x8B,0x48,0x3C,0x83,0xC2,0x07,0xC1,0xEA,0x03,
                    0x52,0x8B,0x50,0x10,0x0F,0xAF,0xD3,0x03,0x56,0x3C,0x51,0x52,0xE8]),
         patch_off=12, jmpback_off=19, cleanup_delta=38, cleanup_marker=b'\x33\xED',
         cave=bytes([0x52, 0x8B,0x50,0x10, 0x0F,0xAF,0xD3, 0x51, 0x52,
                     0x8B,0x4E,0x18, 0x83,0xC1,0x07, 0xC1,0xE9,0x03,
                     0x8B,0x54,0x24,0x08, 0x03,0x14,0x24, 0x39,0xCA,
                     0x5A, 0x59, 0x77,0x05,
                     0xE9, 0,0,0,0,
                     0x83,0xC4,0x04, 0x45,
                     0xE9, 0,0,0,0,
                     0x90, 0x90, 0x90]),
         jmp1=(32, 36), jmp2=(41, 45), nops=2),
    dict(sig=bytes([0x8B,0x41,0x18,0x83,0xC0,0x07,0xC1,0xE8,0x03,
                    0x50,0x8B,0x41,0x10,0xFF,0x71,0x3C,
                    0x0F,0xAF,0xC3,0x8B,0x4D,0x08,0x03,0x41,0x3C,0x50,0xE8]),
         patch_off=16, jmpback_off=22, cleanup_delta=38, cleanup_marker=b'\x33\xDB',
         cave=bytes([0x0F,0xAF,0xC3, 0x8B,0x4D,0x08, 0x50, 0x52,
                     0x8B,0x51,0x18, 0x83,0xC2,0x07, 0xC1,0xEA,0x03,
                     0x8B,0x44,0x24,0x0C, 0x03,0x44,0x24,0x04, 0x39,0xD0,
                     0x5A, 0x58, 0x77,0x05,
                     0xE9, 0,0,0,0,
                     0x83,0xC4,0x08, 0x46,
                     0xE9, 0,0,0,0]),
         jmp1=(32, 36), jmp2=(41, 45), nops=1),
    dict(sig=bytes([0x8B,0x57,0x0C,0x8B,0x04,0xAA,0x8B,0x48,0x14,0x8B,0x50,0x3C,0x8B,0x40,0x0C,
                    0x0F,0xAF,0xC3,0x03,0x46,0x3C,0x83,0xC1,0x07,0xC1,0xE9,0x03,0x51,0x52,0x50,0xE8]),
         patch_off=15, jmpback_off=21, cleanup_delta=44, cleanup_marker=b'\x33\xED',
         cave=bytes([0x0F,0xAF,0xC3, 0x50, 0x52, 0x51,
                     0x89,0xCA, 0x83,0xC2,0x07, 0xC1,0xEA,0x03,
                     0x8B,0x4E,0x14, 0x83,0xC1,0x07, 0xC1,0xE9,0x03,
                     0x8B,0x44,0x24,0x08, 0x03,0xC2, 0x39,0xC8,
                     0x59, 0x5A, 0x58, 0x77,0x08,
                     0x03,0x46,0x3C,
                     0xE9, 0,0,0,0,
                     0x45,
                     0xE9, 0,0,0,0]),
         jmp1=(40, 44), jmp2=(46, 50), nops=1),
]

SECTION = b'.rknpch\0'

def status(data):
    if SECTION in data[:0x1000]:
        return 'patched', None, None
    for i, v in enumerate(VARIANTS):
        off = data.find(v['sig'])
        if off >= 0:
            return 'vulnerable', v, off
    return 'not found', None, None

def patch(data):
    state, v, sig_off = status(data)
    if state != 'vulnerable':
        raise ValueError(state)
    buf = bytearray(data)
    e_lfanew = struct.unpack_from('<I', buf, 0x3C)[0]
    if buf[e_lfanew:e_lfanew + 4] != b'PE\0\0':
        raise ValueError('not a PE file')
    nsec = struct.unpack_from('<H', buf, e_lfanew + 6)[0]
    opt_size = struct.unpack_from('<H', buf, e_lfanew + 20)[0]
    opt = e_lfanew + 24
    sec_table = opt + opt_size
    sect_align, file_align = struct.unpack_from('<II', buf, opt + 32)
    size_of_headers = struct.unpack_from('<I', buf, opt + 60)[0]
    sections = [struct.unpack_from('<8sIIII', buf, sec_table + i * 40) for i in range(nsec)]
    last_end = max(va + vs for _, vs, va, _, _ in sections)

    def rva(foff):
        for _, vs, va, raw_size, raw_ptr in sections:
            if raw_ptr <= foff < raw_ptr + raw_size:
                return va + (foff - raw_ptr)
        raise ValueError('offset outside every section')

    sig_rva = rva(sig_off)
    patch_rva = sig_rva + v['patch_off']
    jmpback_rva = sig_rva + v['jmpback_off']
    start = sig_off + v['cleanup_delta']
    hit = buf.find(v['cleanup_marker'], start, start + 17)
    if hit < 0:
        raise ValueError('loop exit not found')
    loop_cond_rva = rva(hit) - 5

    cave = bytearray(v['cave'])
    cave_rva = align(last_end, sect_align)
    raw_cave = align(len(cave), file_align)
    cave_foff = align(len(buf), file_align)
    new_sec = sec_table + nsec * 40
    if new_sec + 40 > size_of_headers:
        raise ValueError('no room for another section header')
    struct.pack_into('<i', cave, v['jmp1'][0], jmpback_rva - (cave_rva + v['jmp1'][1]))
    struct.pack_into('<i', cave, v['jmp2'][0], loop_cond_rva - (cave_rva + v['jmp2'][1]))

    struct.pack_into('<8sIIIIIIHHI', buf, new_sec, SECTION, len(cave), cave_rva, raw_cave, cave_foff, 0, 0, 0, 0, 0xE0000020)
    struct.pack_into('<H', buf, e_lfanew + 6, nsec + 1)
    struct.pack_into('<I', buf, opt + 56, align(cave_rva + len(cave), sect_align))   # SizeOfImage

    p = sig_off + v['patch_off']
    buf[p] = 0xE9
    struct.pack_into('<i', buf, p + 1, cave_rva - (patch_rva + 5))
    for i in range(v['nops']):
        buf[p + 5 + i] = 0x90

    buf += b'\0' * (cave_foff - len(buf))
    buf += cave + b'\0' * (raw_cave - len(cave))
    return bytes(buf), VARIANTS.index(v) + 1

def main(argv):
    check = '--check' in argv
    files = [a for a in argv if a != '--check']
    if not files:
        print(__doc__)
        return 2
    rc = 0
    for f in files:
        data = open(f, 'rb').read()
        state, v, _ = status(data)
        if check or state != 'vulnerable':
            print(f'{f}: {state}' + (f' (layout {VARIANTS.index(v) + 1})' if v else ''))
            rc |= state == 'vulnerable' and check
            continue
        out, layout = patch(data)
        shutil.copyfile(f, f + '.bak')
        open(f, 'wb').write(out)
        print(f'{f}: patched (layout {layout}), original kept as {f}.bak')
    return rc

if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
