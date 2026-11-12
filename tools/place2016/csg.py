#!/usr/bin/env python3
import gzip
import math
import json
import os
import re
import struct
import sys
import time
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import rbxl

KEY = [86, 46, 110, 88, 49, 32, 48, 4, 52, 105, 12, 119, 12, 1, 94, 0, 26, 96, 55, 105, 29, 82, 43, 7, 79, 36, 89, 101, 83, 4, 122]


def fetch(asset_id, cache_dir, cookie):
    path = os.path.join(cache_dir, str(asset_id))
    if os.path.exists(path):
        return open(path, 'rb').read()
    headers = {'User-Agent': 'Roblox/WinInet', 'requester': 'server'}
    if cookie:
        headers['Cookie'] = '.ROBLOSECURITY=' + cookie
    for attempt in range(5):
        try:
            req = urllib.request.Request('https://assetdelivery.roblox.com/v1/asset/?id=%s' % asset_id, headers=headers)
            data = urllib.request.urlopen(req, timeout=30).read()
            if data[:2] == b'\x1f\x8b':
                data = gzip.decompress(data)
            open(path, 'wb').write(data)
            return data
        except urllib.error.HTTPError as e:
            if e.code == 429 or e.code >= 500:
                time.sleep(2 * (attempt + 1))
                continue
            return None
        except Exception:
            time.sleep(2 * (attempt + 1))
    return None


def unxor(data):
    return bytes(b ^ KEY[i % 31] for i, b in enumerate(data))


def parse_v2(d):
    p = 6
    version = struct.unpack_from('<i', d, p)[0]
    p += 4
    if version != 2:
        raise ValueError('csg version %d' % version)
    p += 32
    nverts, stride = struct.unpack_from('<ii', d, p)
    p += 8
    verts = []
    for i in range(nverts):
        base = p + i * stride
        px, py, pz, nx, ny, nz = struct.unpack_from('<6f', d, base)
        u, v = struct.unpack_from('<2f', d, base + 32)
        verts.append((px, py, pz, nx, ny, nz, u, v))
    p += nverts * stride
    nidx = struct.unpack_from('<i', d, p)[0]
    p += 4
    idx = list(struct.unpack_from('<%dI' % nidx, d, p))
    return verts, idx


def to_mesh_v2(verts, idx):
    out = bytearray(b'version 2.00\n')
    out += struct.pack('<HBBII', 12, 36, 12, len(verts), len(idx) // 3)
    for px, py, pz, nx, ny, nz, u, v in verts:
        out += struct.pack('<9f', px, py, pz, nx, ny, nz, u, 1 - v, 0)
    for i in range(0, len(idx) - 2, 3):
        out += struct.pack('<3I', idx[i], idx[i + 1], idx[i + 2])
    return bytes(out)


def xf(cf, p):
    return (cf[0] + cf[3] * p[0] + cf[4] * p[1] + cf[5] * p[2],
            cf[1] + cf[6] * p[0] + cf[7] * p[1] + cf[8] * p[2],
            cf[2] + cf[9] * p[0] + cf[10] * p[1] + cf[11] * p[2])


def xn(cf, n):
    v = (cf[3] * n[0] + cf[4] * n[1] + cf[5] * n[2], cf[6] * n[0] + cf[7] * n[1] + cf[8] * n[2], cf[9] * n[0] + cf[10] * n[1] + cf[11] * n[2])
    l = math.sqrt(v[0] ** 2 + v[1] ** 2 + v[2] ** 2) or 1
    return (v[0] / l, v[1] / l, v[2] / l)


def face(verts, idx, pts, normal):
    base = len(verts)
    for p in pts:
        verts.append(p + normal + (0, 0))
    for i in range(1, len(pts) - 1):
        idx += [base, base + i, base + i + 1]


def tri_normal(a, b, c):
    u = (b[0] - a[0], b[1] - a[1], b[2] - a[2])
    v = (c[0] - a[0], c[1] - a[1], c[2] - a[2])
    n = (u[1] * v[2] - u[2] * v[1], u[2] * v[0] - u[0] * v[2], u[0] * v[1] - u[1] * v[0])
    l = math.sqrt(n[0] ** 2 + n[1] ** 2 + n[2] ** 2) or 1
    return (n[0] / l, n[1] / l, n[2] / l)


def poly(verts, idx, cf, local):
    pts = [xf(cf, p) for p in local]
    face(verts, idx, pts, tri_normal(pts[0], pts[1], pts[2]))


def shape_mesh(cls, shape, size, cf, verts, idx):
    x, y, z = size[0] / 2, size[1] / 2, size[2] / 2
    if cls == 'WedgePart':
        polys = [[(-x, -y, -z), (x, -y, -z), (x, -y, z), (-x, -y, z)],
                 [(-x, -y, z), (x, -y, z), (x, y, z), (-x, y, z)],
                 [(-x, -y, -z), (-x, -y, z), (-x, y, z)],
                 [(x, -y, -z), (x, y, z), (x, -y, z)],
                 [(-x, -y, -z), (-x, y, z), (x, y, z), (x, -y, -z)]]
    elif cls == 'CornerWedgePart':
        polys = [[(-x, -y, -z), (x, -y, -z), (x, -y, z), (-x, -y, z)],
                 [(x, -y, -z), (x, y, z), (x, -y, z)],
                 [(-x, -y, z), (x, -y, z), (x, y, z)],
                 [(-x, -y, -z), (-x, -y, z), (x, y, z)],
                 [(-x, -y, -z), (x, y, z), (x, -y, -z)]]
    elif shape == 0:
        rings, segs = 8, 14
        for i in range(rings):
            a0 = math.pi * i / rings - math.pi / 2
            a1 = math.pi * (i + 1) / rings - math.pi / 2
            for j in range(segs):
                b0 = 2 * math.pi * j / segs
                b1 = 2 * math.pi * (j + 1) / segs
                def pt(a, b):
                    return (math.cos(a) * math.cos(b) * x, math.sin(a) * y, math.cos(a) * math.sin(b) * z)
                quad = [pt(a0, b0), pt(a1, b0), pt(a1, b1), pt(a0, b1)]
                pts = [xf(cf, p) for p in quad]
                base = len(verts)
                for p, lp in zip(pts, quad):
                    verts.append(p + xn(cf, (lp[0] / (x or 1), lp[1] / (y or 1), lp[2] / (z or 1))) + (0, 0))
                idx += [base, base + 1, base + 2, base, base + 2, base + 3]
        return
    elif shape == 2:
        segs = 16
        r1, r2 = y, z
        ring = [(math.cos(2 * math.pi * j / segs) * r1, math.sin(2 * math.pi * j / segs) * r2) for j in range(segs)]
        poly(verts, idx, cf, [(x, a, b) for a, b in ring])
        poly(verts, idx, cf, [(-x, a, b) for a, b in reversed(ring)])
        for j in range(segs):
            a = ring[j]
            b = ring[(j + 1) % segs]
            quad = [(-x, a[0], a[1]), (-x, b[0], b[1]), (x, b[0], b[1]), (x, a[0], a[1])]
            pts = [xf(cf, p) for p in quad]
            base = len(verts)
            for p, lp in zip(pts, quad):
                verts.append(p + xn(cf, (0, lp[1] / (r1 or 1), lp[2] / (r2 or 1))) + (0, 0))
            idx += [base, base + 2, base + 1, base, base + 3, base + 2]
        return
    else:
        polys = [[(x, -y, -z), (x, y, -z), (x, y, z), (x, -y, z)],
                 [(-x, -y, z), (-x, y, z), (-x, y, -z), (-x, -y, -z)],
                 [(-x, y, -z), (-x, y, z), (x, y, z), (x, y, -z)],
                 [(-x, -y, z), (-x, -y, -z), (x, -y, -z), (x, -y, z)],
                 [(x, -y, z), (x, y, z), (-x, y, z), (-x, -y, z)],
                 [(-x, -y, -z), (-x, y, -z), (x, y, -z), (x, -y, -z)]]
    for pl in polys:
        poly(verts, idx, cf, pl)


def children_mesh(child_data, verts, idx, parent_cf=(0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1)):
    roots, order, errors = rbxl.load(child_data)
    for o in roots:
        if o.cls == 'NegateOperation':
            continue
        cf = o.get('CFrame')
        size = o.get('size')
        if cf is None or size is None:
            continue
        if o.cls in ('UnionOperation',):
            sub = o.get('ChildData')
            if sub:
                children_mesh(sub, verts, idx)
                continue
        if o.cls in ('Part', 'WedgePart', 'CornerWedgePart', 'SpawnLocation', 'Seat', 'TrussPart', 'MeshPart', 'UnionOperation'):
            shape_mesh(o.cls, o.get('shape', 1), size, cf, verts, idx)


def union_ids(order):
    ids = set()
    for o in order:
        if o.cls in ('UnionOperation', 'NegateOperation', 'IntersectOperation'):
            a = o.get('AssetId')
            if a:
                a = a.decode() if isinstance(a, bytes) else a
                m = re.search(r'id=(\d+)', a) or re.search(r'(\d{5,})', a)
                if m:
                    ids.add(m.group(1))
    return sorted(ids)


def mesh_from_asset(data):
    roots, order, errors = rbxl.load(data)
    for o in order:
        md = o.get('MeshData')
        if md:
            d = md if md.startswith(b'CSGMDL') else unxor(md)
            if d.startswith(b'CSGMDL') and struct.unpack_from('<i', d, 6)[0] == 2:
                verts, idx = parse_v2(d)
                return to_mesh_v2(verts, idx), 'v2'
        cd = o.get('ChildData')
        if cd:
            verts, idx = [], []
            children_mesh(cd, verts, idx)
            if verts:
                return to_mesh_v2(verts, idx), 'children'
    raise ValueError('no mesh data')


def main():
    place, out_dir, mapping = sys.argv[1], sys.argv[2], sys.argv[3]
    cache = os.environ.get('CSG_CACHE', '/tmp/csg-cache')
    os.makedirs(cache, exist_ok=True)
    os.makedirs(out_dir, exist_ok=True)
    cookie = os.environ.get('FINOBE_ROBLOSECURITY', '')
    roots, order, errors = rbxl.load(open(place, 'rb').read())
    ids = union_ids(order)
    result = {}
    failed = {}
    for n, aid in enumerate(ids):
        data = fetch(aid, cache, cookie)
        if not data:
            failed[aid] = 'fetch failed'
            continue
        try:
            mesh, how = mesh_from_asset(data)
        except Exception as e:
            failed[aid] = str(e)
            continue
        open(os.path.join(out_dir, aid), 'wb').write(mesh)
        result[aid] = {'url': 'rbxassetid://' + aid, 'how': how}
    json.dump(result, open(mapping, 'w'), indent=0)
    print('unions', len(ids), 'meshes', len(result), 'failed', len(failed))
    for k, v in list(failed.items())[:20]:
        print(' ', k, v)


if __name__ == '__main__':
    main()
