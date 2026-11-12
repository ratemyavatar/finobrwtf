import os

from rbxl import Inst

STUB_MARKERS = (b'UniversalSynSaveInstance', b'Decompiling is disabled', b'Server Scripts are IMPOSSIBLE to save')


SERVICES = ('ServerScriptService', 'ServerStorage', 'ReplicatedStorage', 'StarterPlayer', 'StarterGui', 'StarterPack')
CREATED = []


def text(v):
    if isinstance(v, bytes):
        return v
    return (v or '').encode('utf-8')


def is_stub(o):
    src = text(o.get('Source'))
    return any(m in src for m in STUB_MARKERS)


def find_child(o, name, cls=None):
    for c in o.children:
        if c.name() == name and (cls is None or c.cls == cls):
            return c
    return None


def resolve(roots, path):
    parts = [p for p in path.split('/') if p]
    cur = None
    for r in roots:
        if r.cls == parts[0] or r.name() == parts[0]:
            cur = r
            break
    if cur is None:
        if parts[0] not in SERVICES:
            raise KeyError('no service ' + parts[0])
        cur = Inst(parts[0], -1)
        cur.set('Name', 'string', parts[0].encode('utf-8'))
        roots.append(cur)
        CREATED.append(cur)
    for p in parts[1:]:
        nxt = find_child(cur, p)
        if nxt is None:
            raise KeyError('no ' + p + ' under ' + cur.path())
        cur = nxt
    return cur


def apply(roots, order, folder):
    stats = {'stubs_blanked': 0, 'scripts_injected': 0}
    for o in order:
        if o.cls in ('Script', 'LocalScript', 'ModuleScript') and is_stub(o):
            o.set('Source', 'ProtectedString', b'')
            if o.cls != 'ModuleScript':
                o.set('Disabled', 'bool', True)
            stats['stubs_blanked'] += 1
    for fn in sorted(os.listdir(folder)):
        if not fn.endswith('.lua'):
            continue
        src = open(os.path.join(folder, fn), 'rb').read()
        head, _, body = src.partition(b'\n')
        if not head.startswith(b'--@ '):
            continue
        cls, parent_path, name = head[4:].decode('utf-8').strip().split(' ', 2)
        parent = resolve(roots, parent_path)
        while CREATED:
            order.append(CREATED.pop())
        o = find_child(parent, name, cls)
        if o is None:
            o = Inst(cls, -1)
            o.set('Name', 'string', name.encode('utf-8'))
            o.parent = parent
            parent.children.append(o)
            order.append(o)
        o.set('Source', 'ProtectedString', body)
        if cls != 'ModuleScript':
            o.set('Disabled', 'bool', False)
        stats['scripts_injected'] += 1
    return stats
