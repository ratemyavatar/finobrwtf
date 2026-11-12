import struct

import lz4.block
import zstandard

MAGIC = b'<roblox!\x89\xff\r\n\x1a\n'


class Inst:
    __slots__ = ('cls', 'ref', 'props', 'children', 'parent', 'service')

    def __init__(self, cls, ref):
        self.cls = cls
        self.ref = ref
        self.props = {}
        self.children = []
        self.parent = None
        self.service = False

    def get(self, name, default=None):
        p = self.props.get(name)
        return default if p is None else p[1]

    def set(self, name, kind, value):
        self.props[name] = (kind, value)

    def name(self):
        n = self.get('Name', self.cls)
        if isinstance(n, bytes):
            return n.decode('utf-8', 'replace')
        return n

    def path(self):
        parts = []
        o = self
        while o is not None:
            parts.append(o.name())
            o = o.parent
        return '.'.join(reversed(parts))

    def descendants(self):
        stack = list(reversed(self.children))
        while stack:
            o = stack.pop()
            yield o
            stack.extend(reversed(o.children))


class Reader:
    def __init__(self, data):
        self.d = data
        self.p = 0

    def u8(self):
        v = self.d[self.p]
        self.p += 1
        return v

    def u16(self):
        v = struct.unpack_from('<H', self.d, self.p)[0]
        self.p += 2
        return v

    def u32(self):
        v = struct.unpack_from('<I', self.d, self.p)[0]
        self.p += 4
        return v

    def i32(self):
        v = struct.unpack_from('<i', self.d, self.p)[0]
        self.p += 4
        return v

    def f32(self):
        v = struct.unpack_from('<f', self.d, self.p)[0]
        self.p += 4
        return v

    def f64(self):
        v = struct.unpack_from('<d', self.d, self.p)[0]
        self.p += 8
        return v

    def bytes(self, n):
        v = self.d[self.p:self.p + n]
        self.p += n
        return v

    def string(self):
        n = self.u32()
        return self.bytes(n)

    def interleaved(self, count, width):
        raw = self.bytes(count * width)
        out = []
        for i in range(count):
            out.append(bytes(raw[j * count + i] for j in range(width)))
        return out

    def ints(self, count):
        out = []
        for b in self.interleaved(count, 4):
            v = struct.unpack('>I', b)[0]
            out.append((v >> 1) ^ -(v & 1))
        return out

    def uints(self, count):
        return [struct.unpack('>I', b)[0] for b in self.interleaved(count, 4)]

    def floats(self, count):
        out = []
        for b in self.interleaved(count, 4):
            v = struct.unpack('>I', b)[0]
            v = ((v >> 1) | ((v & 1) << 31)) & 0xFFFFFFFF
            out.append(struct.unpack('<f', struct.pack('<I', v))[0])
        return out

    def int64s(self, count):
        out = []
        for b in self.interleaved(count, 8):
            v = struct.unpack('>Q', b)[0]
            out.append((v >> 1) ^ -(v & 1))
        return out

    def refs(self, count):
        vals = self.ints(count)
        out = []
        acc = 0
        for v in vals:
            acc += v
            out.append(acc)
        return out


ROTATIONS = {
    0x02: (1, 0, 0, 0, 1, 0, 0, 0, 1), 0x03: (1, 0, 0, 0, 0, -1, 0, 1, 0), 0x05: (1, 0, 0, 0, -1, 0, 0, 0, -1),
    0x06: (1, 0, 0, 0, 0, 1, 0, -1, 0), 0x07: (0, 1, 0, 1, 0, 0, 0, 0, -1), 0x09: (0, 0, 1, 1, 0, 0, 0, 1, 0),
    0x0a: (0, -1, 0, 1, 0, 0, 0, 0, 1), 0x0c: (0, 0, -1, 1, 0, 0, 0, -1, 0), 0x0d: (0, 1, 0, 0, 0, 1, 1, 0, 0),
    0x0e: (0, 0, -1, 0, 1, 0, 1, 0, 0), 0x10: (0, -1, 0, 0, 0, -1, 1, 0, 0), 0x11: (0, 0, 1, 0, -1, 0, 1, 0, 0),
    0x14: (-1, 0, 0, 0, 1, 0, 0, 0, -1), 0x15: (-1, 0, 0, 0, 0, 1, 0, 1, 0), 0x17: (-1, 0, 0, 0, -1, 0, 0, 0, 1),
    0x18: (-1, 0, 0, 0, 0, -1, 0, -1, 0), 0x19: (0, 1, 0, -1, 0, 0, 0, 0, 1), 0x1b: (0, 0, -1, -1, 0, 0, 0, 1, 0),
    0x1c: (0, -1, 0, -1, 0, 0, 0, 0, -1), 0x1e: (0, 0, 1, -1, 0, 0, 0, -1, 0), 0x1f: (0, 1, 0, 0, 0, -1, -1, 0, 0),
    0x20: (0, 0, 1, 0, 1, 0, -1, 0, 0), 0x22: (0, -1, 0, 0, 0, 1, -1, 0, 0), 0x23: (0, 0, -1, 0, -1, 0, -1, 0, 0),
}


def decompress(data):
    out = {}
    chunks = []
    pos = 32
    z = zstandard.ZstdDecompressor()
    while pos < len(data):
        name = data[pos:pos + 4].rstrip(b'\0').decode()
        clen, ulen = struct.unpack_from('<II', data, pos + 4)
        pos += 16
        if clen:
            body = data[pos:pos + clen]
            pos += clen
            if body[:4] == b'\x28\xb5\x2f\xfd':
                raw = z.decompress(body, max_output_size=ulen)
            else:
                raw = lz4.block.decompress(body, uncompressed_size=ulen)
        else:
            raw = data[pos:pos + ulen]
            pos += ulen
        chunks.append((name, raw))
        if name == 'END':
            break
    return chunks


def read_values(r, kind, count, sstr):
    if kind == 0x01 or kind == 0x1D:
        return [r.string() for _ in range(count)]
    if kind == 0x02:
        return [b != 0 for b in r.bytes(count)]
    if kind == 0x03:
        return r.ints(count)
    if kind == 0x04:
        return r.floats(count)
    if kind == 0x05:
        return [r.f64() for _ in range(count)]
    if kind == 0x06:
        s = r.floats(count)
        o = r.ints(count)
        return list(zip(s, o))
    if kind == 0x07:
        sx = r.floats(count)
        sy = r.floats(count)
        ox = r.ints(count)
        oy = r.ints(count)
        return [(sx[i], ox[i], sy[i], oy[i]) for i in range(count)]
    if kind == 0x08:
        return [tuple(r.f32() for _ in range(6)) for _ in range(count)]
    if kind == 0x09 or kind == 0x0A:
        return list(r.bytes(count))
    if kind == 0x0B:
        return r.uints(count)
    if kind == 0x0C:
        a = r.floats(count)
        b = r.floats(count)
        c = r.floats(count)
        return list(zip(a, b, c))
    if kind == 0x0D:
        a = r.floats(count)
        b = r.floats(count)
        return list(zip(a, b))
    if kind == 0x0E:
        a = r.floats(count)
        b = r.floats(count)
        c = r.floats(count)
        return list(zip(a, b, c))
    if kind == 0x0F:
        return [(struct.unpack('<hh', r.bytes(4))) for _ in range(count)]
    if kind == 0x10:
        rots = []
        for _ in range(count):
            rid = r.u8()
            if rid == 0:
                rots.append(tuple(r.f32() for _ in range(9)))
            else:
                rots.append(ROTATIONS.get(rid, (1, 0, 0, 0, 1, 0, 0, 0, 1)))
        x = r.floats(count)
        y = r.floats(count)
        zz = r.floats(count)
        return [(x[i], y[i], zz[i]) + rots[i] for i in range(count)]
    if kind == 0x12:
        return r.uints(count)
    if kind == 0x13:
        return r.refs(count)
    if kind == 0x14:
        return [struct.unpack('<hhh', r.bytes(6)) for _ in range(count)]
    if kind == 0x15:
        out = []
        for _ in range(count):
            n = r.u32()
            out.append([(r.f32(), r.f32(), r.f32()) for _ in range(n)])
        return out
    if kind == 0x16:
        out = []
        for _ in range(count):
            n = r.u32()
            out.append([(r.f32(), r.f32(), r.f32(), r.f32(), r.f32()) for _ in range(n)])
        return out
    if kind == 0x17:
        return [(r.f32(), r.f32()) for _ in range(count)]
    if kind == 0x18:
        a = r.floats(count)
        b = r.floats(count)
        c = r.floats(count)
        d = r.floats(count)
        return list(zip(a, b, c, d))
    if kind == 0x19:
        out = []
        for _ in range(count):
            custom = r.u8()
            if custom & 1:
                n = 6 if custom & 2 else 5
                out.append(tuple(r.f32() for _ in range(n)))
            else:
                out.append(None)
        return out
    if kind == 0x1A:
        a = r.bytes(count)
        b = r.bytes(count)
        c = r.bytes(count)
        return [(a[i], b[i], c[i]) for i in range(count)]
    if kind == 0x1B:
        return r.int64s(count)
    if kind == 0x1C:
        idx = r.uints(count)
        return [sstr[i] if i < len(sstr) else b'' for i in idx]
    if kind == 0x1E:
        r.u8()
        cfs = read_values(r, 0x10, count, sstr)
        r.u8()
        flags = read_values(r, 0x02, count, sstr)
        return [cfs[i] if flags[i] else None for i in range(count)]
    if kind == 0x1F:
        return [b for b in r.interleaved(count, 16)]
    if kind == 0x20:
        out = []
        for _ in range(count):
            fam = r.string()
            weight = r.u16()
            style = r.u8()
            face = r.string()
            out.append((fam, weight, style, face))
        return out
    if kind == 0x21:
        return r.int64s(count)
    if kind == 0x22:
        types = r.ints(count)
        nuris = r.u32()
        uris = [r.string() for _ in range(nuris)]
        nobj = r.u32()
        objs = r.refs(nobj)
        nexternal = r.u32()
        externals = r.refs(nexternal)
        out = []
        ui = 0
        oi = 0
        for t in types:
            if t == 1:
                out.append(uris[ui] if ui < len(uris) else b'')
                ui += 1
            elif t == 2:
                oi += 1
                out.append(None)
            else:
                out.append(None)
        return out
    raise ValueError(f'unknown property type {kind:#x}')


TYPE_NAMES = {
    0x01: 'string', 0x02: 'bool', 0x03: 'int', 0x04: 'float', 0x05: 'double', 0x06: 'UDim', 0x07: 'UDim2', 0x08: 'Ray',
    0x09: 'Faces', 0x0A: 'Axes', 0x0B: 'BrickColor', 0x0C: 'Color3', 0x0D: 'Vector2', 0x0E: 'Vector3', 0x0F: 'Vector2int16',
    0x10: 'CFrame', 0x12: 'token', 0x13: 'Ref', 0x14: 'Vector3int16', 0x15: 'NumberSequence', 0x16: 'ColorSequence',
    0x17: 'NumberRange', 0x18: 'Rect', 0x19: 'PhysicalProperties', 0x1A: 'Color3uint8', 0x1B: 'int64', 0x1C: 'SharedString',
    0x1D: 'ProtectedString', 0x1E: 'OptionalCFrame', 0x1F: 'UniqueId', 0x20: 'Font', 0x21: 'SecurityCapabilities', 0x22: 'Content',
}


def load(data):
    if not data.startswith(MAGIC):
        raise ValueError('not a binary roblox file')
    chunks = decompress(data)
    classes = {}
    by_ref = {}
    sstr = []
    order = []
    errors = []
    for name, raw in chunks:
        r = Reader(raw)
        if name == 'SSTR':
            r.u32()
            n = r.u32()
            for _ in range(n):
                r.bytes(16)
                sstr.append(r.string())
        elif name == 'INST':
            cid = r.u32()
            cname = r.string().decode('utf-8', 'replace')
            service = r.u8()
            n = r.u32()
            refs = r.refs(n)
            insts = []
            for ref in refs:
                inst = Inst(cname, ref)
                inst.service = bool(service)
                by_ref[ref] = inst
                insts.append(inst)
                order.append(inst)
            classes[cid] = insts
        elif name == 'PROP':
            cid = r.u32()
            pname = r.string().decode('utf-8', 'replace')
            kind = r.u8()
            insts = classes.get(cid, [])
            try:
                values = read_values(r, kind, len(insts), sstr)
            except Exception as e:
                errors.append(f'{pname}: {e}')
                continue
            tname = TYPE_NAMES.get(kind, str(kind))
            for inst, v in zip(insts, values):
                inst.props[pname] = (tname, v)
        elif name == 'PRNT':
            r.u8()
            n = r.u32()
            child = r.refs(n)
            parent = r.refs(n)
            for c, p in zip(child, parent):
                ci = by_ref.get(c)
                pi = by_ref.get(p)
                if ci is None:
                    continue
                ci.parent = pi
                if pi is not None:
                    pi.children.append(ci)
    roots = [o for o in order if o.parent is None]
    for inst in order:
        for k, (t, v) in list(inst.props.items()):
            if t == 'Ref':
                inst.props[k] = (t, by_ref.get(v) if v != -1 else None)
    return roots, order, errors


def parse_attributes(blob):
    r = Reader(blob)
    out = {}
    if len(blob) < 4:
        return out
    n = r.u32()
    for _ in range(n):
        name = r.string().decode('utf-8', 'replace')
        t = r.u8()
        if t == 0x02:
            v = ('string', r.string().decode('utf-8', 'replace'))
        elif t == 0x03:
            v = ('bool', r.u8() != 0)
        elif t == 0x04:
            v = ('number', r.i32())
        elif t == 0x05:
            v = ('number', r.f32())
        elif t == 0x06:
            v = ('number', r.f64())
        elif t == 0x09:
            v = ('UDim', (r.f32(), r.i32()))
        elif t == 0x0A:
            sx, ox, sy, oy = r.f32(), r.i32(), r.f32(), r.i32()
            v = ('UDim2', (sx, ox, sy, oy))
        elif t == 0x0E:
            v = ('BrickColor', r.u32())
        elif t == 0x0F:
            v = ('Color3', (r.f32(), r.f32(), r.f32()))
        elif t == 0x10:
            v = ('Vector2', (r.f32(), r.f32()))
        elif t == 0x11:
            v = ('Vector3', (r.f32(), r.f32(), r.f32()))
        elif t == 0x14:
            pos = (r.f32(), r.f32(), r.f32())
            rid = r.u8()
            if rid == 0:
                rot = tuple(r.f32() for _ in range(9))
            else:
                rot = ROTATIONS.get(rid, (1, 0, 0, 0, 1, 0, 0, 0, 1))
            v = ('CFrame', pos + rot)
        elif t == 0x15:
            enum = r.string().decode('utf-8', 'replace')
            v = ('Enum', (enum, r.u32()))
        elif t == 0x17:
            k = r.u32()
            v = ('NumberSequence', [(lambda e, ti, va: (ti, va, e))(r.f32(), r.f32(), r.f32()) for _ in range(k)])
        elif t == 0x19:
            k = r.u32()
            kps = []
            for _ in range(k):
                e = r.f32()
                ti = r.f32()
                kps.append((ti, r.f32(), r.f32(), r.f32(), e))
            v = ('ColorSequence', kps)
        elif t == 0x1B:
            v = ('NumberRange', (r.f32(), r.f32()))
        elif t == 0x1C:
            v = ('Rect', (r.f32(), r.f32(), r.f32(), r.f32()))
        elif t == 0x21:
            weight = r.u16()
            style = r.u8()
            fam = r.string()
            r.string()
            v = ('Font', (fam, weight, style))
        else:
            raise ValueError(f'unknown attribute type {t:#x} for {name}')
        out[name] = v
    return out
