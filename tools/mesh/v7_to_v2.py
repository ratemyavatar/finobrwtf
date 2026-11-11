#!/usr/bin/env python3
import struct
import sys

import numpy as np

try:
    import DracoPy
except ImportError:
    sys.stderr.write("DracoPy is not installed (pip install DracoPy)\n")
    sys.exit(3)


def fail(msg):
    sys.stderr.write(msg + "\n")
    sys.exit(2)


def attribute(mesh, unique_id):
    try:
        a = mesh.get_attribute_by_unique_id(unique_id)
    except Exception:
        return None
    if a is None:
        return None
    data = a.get("data") if isinstance(a, dict) else getattr(a, "data", None)
    return None if data is None else np.asarray(data)


def main():
    data = sys.stdin.buffer.read()
    nl = data.find(b"\n")
    if not data.startswith(b"version ") or nl < 0:
        fail("not a Roblox mesh")
    o = nl + 1

    draco = None
    lods = []
    while o + 16 <= len(data):
        ctype = data[o:o + 8].rstrip(b"\0")
        version, size = struct.unpack_from("<II", data, o + 8)
        body = data[o + 16:o + 16 + size]
        o += 16 + size
        if ctype == b"COREMESH" and version == 2:
            (n,) = struct.unpack_from("<I", body, 0)
            draco = body[4:4 + n]
        elif ctype == b"LODS" and version == 1:
            (count,) = struct.unpack_from("<I", body, 3)
            lods = list(struct.unpack_from("<%dI" % count, body, 7))
    if draco is None:
        fail("no compressed COREMESH chunk")

    mesh = DracoPy.decode(draco)
    pos = attribute(mesh, 0)
    pos = np.asarray(mesh.points if pos is None else pos, dtype=np.float32).reshape(-1, 3)
    faces = np.asarray(mesh.faces, dtype=np.uint32).reshape(-1, 3)
    n = len(pos)

    uv = attribute(mesh, 2)
    if uv is None and getattr(mesh, "tex_coord", None) is not None and len(mesh.tex_coord):
        uv = mesh.tex_coord
    uv = np.zeros((n, 2), np.float32) if uv is None else np.asarray(uv, np.float32).reshape(-1, 2)

    normals = attribute(mesh, 1)
    if normals is not None and np.asarray(normals).size == n * 3:
        normals = np.asarray(normals, np.float32).reshape(-1, 3)
    else:
        normals = np.zeros((n, 3), np.float32)
        tri = pos[faces]
        fn = np.cross(tri[:, 1] - tri[:, 0], tri[:, 2] - tri[:, 0])
        for k in range(3):
            np.add.at(normals, faces[:, k], fn)
        normals /= np.maximum(np.linalg.norm(normals, axis=1, keepdims=True), 1e-9)

    if len(lods) >= 2 and lods[1] > lods[0]:
        faces = faces[lods[0]:lods[1]]

    out = bytearray(b"version 2.00\n")
    out += struct.pack("<HBBII", 12, 36, 12, n, len(faces))
    vert = np.zeros(n, dtype=[("p", "<f4", 3), ("n", "<f4", 3), ("uv", "<f4", 3)])
    vert["p"] = pos
    vert["n"] = normals
    vert["uv"][:, :2] = uv
    out += vert.tobytes()
    out += faces.astype("<u4").tobytes()
    sys.stdout.buffer.write(bytes(out))


main()
