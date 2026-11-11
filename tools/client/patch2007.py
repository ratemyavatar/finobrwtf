#!/usr/bin/env python3
import hashlib
import shutil
import struct
import sys

ORIGINAL_SHA256 = 'f16069abb753483338ec0ff3a508dc2ee7c696f30997664816446e80863096cc'
SECTION = b'.fnb07\0\0'
IMAGE_BASE = 0x400000
MAX_SPLIT_PIECES = 0x8000

READ_COMPRESSED_UINT = 0x4bd830
SPLIT_HEADER_HOOK = 0x4e3b26
SPLIT_HEADER_OK = 0x4e3b4e
SPLIT_HEADER_FAIL = 0x4e3b2f
DUPLICATE_HOOK = 0x4e5560
DUPLICATE_RESUME = 0x4e5567
DUPLICATE_SKIP = 0x4e5578
OPERATOR_DELETE = 0x699ef0
POOL_RELEASE = 0x4e2d20
POOL_OFFSET = 0x7b0
EXPIRE_RELIABILITY_JNE = 0x4e58c0
F_PARSER_PICK = 0x60313f
LUAY_PARSER = 0x652ea0

EXPECTED = {
    SPLIT_HEADER_HOOK: bytes.fromhex('e805 9dfd ff84 c075 1f'.replace(' ', '')),
    DUPLICATE_HOOK: bytes.fromhex('7516 3b47 048b cf'.replace(' ', '')),
    EXPIRE_RELIABILITY_JNE: bytes.fromhex('0f85 a200 0000'.replace(' ', '')),
    F_PARSER_PICK: bytes.fromhex('b890 3965 00'.replace(' ', '')),
}


def align(value, alignment):
    return (value + alignment - 1) // alignment * alignment


class Pe:
    def __init__(self, buf):
        self.buf = buf
        self.e_lfanew = struct.unpack_from('<I', buf, 0x3C)[0]
        if buf[self.e_lfanew:self.e_lfanew + 4] != b'PE\0\0':
            raise ValueError('not a PE file')
        self.nsec = struct.unpack_from('<H', buf, self.e_lfanew + 6)[0]
        opt_size = struct.unpack_from('<H', buf, self.e_lfanew + 20)[0]
        self.opt = self.e_lfanew + 24
        self.sec_table = self.opt + opt_size
        self.sect_align, self.file_align = struct.unpack_from('<II', buf, self.opt + 32)
        self.size_of_headers = struct.unpack_from('<I', buf, self.opt + 60)[0]
        self.sections = [struct.unpack_from('<8sIIII', buf, self.sec_table + i * 40) for i in range(self.nsec)]

    def offset(self, va):
        rva = va - IMAGE_BASE
        for _, vsize, vaddr, raw_size, raw_ptr in self.sections:
            if vaddr <= rva < vaddr + max(vsize, raw_size):
                return raw_ptr + (rva - vaddr)
        raise ValueError(f'{va:#x} is outside every section')

    def has_section(self, name):
        return any(s[0] == name for s in self.sections)


class Asm:
    def __init__(self, base):
        self.base = base
        self.code = bytearray()
        self.labels = {}
        self.fixups = []

    def here(self):
        return self.base + len(self.code)

    def raw(self, data):
        self.code += data

    def label(self, name):
        self.labels[name] = self.here()

    def rel32(self, opcode, target):
        self.code += opcode
        self.fixups.append((len(self.code), target))
        self.code += b'\0\0\0\0'

    def finish(self):
        for pos, target in self.fixups:
            dest = self.labels[target] if isinstance(target, str) else target
            struct.pack_into('<i', self.code, pos, dest - (self.base + pos + 4))
        return bytes(self.code)


def build_cave(base):
    asm = Asm(base)
    asm.label('split')
    asm.rel32(b'\xE8', READ_COMPRESSED_UINT)
    asm.raw(b'\x84\xC0')
    asm.rel32(b'\x0F\x84', 'split_fail')
    asm.raw(b'\x8B\x46\x24')
    asm.raw(b'\x3D' + struct.pack('<I', MAX_SPLIT_PIECES))
    asm.rel32(b'\x0F\x87', 'split_fail')
    asm.raw(b'\x39\x46\x20')
    asm.rel32(b'\x0F\x83', 'split_fail')
    asm.rel32(b'\xE9', SPLIT_HEADER_OK)
    asm.label('split_fail')
    asm.rel32(b'\xE9', SPLIT_HEADER_FAIL)

    asm.label('dup')
    asm.rel32(b'\x0F\x85', 'dup_swap')
    asm.raw(b'\x3B\x47\x04')
    asm.raw(b'\x8B\xCF')
    asm.rel32(b'\xE9', DUPLICATE_RESUME)
    asm.label('dup_swap')
    asm.raw(b'\x8B\x0F')
    asm.raw(b'\x8D\x0C\x81')
    asm.raw(b'\x8B\x11')
    asm.raw(b'\x89\x19')
    asm.raw(b'\x52')
    asm.raw(b'\xFF\x72\x3C')
    asm.rel32(b'\xE8', OPERATOR_DELETE)
    asm.raw(b'\x83\xC4\x04')
    asm.raw(b'\x5A')
    asm.raw(b'\x8B\x4C\x24\x14')
    asm.raw(b'\x81\xC1' + struct.pack('<I', POOL_OFFSET))
    asm.raw(b'\x52')
    asm.rel32(b'\xE8', POOL_RELEASE)
    asm.rel32(b'\xE9', DUPLICATE_SKIP)
    code = asm.finish()
    return code, asm.labels


def status(data):
    pe = Pe(bytearray(data))
    if pe.has_section(SECTION):
        return 'patched'
    if hashlib.sha256(data).hexdigest() == ORIGINAL_SHA256:
        return 'vulnerable'
    for va, expected in EXPECTED.items():
        try:
            off = pe.offset(va)
        except ValueError:
            return 'unknown build'
        if bytes(data[off:off + len(expected)]) != expected:
            return 'unknown build'
    return 'vulnerable'


def jump(buf, pe, at, target, length):
    off = pe.offset(at)
    buf[off] = 0xE9
    struct.pack_into('<i', buf, off + 1, target - (at + 5))
    for i in range(5, length):
        buf[off + i] = 0x90


def patch(data):
    if status(data) != 'vulnerable':
        raise ValueError(status(data))
    buf = bytearray(data)
    pe = Pe(buf)
    for va, expected in EXPECTED.items():
        off = pe.offset(va)
        if bytes(buf[off:off + len(expected)]) != expected:
            raise ValueError(f'unexpected bytes at {va:#x}')

    new_sec = pe.sec_table + pe.nsec * 40
    if new_sec + 40 > pe.size_of_headers:
        raise ValueError('no room for another section header')
    last_end = max(vaddr + vsize for _, vsize, vaddr, _, _ in pe.sections)
    cave_rva = align(last_end, pe.sect_align)
    code, labels = build_cave(IMAGE_BASE + cave_rva)
    raw_size = align(len(code), pe.file_align)
    cave_off = align(len(buf), pe.file_align)

    struct.pack_into('<8sIIIIIIHHI', buf, new_sec, SECTION, len(code), cave_rva, raw_size, cave_off, 0, 0, 0, 0, 0x60000020)
    struct.pack_into('<H', buf, pe.e_lfanew + 6, pe.nsec + 1)
    struct.pack_into('<I', buf, pe.opt + 56, align(cave_rva + len(code), pe.sect_align))
    struct.pack_into('<I', buf, pe.opt + 64, 0)

    jump(buf, pe, SPLIT_HEADER_HOOK, labels['split'], 9)
    jump(buf, pe, DUPLICATE_HOOK, labels['dup'], 7)
    off = pe.offset(EXPIRE_RELIABILITY_JNE)
    buf[off:off + 6] = b'\x90' * 6
    off = pe.offset(F_PARSER_PICK)
    struct.pack_into('<I', buf, off + 1, LUAY_PARSER)

    buf += b'\0' * (cave_off - len(buf))
    buf += code + b'\0' * (raw_size - len(code))
    return bytes(buf)


def main(argv):
    check = '--check' in argv
    files = [a for a in argv if a != '--check']
    if not files:
        print('usage: patch2007.py [--check] Roblox.exe [more.exe ...]')
        return 2
    rc = 0
    for name in files:
        data = open(name, 'rb').read()
        state = status(data)
        if check or state != 'vulnerable':
            print(f'{name}: {state}')
            if check and state != 'patched':
                rc = 1
            continue
        out = patch(data)
        shutil.copyfile(name, name + '.bak')
        open(name, 'wb').write(out)
        print(f'{name}: patched, original kept as {name}.bak')
    return rc


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
