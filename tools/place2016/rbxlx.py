import base64
import xml.etree.ElementTree as ET

from rbxl import Inst

SHARED = {}
CF = ('X', 'Y', 'Z', 'R00', 'R01', 'R02', 'R10', 'R11', 'R12', 'R20', 'R21', 'R22')


def num(el, tag, default=0.0):
    c = el.find(tag)
    if c is None or c.text is None:
        return default
    try:
        return float(c.text)
    except ValueError:
        return default


def text(el):
    return el.text or ''


def cframe(el):
    return tuple(num(el, k, 1.0 if k in ('R00', 'R11', 'R22') else 0.0) for k in CF)


def value(el, refs):
    tag = el.tag
    if tag in ('string', 'ProtectedString'):
        return tag, text(el).encode('utf-8')
    if tag == 'BinaryString':
        try:
            return 'string', base64.b64decode(text(el).strip() or b'')
        except Exception:
            return 'string', b''
    if tag == 'SharedString':
        key = text(el).strip()
        if key in SHARED:
            return 'SharedString', SHARED[key]
        try:
            return 'SharedString', base64.b64decode(key or b'')
        except Exception:
            return 'SharedString', b''
    if tag == 'Content':
        u = el.find('url')
        return 'Content', (text(u).encode('utf-8') if u is not None else b'')
    if tag == 'bool':
        return 'bool', text(el).strip().lower() == 'true'
    if tag in ('int', 'token', 'BrickColor', 'int64', 'SecurityCapabilities'):
        try:
            v = int(float(text(el).strip() or 0))
        except ValueError:
            v = 0
        return {'BrickColor': 'BrickColor', 'int64': 'int64', 'SecurityCapabilities': 'SecurityCapabilities'}.get(tag, 'int' if tag == 'int' else 'token'), v
    if tag in ('float', 'double'):
        try:
            return tag, float(text(el).strip() or 0)
        except ValueError:
            return tag, 0.0
    if tag in ('CoordinateFrame', 'CFrame'):
        return 'CFrame', cframe(el)
    if tag == 'OptionalCoordinateFrame':
        c = el.find('CFrame')
        return 'OptionalCFrame', cframe(c) if c is not None else None
    if tag == 'Vector3':
        return 'Vector3', (num(el, 'X'), num(el, 'Y'), num(el, 'Z'))
    if tag == 'Vector2':
        return 'Vector2', (num(el, 'X'), num(el, 'Y'))
    if tag == 'Color3':
        return 'Color3', (num(el, 'R'), num(el, 'G'), num(el, 'B'))
    if tag == 'Color3uint8':
        try:
            p = int(text(el).strip() or 0)
        except ValueError:
            p = 0
        return 'Color3uint8', ((p >> 16) & 255, (p >> 8) & 255, p & 255)
    if tag == 'UDim2':
        return 'UDim2', (num(el, 'XS'), int(num(el, 'XO')), num(el, 'YS'), int(num(el, 'YO')))
    if tag == 'UDim':
        return 'UDim', (num(el, 'S'), int(num(el, 'O')))
    if tag == 'Rect2D':
        mn, mx = el.find('min'), el.find('max')
        return 'Rect', (num(mn, 'X'), num(mn, 'Y'), num(mx, 'X'), num(mx, 'Y'))
    if tag == 'NumberRange':
        parts = text(el).split()
        a = float(parts[0]) if parts else 0.0
        b = float(parts[1]) if len(parts) > 1 else a
        return 'NumberRange', (a, b)
    if tag == 'NumberSequence':
        parts = [float(x) for x in text(el).split()]
        return 'NumberSequence', [tuple(parts[i:i + 3]) for i in range(0, len(parts) - 2, 3)]
    if tag == 'ColorSequence':
        parts = [float(x) for x in text(el).split()]
        return 'ColorSequence', [tuple(parts[i:i + 5]) for i in range(0, len(parts) - 4, 5)]
    if tag == 'PhysicalProperties':
        if text(el.find('CustomPhysics') if el.find('CustomPhysics') is not None else el).strip().lower() != 'true':
            return 'PhysicalProperties', None
        return 'PhysicalProperties', (num(el, 'Density'), num(el, 'Friction'), num(el, 'Elasticity'), num(el, 'FrictionWeight'), num(el, 'ElasticityWeight'))
    if tag == 'Font':
        fam = el.find('Family')
        fam = text(fam.find('url')).encode('utf-8') if fam is not None and fam.find('url') is not None else b''
        weight = int(num(el, 'Weight', 400))
        style = 1 if (el.find('Style') is not None and text(el.find('Style')).strip() == 'Italic') else 0
        return 'Font', (fam, weight, style, b'')
    if tag == 'Ref':
        return 'Ref', text(el).strip()
    if tag == 'Faces':
        return 'Faces', int(num(el, 'faces'))
    if tag == 'Axes':
        return 'Axes', int(num(el, 'axes'))
    return None, None


def load(data):
    root = ET.fromstring(data)
    SHARED.clear()
    table = root.find('SharedStrings')
    if table is not None:
        for e in table.findall('SharedString'):
            try:
                SHARED[e.get('md5') or ''] = base64.b64decode(text(e).strip() or b'')
            except Exception:
                pass
    order = []
    by_ref = {}
    roots = []
    errors = []
    counter = [0]

    def walk(el, parent):
        cls = el.get('class') or 'Folder'
        ref = el.get('referent') or ('auto%d' % counter[0])
        counter[0] += 1
        inst = Inst(cls, ref)
        inst.parent = parent
        if parent is None:
            inst.service = True
            roots.append(inst)
        else:
            parent.children.append(inst)
        by_ref[ref] = inst
        order.append(inst)
        props = el.find('Properties')
        if props is not None:
            for p in props:
                name = p.get('name')
                if not name:
                    continue
                try:
                    kind, v = value(p, by_ref)
                except Exception as e:
                    errors.append('%s.%s: %s' % (cls, name, e))
                    continue
                if kind is not None:
                    inst.props[name] = (kind, v)
        for c in el.findall('Item'):
            walk(c, inst)

    for el in root.findall('Item'):
        walk(el, None)
    for inst in order:
        for k, (t, v) in list(inst.props.items()):
            if t == 'Ref':
                inst.props[k] = (t, by_ref.get(v) if v not in ('null', '', None) else None)
    return roots, order, errors
