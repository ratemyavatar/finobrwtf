import sys, struct, codecs, pefile

def tables(path):
    b = open(path, 'rb').read()
    pe = pefile.PE(data=b, fast_load=True)
    base = pe.OPTIONAL_HEADER.ImageBase

    def va2off(v):
        for s in pe.sections:
            if s.VirtualAddress <= v - base < s.VirtualAddress + s.SizeOfRawData:
                return s.PointerToRawData + v - base - s.VirtualAddress

    def off2va(o):
        for s in pe.sections:
            if s.PointerToRawData <= o < s.PointerToRawData + s.SizeOfRawData:
                return base + s.VirtualAddress + o - s.PointerToRawData

    def cstr(v):
        o = va2off(v)
        if o is None:
            return None
        e = b.find(b'\0', o, o + 200)
        s = b[o:e] if e > o else b''
        return s.decode() if s and all(32 <= c < 127 for c in s) else None

    def entry(i):
        n, d, sz = struct.unpack_from('<III', b, i)
        nm = cstr(n)
        if nm is None or va2off(d) is None or not 0 < sz < 4000000:
            return None
        o = va2off(d)
        return nm, b[o:o + sz]

    name = b'\0' + codecs.encode('PlayerlistModule', 'rot13').encode() + b'\0'
    o = b.find(name)
    if o < 0:
        sys.exit('core script table not found')
    p = struct.pack('<I', off2va(o + 1))
    i = next(i for i in range(0, len(b) - 12, 4) if b[i:i + 4] == p)
    while entry(i - 12):
        i -= 12
    rows = []
    while entry(i):
        rows.append(entry(i))
        i += 12
    plain = [codecs.encode(n, 'rot13') for n, _ in rows]
    cut = next(k for k in range(1, len(rows)) if plain[k].lower() < plain[k - 1].lower())
    return rows[:cut], rows[cut:]

def emit(out, var, rows):
    for k, (_, data) in enumerate(rows):
        out.write('static const unsigned char %s_%d[] = {%s};\n' % (var, k, ','.join(str(c) for c in data)))
    out.write('static const CoreScriptBytecode %s[] = {\n' % var)
    for k, (n, data) in enumerate(rows):
        out.write('    {"%s", %s_%d, %d},\n' % (n, var, k, len(data)))
    out.write('};\n')

core, mods = tables(sys.argv[1])
with open(sys.argv[2], 'w') as out:
    emit(out, 'gCoreScripts', core)
    emit(out, 'gCoreModuleScripts', mods)
print(len(core), 'core scripts,', len(mods), 'modules')
