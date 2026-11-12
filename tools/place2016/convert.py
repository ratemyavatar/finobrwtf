#!/usr/bin/env python3
import argparse
import base64
import json
import math
import os
import re
import sys

sys.setrecursionlimit(20000)
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import luau2lua
import rbxl
from rbxl import Inst

KEEP_SERVICES = {'Workspace', 'Players', 'Lighting', 'ReplicatedFirst', 'ReplicatedStorage', 'ServerScriptService',
                 'ServerStorage', 'StarterGui', 'StarterPack', 'StarterPlayer', 'Teams', 'SoundService', 'Chat'}
FOLDER_SERVICES = {'TextChatService', 'ProximityPromptService', 'VoiceChatService', 'MaterialService'}

SUPPORTED = set('''Part WedgePart CornerWedgePart TrussPart SpawnLocation Seat VehicleSeat SkateboardPlatform Model Folder
Configuration Script LocalScript ModuleScript StringValue NumberValue IntValue BoolValue ObjectValue CFrameValue Vector3Value
Color3Value BrickColorValue RayValue RemoteEvent RemoteFunction BindableEvent BindableFunction Tool HopperBin Accessory Hat
Attachment Weld ManualWeld Snap Motor Motor6D Glue Rotate RotateP RotateV VelocityMotor Humanoid Animation AnimationController
Animator KeyframeSequence Keyframe Pose Sound Decal Texture SpecialMesh BlockMesh CylinderMesh FileMesh CharacterMesh Shirt Pants
ShirtGraphic BodyColors Fire Smoke Sparkles ParticleEmitter PointLight SpotLight SurfaceLight ScreenGui BillboardGui SurfaceGui
Frame TextLabel TextButton TextBox ImageLabel ImageButton ScrollingFrame ViewportFrame Camera Sky BloomEffect BlurEffect
ColorCorrectionEffect ClickDetector SelectionBox SelectionSphere BoxHandleAdornment ConeHandleAdornment CylinderHandleAdornment
SphereHandleAdornment LineHandleAdornment Handles ArcHandles SurfaceSelection BodyVelocity BodyGyro BodyPosition BodyForce
BodyThrust BodyAngularVelocity RocketPropulsion RopeConstraint RodConstraint SpringConstraint HingeConstraint BallSocketConstraint
PrismaticConstraint CylindricalConstraint Team Explosion ForceField Dialog DialogChoice StarterPlayerScripts StarterCharacterScripts
Backpack Terrain Workspace Players Lighting ReplicatedFirst ReplicatedStorage ServerScriptService ServerStorage StarterGui StarterPack
StarterPlayer Teams SoundService Chat NumberPose BinaryStringValue IntConstrainedValue DoubleConstrainedValue UnionOperation MeshPart
NegateOperation WrapTarget'''.split())
SUPPORTED -= {'ViewportFrame', 'UnionOperation', 'MeshPart', 'NegateOperation', 'WrapTarget'}

CLASS_RENAME = {'Bone': 'Attachment'}

DROP_CLASSES = {'PackageLink', 'StudioData', 'LocalizationTable'}

DROP_PROPS = set('''UniqueId HistoryId Capabilities DefinesCapabilities SourceAssetId AttributesSerialize Tags BackSurfaceInput
BottomSurfaceInput FrontSurfaceInput LeftSurfaceInput RightSurfaceInput TopSurfaceInput BackParamA BackParamB BottomParamA BottomParamB
FrontParamA FrontParamB LeftParamA LeftParamB RightParamA RightParamB TopParamA TopParamB AudioCanCollide EnableFluidForces
RootPriority MaterialVariantSerialized PivotOffset CollisionGroupId FluidFidelityInternal InertiaMigrated UnscaledCofm
UnscaledVolInertiaDiags UnscaledVolInertiaOffDiags UnscaledVolume WorldPivotData NeedsPivotMigration ModelStreamingMode LevelOfDetail
ScaleFactor ModelMeshCFrame ModelMeshData ModelMeshSize SlimHash LocalizationMatchIdentifier LocalizationMatchedSourceText
OpenTypeFeatures SelectionBehaviorDown SelectionBehaviorLeft SelectionBehaviorRight SelectionBehaviorUp InputSink ScriptGuid
RunContext SourceAssetId PhysicsData MeshData ChildData AssetId CollisionGroup CanQuery CanTouch CastShadow Massless
AutoLocalize RootLocalizationTable SelectionGroup SelectionOrder Interactable LineHeight MaxVisibleGraphemes RichText TextDirection
TextTruncate FontFace TextSize AutomaticSize LayoutOrder AnchorPoint ZIndexBehavior ScreenInsets DisplayOrder ResetOnSpawn
ClipToDeviceSafeArea SafeAreaCompatibility IgnoreGuiInset FluidForces DoubleSided HasJointOffset HasSkinnedMesh JointOffset
VertexCount TriangleCount RenderFidelity CollisionFidelity InitialSize UsePartColor SmoothingAngle FormFactor OffCentered
ComponentIndex AlphaMode ColorMap Pivot DefaultCollisionGroup ModelStreamingBehavior'''.split())

CONTENT_PROPS = set('''Image HoverImage PressedImage SelectionImage MeshId TextureId TextureID SoundId AnimationId Texture ShirtTemplate
PantsTemplate Graphic LinkedSource SkyboxBk SkyboxDn SkyboxFt SkyboxLf SkyboxRt SkyboxUp SunTextureId MoonTextureId
BottomImage MidImage TopImage CursorIcon OverlayTextureId BaseTextureId VideoId ScrollBarImage'''.split())

MATERIAL_2016 = {256, 272, 288, 512, 528, 784, 800, 816, 832, 848, 864, 880, 1040, 1056, 1072, 1088, 1280, 1296, 1312, 1536}
MATERIAL_MAP = {1568: 272, 1584: 288, 788: 800, 804: 800, 820: 816, 836: 816, 896: 800, 912: 1296, 1284: 1280, 1328: 1296,
                1344: 1296, 1360: 1280, 1376: 816, 1392: 1296, 1552: 1536, 2304: 512, 2305: 1312, 2306: 272, 2307: 848,
                2308: 528, 2309: 1312, 2310: 272, 2311: 256}

FONT_SIZES = [(8, 0), (9, 1), (10, 2), (11, 3), (12, 4), (14, 5), (18, 6), (24, 7), (36, 8), (48, 9), (28, 10), (32, 11),
              (42, 12), (60, 13), (96, 14)]

GUI_CLASSES = {'Frame', 'TextLabel', 'TextButton', 'TextBox', 'ImageLabel', 'ImageButton', 'ScrollingFrame', 'ViewportFrame',
               'CanvasGroup', 'VideoFrame'}
TEXT_CLASSES = {'TextLabel', 'TextButton', 'TextBox'}
LAYER_CLASSES = {'ScreenGui', 'BillboardGui', 'SurfaceGui'}
PART_CLASSES = {'Part', 'WedgePart', 'CornerWedgePart', 'TrussPart', 'SpawnLocation', 'Seat', 'VehicleSeat', 'MeshPart',
                'UnionOperation', 'NegateOperation', 'SkateboardPlatform'}

PART_2016 = {'Part', 'WedgePart', 'CornerWedgePart', 'TrussPart', 'SpawnLocation', 'Seat', 'VehicleSeat', 'SkateboardPlatform', 'Terrain'}

BRICK_COLORS = None
KEYCODES = None


def keycodes():
    global KEYCODES
    if KEYCODES is None:
        KEYCODES = json.load(open(os.path.join(HERE, 'keycodes.json')))
    return KEYCODES


def text(v):
    if isinstance(v, bytes):
        return v.decode('utf-8', 'replace')
    return v


def brick_palette():
    global BRICK_COLORS
    if BRICK_COLORS is None:
        BRICK_COLORS = json.load(open(os.path.join(HERE, 'brickcolors.json')))
    return BRICK_COLORS


def nearest_brick(rgb):
    best, bd = 194, 1e9
    for num, (r, g, b) in brick_palette().items():
        d = (r - rgb[0]) ** 2 + (g - rgb[1]) ** 2 + (b - rgb[2]) ** 2
        if d < bd:
            best, bd = int(num), d
    return best


def font_size_token(px):
    best, bd = 5, 1e9
    for size, tok in FONT_SIZES:
        d = abs(size - px)
        if d < bd or (d == bd and size < px):
            best, bd = tok, d
    return best


def font_token(face):
    if not face:
        return 3
    fam = text(face[0]).lower()
    weight = face[1]
    if 'arial' in fam:
        return 2 if weight >= 600 else 1
    if 'garamond' in fam or 'merriweather' in fam or 'accanthis' in fam or 'fondamento' in fam:
        return 8
    if weight >= 600:
        return 4
    if weight <= 300:
        return 5
    return 3


def mat_mul(a, b):
    return [sum(a[r * 3 + k] * b[k * 3 + c] for k in range(3)) for r in range(3) for c in range(3)]


def cf_mul(a, b):
    ra = a[3:]
    rb = b[3:]
    pos = [a[0] + ra[0] * b[0] + ra[1] * b[1] + ra[2] * b[2],
           a[1] + ra[3] * b[0] + ra[4] * b[1] + ra[5] * b[2],
           a[2] + ra[6] * b[0] + ra[7] * b[1] + ra[8] * b[2]]
    return tuple(pos + mat_mul(ra, rb))


def cf_inv(a):
    r = a[3:]
    rt = [r[0], r[3], r[6], r[1], r[4], r[7], r[2], r[5], r[8]]
    p = a[:3]
    pos = [-(rt[0] * p[0] + rt[1] * p[1] + rt[2] * p[2]), -(rt[3] * p[0] + rt[4] * p[1] + rt[5] * p[2]),
           -(rt[6] * p[0] + rt[7] * p[1] + rt[8] * p[2])]
    return tuple(pos + rt)


IDENTITY = (0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1)


class Converter:
    def __init__(self, roots, order, compat_source, opts):
        self.roots = roots
        self.order = order
        self.compat = compat_source
        self.opts = opts
        self.stats = {}
        self.translate_errors = []
        self.next_ref = 0
        self.ref_ids = {}

    def stat(self, key, n=1):
        self.stats[key] = self.stats.get(key, 0) + n

    def new(self, cls, name, parent):
        o = Inst(cls, -1)
        o.set('Name', 'string', name)
        o.parent = parent
        if parent is not None:
            parent.children.append(o)
        return o

    def value_child(self, parent, name, v):
        kind, val = v
        if kind == 'string':
            o = self.new('StringValue', name, parent)
            o.set('Value', 'string', val if isinstance(val, (bytes, str)) else str(val))
        elif kind == 'bool':
            o = self.new('BoolValue', name, parent)
            o.set('Value', 'bool', bool(val))
        elif kind == 'number':
            o = self.new('NumberValue', name, parent)
            o.set('Value', 'double', float(val))
        elif kind == 'Vector3':
            o = self.new('Vector3Value', name, parent)
            o.set('Value', 'Vector3', val)
        elif kind == 'Color3':
            o = self.new('Color3Value', name, parent)
            o.set('Value', 'Color3', val)
        elif kind == 'CFrame':
            o = self.new('CFrameValue', name, parent)
            o.set('Value', 'CFrame', val)
        elif kind == 'BrickColor':
            o = self.new('BrickColorValue', name, parent)
            o.set('Value', 'BrickColor', val)
        elif kind == 'Ref':
            o = self.new('ObjectValue', name, parent)
            o.set('Value', 'Ref', val)
        else:
            s = encode_special(kind, val)
            if s is None:
                return None
            o = self.new('StringValue', name, parent)
            o.set('Value', 'string', s)
        return o

    def hidden_folder(self, inst, name):
        for c in inst.children:
            if c.cls == 'Configuration' and c.name() == name:
                return c
        return self.new('Configuration', name, inst)

    def store_prop(self, inst, name, v):
        if v is None or v[1] is None:
            return
        folder = self.hidden_folder(inst, '__compat_props')
        for c in list(folder.children):
            if c.name() == name:
                folder.children.remove(c)
        self.value_child(folder, name, v)

    def run(self):
        game = Inst('DataModel', -1)
        game.children = []
        keep = []
        services = {}
        for r in self.roots:
            if r.cls in KEEP_SERVICES:
                keep.append(r)
                services[r.cls] = r
        for r in keep:
            r.parent = game
        game.children = keep
        self.game = game
        self.services = services
        holder = None
        rs = services['ReplicatedStorage']
        for r in self.roots:
            if r.cls in FOLDER_SERVICES and r.children:
                if holder is None:
                    holder = self.new('Folder', '__compat_services', rs)
                svc = self.make_dummy_shell(r.cls, r.cls, holder)
                for c in list(r.children):
                    c.parent = svc
                    svc.children.append(c)
                for k, v in r.props.items():
                    if k not in DROP_PROPS and k != 'Name':
                        self.store_prop(svc, k, prop_to_value(v))
        all_insts = list(self.walk(game))
        self.weld_parts = {}
        for o in all_insts:
            self.convert_meta(o)
        for o in all_insts:
            self.convert_instance(o)
        for o in all_insts:
            if o.cls in LAYER_CLASSES:
                self.fix_zindex(o)
        for o in all_insts:
            if o.cls == 'Attachment' and (o.parent is None or o.parent.cls not in PART_2016):
                self.to_dummy(o)
                o.children[0].set('Value', 'string', 'Attachment')
                self.stat('loose_attachments')
        self.add_runtime(services)
        self.workspace_settings(services)
        return game

    def walk(self, root):
        stack = [root]
        while stack:
            o = stack.pop()
            yield o
            stack.extend(reversed(o.children))

    def make_dummy_shell(self, cls, name, parent):
        o = self.new('Configuration', name, parent)
        marker = self.new('StringValue', '__compat_class', o)
        marker.set('Value', 'string', cls)
        return o

    def convert_meta(self, o):
        attrs = o.get('AttributesSerialize')
        if attrs:
            try:
                parsed = rbxl.parse_attributes(attrs)
            except Exception as e:
                parsed = {}
                self.stat('attr_errors')
            if parsed:
                folder = self.hidden_folder(o, '__compat_attrs')
                for k, v in parsed.items():
                    self.value_child(folder, k, attr_to_value(v))
                self.stat('attributes', len(parsed))
        tags = o.get('Tags')
        if tags:
            names = [t for t in text(tags).split('\0') if t]
            if names:
                folder = self.hidden_folder(o, '__compat_tags')
                for t in names:
                    b = self.new('BoolValue', t, folder)
                    b.set('Value', 'bool', True)
                self.stat('tags', len(names))

    def convert_instance(self, o):
        cls = o.cls
        if cls in ('Script', 'LocalScript', 'ModuleScript'):
            self.convert_script(o)
            return
        if cls in CLASS_RENAME:
            o.cls = CLASS_RENAME[cls]
            return
        if cls == 'MeshPart':
            self.convert_meshpart(o)
            return
        if cls in ('UnionOperation', 'NegateOperation', 'IntersectOperation'):
            self.convert_union(o)
            return
        if cls in ('WeldConstraint', 'RigidConstraint'):
            self.convert_weld(o)
            return
        if cls in PART_CLASSES:
            self.convert_part(o)
            return
        if cls in GUI_CLASSES or cls in LAYER_CLASSES:
            self.convert_gui(o)
            if cls in ('CanvasGroup', 'ViewportFrame', 'VideoFrame'):
                gt = o.get('GroupTransparency')
                if gt:
                    self.store_prop(o, 'GroupTransparency', ('number', gt))
                o.cls = 'Frame'
                marker = self.new('StringValue', '__compat_class', o)
                marker.set('Value', 'string', cls)
                self.stat('frames_from:' + cls)
            return
        if cls == 'Sound':
            ps = o.get('PlaybackSpeed')
            if ps is not None:
                o.set('Pitch', 'float', ps)
            return
        if cls == 'Humanoid':
            if o.get('UseJumpPower') is False:
                h = o.get('JumpHeight') or 7.2
                o.set('JumpPower', 'float', math.sqrt(2 * 196.2 * h))
            return
        if cls == 'StarterPlayer':
            if o.get('CharacterUseJumpPower') is False:
                h = o.get('CharacterJumpHeight') or 7.2
                o.set('CharacterJumpPower', 'float', math.sqrt(2 * 196.2 * h))
            return
        if cls == 'Lighting':
            ct = o.get('ClockTime')
            if ct is not None:
                hh = int(ct)
                mm = int((ct - hh) * 60)
                o.set('TimeOfDay', 'string', f'{hh:02d}:{mm:02d}:00')
            return
        if cls in ('DataModel',) or o.parent is None:
            return
        if cls in SUPPORTED:
            return
        if cls in DROP_CLASSES:
            if o.parent is not None and o in o.parent.children:
                o.parent.children.remove(o)
            return
        self.to_dummy(o)

    def to_dummy(self, o):
        cls = o.cls
        props = dict(o.props)
        o.cls = 'Configuration'
        o.props = {'Name': props.get('Name', ('string', cls))}
        marker = Inst('StringValue', -1)
        marker.set('Name', 'string', '__compat_class')
        marker.set('Value', 'string', cls)
        marker.parent = o
        o.children.insert(0, marker)
        for k, v in props.items():
            if k in ('Name', 'AttributesSerialize', 'Tags', 'UniqueId', 'HistoryId', 'Capabilities', 'DefinesCapabilities', 'SourceAssetId'):
                continue
            val = prop_to_value(v, k)
            if val is not None:
                self.store_prop(o, k, val)
        self.stat('dummy:' + cls)

    def convert_script(self, o):
        src = text(o.get('Source') or b'')
        try:
            out = luau2lua.translate(src)
            self.stat('scripts_ok')
        except Exception as e:
            self.translate_errors.append((o.path(), str(e)))
            out = src
            self.stat('scripts_failed')
        o.set('Source', 'ProtectedString', out)
        rc = o.get('RunContext')
        if o.cls == 'Script' and rc == 2:
            o.cls = 'LocalScript'
            self.stat('client_scripts')
            marker = self.new('BoolValue', '__compat_clientscript', o)
            marker.set('Value', 'bool', True)
        if 'LinkedSource' in o.props:
            del o.props['LinkedSource']

    def part_common(self, o):
        mat = o.get('Material')
        if mat is not None and mat not in MATERIAL_2016:
            o.set('Material', 'token', MATERIAL_MAP.get(mat, 256))
            if mat == 1568:
                o.set('Reflectance', 'float', max(o.get('Reflectance') or 0, 0.1))
        col = o.get('Color3uint8')
        if col is not None:
            o.set('BrickColor', 'int', nearest_brick(col))
        o.set('formFactorRaw', 'token', 3)
        for k in ('CanTouch', 'CanQuery', 'CastShadow', 'Massless', 'CollisionGroup'):
            v = o.props.get(k)
            if v is None:
                continue
            if k == 'CanTouch' and v[1] is True:
                continue
            if k == 'CanQuery' and v[1] is True:
                continue
            if k == 'CastShadow' and v[1] is True:
                continue
            if k == 'Massless' and v[1] is False:
                continue
            if k == 'CollisionGroup' and text(v[1]) in ('', 'Default'):
                continue
            self.store_prop(o, k, prop_to_value(v))

    def convert_part(self, o):
        self.part_common(o)

    def add_mesh(self, o, mesh_id, texture_id, scale, offset=(0, 0, 0)):
        m = self.new('SpecialMesh', '__compat_mesh', o)
        m.set('MeshType', 'token', 5)
        m.set('MeshId', 'Content', mesh_id)
        m.set('TextureId', 'Content', texture_id or '')
        m.set('Scale', 'Vector3', scale)
        m.set('Offset', 'Vector3', offset)
        m.set('VertexColor', 'Vector3', (1, 1, 1))
        return m

    def convert_meshpart(self, o):
        size = o.get('size') or (1, 1, 1)
        initial = o.get('InitialSize') or size
        mesh_id = text(o.get('MeshId') or b'')
        tex = text(o.get('TextureID') or b'')
        scale = tuple((s / i) if i else 1 for s, i in zip(size, initial))
        self.part_common(o)
        o.cls = 'Part'
        o.set('shape', 'token', 1)
        if mesh_id:
            self.add_mesh(o, mesh_id, tex, scale)
        self.store_prop(o, 'InitialSize', ('Vector3', initial))
        self.store_prop(o, 'MeshId', ('string', mesh_id))
        self.store_prop(o, 'TextureID', ('string', tex))
        marker = self.new('StringValue', '__compat_class', o)
        marker.set('Value', 'string', 'MeshPart')
        self.stat('meshparts')

    def convert_union(self, o):
        size = o.get('size') or (1, 1, 1)
        initial = o.get('InitialSize') or size
        asset = text(o.get('AssetId') or b'')
        m = re.search(r'id=(\d+)', asset) or re.search(r'(\d{5,})', asset)
        self.part_common(o)
        kind = o.cls
        o.cls = 'Part'
        o.set('shape', 'token', 1)
        if kind == 'NegateOperation':
            o.set('Transparency', 'float', 1)
            o.set('CanCollide', 'bool', False)
        csg = self.opts.csg
        mesh = None
        if m and csg:
            aid = m.group(1)
            info = csg.get(aid)
            if info:
                mesh = info
        if mesh:
            sx = size[0] / initial[0] if initial[0] else 1
            sy = size[1] / initial[1] if initial[1] else 1
            sz = size[2] / initial[2] if initial[2] else 1
            off = mesh.get('offset', (0, 0, 0))
            self.add_mesh(o, mesh['url'], '', (sx * mesh.get('scale', 1), sy * mesh.get('scale', 1), sz * mesh.get('scale', 1)),
                          (off[0] * sx, off[1] * sy, off[2] * sz))
            self.stat('unions_meshed')
        else:
            self.stat('unions_boxed')
        marker = self.new('StringValue', '__compat_class', o)
        marker.set('Value', 'string', 'UnionOperation')
        self.store_prop(o, 'InitialSize', ('Vector3', initial))
        self.store_prop(o, 'UsePartColor', ('bool', True))

    def convert_weld(self, o):
        kind = o.cls
        if kind == 'WeldConstraint':
            p0 = o.get('Part0Internal') or o.get('Part0')
            p1 = o.get('Part1Internal') or o.get('Part1')
            enabled = o.get('Enabled', True)
            o.cls = 'ManualWeld'
            name = o.props.get('Name')
            o.props = {'Name': name or ('string', 'WeldConstraint')}
            if p0 is not None and p1 is not None:
                c0 = cf_mul(cf_inv(p0.get('CFrame') or IDENTITY), p1.get('CFrame') or IDENTITY)
                o.set('Part0', 'Ref', p0)
                o.set('Part1', 'Ref', p1 if enabled else None)
                o.set('C0', 'CFrame', c0)
                o.set('C1', 'CFrame', IDENTITY)
            marker = self.new('BoolValue', '__compat_wc', o)
            marker.set('Value', 'bool', True)
            self.stat('weldconstraints')
            return
        a0 = o.get('Attachment0')
        a1 = o.get('Attachment1')
        if a0 is None or a1 is None or a0.parent is None or a1.parent is None:
            self.to_dummy(o)
            return
        props = dict(o.props)
        o.cls = 'ManualWeld'
        o.props = {'Name': props.get('Name', ('string', 'RigidConstraint'))}
        o.set('Part0', 'Ref', a0.parent)
        o.set('Part1', 'Ref', a1.parent)
        o.set('C0', 'CFrame', a0.get('CFrame') or IDENTITY)
        o.set('C1', 'CFrame', a1.get('CFrame') or IDENTITY)
        self.stat('rigid')

    def convert_gui(self, o):
        cls = o.cls
        if cls in LAYER_CLASSES:
            if cls == 'ScreenGui':
                insets = o.get('ScreenInsets')
                ignore = o.get('IgnoreGuiInset')
                if ignore is None:
                    ignore = insets in (0, 1)
                if ignore:
                    self.store_prop(o, 'IgnoreGuiInset', ('bool', True))
                if o.get('ResetOnSpawn') is False:
                    self.store_prop(o, 'ResetOnSpawn', ('bool', False))
                if o.get('Enabled') is False:
                    self.store_prop(o, 'Enabled', ('bool', False))
                d = o.get('DisplayOrder')
                if d:
                    self.store_prop(o, 'DisplayOrder', ('number', d))
            else:
                if o.get('Enabled') is False:
                    o.set('Enabled', 'bool', False)
            return
        a = o.get('AnchorPoint')
        pos = o.get('Position')
        size = o.get('Size')
        if a and (a[0] or a[1]) and pos and size:
            self.store_prop(o, 'AnchorPoint', ('Vector2', a))
            self.store_prop(o, 'Position', ('UDim2', pos))
            real = (pos[0] - a[0] * size[0], pos[1] - a[0] * size[1], pos[2] - a[1] * size[2], pos[3] - a[1] * size[3])
            o.set('Position', 'UDim2', (real[0], int(round(real[1])), real[2], int(round(real[3]))))
            self.stat('anchors')
        lo = o.get('LayoutOrder')
        if lo:
            self.store_prop(o, 'LayoutOrder', ('number', lo))
        au = o.get('AutomaticSize')
        if au:
            self.store_prop(o, 'AutomaticSize', ('Enum', ('AutomaticSize', ENUM_TOKENS['AutomaticSize'][au])))
        if cls == 'ScrollingFrame':
            ac = o.get('AutomaticCanvasSize')
            if ac:
                self.store_prop(o, 'AutomaticCanvasSize', ('Enum', ('AutomaticSize', ENUM_TOKENS['AutomaticSize'][ac])))
        rot = o.get('Rotation')
        if cls in TEXT_CLASSES:
            ts = o.get('TextSize')
            if ts is not None:
                o.set('FontSize', 'token', font_size_token(ts))
                if ts != 14:
                    self.store_prop(o, 'TextSize', ('number', ts))
            face = o.get('FontFace')
            if face is not None:
                o.set('Font', 'token', font_token(face))
            else:
                f = o.get('Font')
                if f is not None and f > 6 and f != 8:
                    o.set('Font', 'token', 4 if f in (2, 4, 16, 19, 20) else 3)
            if o.get('RichText'):
                raw = text(o.get('Text') or b'')
                self.store_prop(o, 'RichText', ('bool', True))
                self.store_prop(o, 'Text', ('string', raw))
                o.set('Text', 'string', strip_rich(raw))
                self.stat('richtext')
            mv = o.get('MaxVisibleGraphemes')
            if mv is not None and mv >= 0:
                self.store_prop(o, 'MaxVisibleGraphemes', ('number', mv))
        z = o.get('ZIndex')
        if z is not None and z != 1:
            self.store_prop(o, 'ZIndex', ('number', z))

    def fix_zindex(self, layer):
        sibling = (layer.get('ZIndexBehavior') or 0) == 1 or layer.cls != 'ScreenGui'

        def visit(node, base):
            kids = [c for c in node.children if c.cls in GUI_CLASSES]
            if not kids:
                return
            if sibling:
                values = sorted(set(c.get('ZIndex', 1) or 1 for c in kids))
                rank = {v: i for i, v in enumerate(values)}
                for c in kids:
                    eff = min(10, max(1, base + rank[c.get('ZIndex', 1) or 1]))
                    c.set('ZIndex', 'int', eff)
                    visit(c, eff)
            else:
                for c in kids:
                    z = c.get('ZIndex', 1) or 1
                    c.set('ZIndex', 'int', min(10, max(1, z)))
                    visit(c, z)
        visit(layer, 1)

    def add_runtime(self, services):
        rs = services['ReplicatedStorage']
        mod = self.new('ModuleScript', '__Compat2016', rs)
        mod.set('Source', 'ProtectedString', self.compat)
        remote = self.new('RemoteEvent', '__compat_remote', rs)
        sss = services.get('ServerScriptService')
        if sss is not None:
            boot = self.new('Script', '__CompatServer', sss)
            boot.set('Source', 'ProtectedString', SERVER_BOOT)
        sp = services.get('StarterPlayer')
        if sp is not None:
            sps = None
            for c in sp.children:
                if c.cls == 'StarterPlayerScripts':
                    sps = c
            if sps is None:
                sps = self.new('StarterPlayerScripts', 'StarterPlayerScripts', sp)
            boot = self.new('LocalScript', '__CompatClient', sps)
            boot.set('Source', 'ProtectedString', CLIENT_BOOT)
            sps.children.remove(boot)
            sps.children.insert(0, boot)
            if not self.opts.legacy_controls:
                names = {c.name() for c in sps.children}
                for n in ('ControlScript', 'CameraScript'):
                    if n not in names:
                        s = self.new('LocalScript', n, sps)
                        s.set('Source', 'ProtectedString', '')
        sg = services.get('StarterGui')
        if sg is not None:
            reset = [c.get('ResetOnSpawn') for c in sg.children if c.cls == 'ScreenGui']
            if reset and sum(1 for r in reset if r is False) >= len(reset) / 2:
                sg.set('ResetPlayerGuiOnSpawn', 'bool', False)

    def workspace_settings(self, services):
        ws = services['Workspace']
        ws.set('FilteringEnabled', 'bool', True)
        ws.set('StreamingEnabled', 'bool', False)


SERVER_BOOT = 'require(game:GetService("ReplicatedStorage"):WaitForChild("__Compat2016"))\n'
CLIENT_BOOT = 'require(game:GetService("ReplicatedStorage"):WaitForChild("__Compat2016"))\n'


def strip_rich(s):
    s = re.sub(r'<br\s*/?>', '\n', s)
    s = re.sub(r'<[^<>]*>', '', s)
    return s.replace('&lt;', '<').replace('&gt;', '>').replace('&quot;', '"').replace('&apos;', "'").replace('&amp;', '&')


def fmt(n):
    if isinstance(n, float):
        if math.isinf(n):
            return '1e999' if n > 0 else '-1e999'
        if math.isnan(n):
            return '0'
        return repr(float('%.9g' % n))
    return str(n)


def encode_special(kind, val):
    p = '__cv:'
    if kind == 'UDim2':
        return p + 'UDim2:%s,%s,%s,%s' % (fmt(val[0]), fmt(val[1]), fmt(val[2]), fmt(val[3]))
    if kind == 'UDim':
        return p + 'UDim:%s,%s' % (fmt(val[0]), fmt(val[1]))
    if kind == 'Vector2':
        return p + 'Vector2:%s,%s' % (fmt(val[0]), fmt(val[1]))
    if kind == 'NumberRange':
        return p + 'NumberRange:%s,%s' % (fmt(val[0]), fmt(val[1]))
    if kind == 'Rect':
        return p + 'Rect:%s,%s,%s,%s' % tuple(fmt(x) for x in val)
    if kind == 'Enum':
        return p + 'Enum:%s.%s' % (val[0], val[1])
    if kind == 'EnumNum':
        return p + 'EnumNum:%s' % val
    if kind == 'ColorSequence':
        return p + 'CS:' + ';'.join('%s,%s,%s,%s' % (fmt(k[0]), fmt(k[1]), fmt(k[2]), fmt(k[3])) for k in val)
    if kind == 'NumberSequence':
        return p + 'NS:' + ';'.join('%s,%s,%s' % (fmt(k[0]), fmt(k[1]), fmt(k[2])) for k in val)
    if kind == 'Font':
        return p + 'Font:' + text(val[0])
    return None


ENUM_TOKENS = {
    'AutomaticSize': ['None', 'X', 'Y', 'XY'],
    'FillDirection': ['Horizontal', 'Vertical'],
    'SortOrder': ['Name', 'Custom', 'LayoutOrder'],
    'HorizontalAlignment': ['Center', 'Left', 'Right'],
    'VerticalAlignment': ['Center', 'Top', 'Bottom'],
    'StartCorner': ['TopLeft', 'TopRight', 'BottomLeft', 'BottomRight'],
    'AspectType': ['FitWithinMaxSize', 'ScaleWithParentSize'],
    'DominantAxis': ['Width', 'Height'],
    'ApplyStrokeMode': ['Contextual', 'Border'],
    'LineJoinMode': ['Round', 'Bevel', 'Miter'],
    'EasingStyle': ['Linear', 'Sine', 'Back', 'Quad', 'Quart', 'Quint', 'Bounce', 'Elastic', 'Exponential', 'Circular', 'Cubic'],
    'EasingDirection': ['In', 'Out', 'InOut'],
    'ProximityPromptStyle': ['Default', 'Custom'],
    'ProximityPromptExclusivity': ['OnePerButton', 'OneGlobally', 'AlwaysShow'],
    'ActuatorRelativeTo': ['Attachment0', 'Attachment1', 'World'],
    'VelocityConstraintMode': ['Line', 'Plane', 'Vector'],
    'PositionAlignmentMode': ['OneAttachment', 'TwoAttachment'],
    'OrientationAlignmentMode': ['OneAttachment', 'TwoAttachment'],
    'UIFlexMode': ['None', 'Grow', 'Shrink', 'Fill', 'Custom'],
}

PROP_ENUMS = {
    'FillDirection': 'FillDirection', 'SortOrder': 'SortOrder', 'HorizontalAlignment': 'HorizontalAlignment',
    'VerticalAlignment': 'VerticalAlignment', 'StartCorner': 'StartCorner', 'AspectType': 'AspectType',
    'DominantAxis': 'DominantAxis', 'ApplyStrokeMode': 'ApplyStrokeMode', 'LineJoinMode': 'LineJoinMode',
    'EasingStyle': 'EasingStyle', 'EasingDirection': 'EasingDirection', 'Style': 'ProximityPromptStyle',
    'Exclusivity': 'ProximityPromptExclusivity', 'RelativeTo': 'ActuatorRelativeTo',
    'VelocityConstraintMode': 'VelocityConstraintMode', 'AutomaticSize': 'AutomaticSize', 'FlexMode': 'UIFlexMode',
}


ASSET_URL = re.compile(r'^\s*(?:https?://)?(?:[a-z]+\.)?roblox\.com/(?:v1/)?asset(?:/|\.ashx)?/?\?(?:.*&)?id=(\d+)', re.I)
ASSET_URL2 = re.compile(r'^\s*(?:https?://)?assetdelivery\.roblox\.com/v1/assetId/(\d+)', re.I)


def normalize_content(url):
    m = ASSET_URL.match(url) or ASSET_URL2.match(url)
    if m:
        return 'rbxassetid://' + m.group(1)
    m = re.match(r'^\s*rbxassetid://\s*(\d+)', url)
    if m:
        return 'rbxassetid://' + m.group(1)
    return url


def prop_to_value(v, prop=None):
    kind, val = v
    if val is None:
        return None
    if kind in ('string', 'ProtectedString', 'Content'):
        return ('string', val)
    if kind == 'bool':
        return ('bool', val)
    if kind in ('int', 'float', 'double', 'int64'):
        return ('number', val)
    if kind == 'token':
        enum = PROP_ENUMS.get(prop)
        names = ENUM_TOKENS.get(enum) if enum else None
        if names and 0 <= val < len(names):
            return ('Enum', (enum, names[val]))
        if prop and prop.endswith('KeyCode'):
            name = keycodes().get(str(val))
            if name:
                return ('Enum', ('KeyCode', name))
        if prop in ('KeyboardKeyCode', 'GamepadKeyCode'):
            if 97 <= val <= 122:
                return ('Enum', ('KeyCode', chr(val).upper()))
            if 48 <= val <= 57:
                return ('Enum', ('KeyCode', ['Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine'][val - 48]))
            pads = {1000: 'ButtonX', 1001: 'ButtonY', 1002: 'ButtonA', 1003: 'ButtonB', 1004: 'ButtonR1', 1005: 'ButtonL1', 1006: 'ButtonR2', 1007: 'ButtonL2', 1008: 'ButtonR3', 1009: 'ButtonL3'}
            if val in pads:
                return ('Enum', ('KeyCode', pads[val]))
        return ('EnumNum', val)
    if kind in ('Vector3', 'Color3', 'CFrame', 'UDim', 'UDim2', 'Vector2', 'NumberRange', 'Rect', 'NumberSequence', 'ColorSequence', 'Font'):
        if kind == 'ColorSequence':
            return (kind, [(k[0], k[1], k[2], k[3]) for k in val])
        return (kind, val)
    if kind == 'Color3uint8':
        return ('Color3', tuple(c / 255 for c in val))
    if kind == 'BrickColor':
        return ('BrickColor', val)
    if kind == 'Ref':
        return ('Ref', val)
    return None


def attr_to_value(v):
    kind, val = v
    if kind == 'number':
        return ('number', val)
    if kind == 'Enum':
        return ('Enum', val)
    return (kind, val)


class XmlWriter:
    def __init__(self, out):
        self.out = out
        self.refs = {}

    def ref(self, inst):
        r = self.refs.get(id(inst))
        if r is None:
            r = 'RBX%X' % len(self.refs)
            self.refs[id(inst)] = r
        return r

    def write(self, game):
        w = self.out.write
        w('<roblox xmlns:xmime="http://www.w3.org/2005/05/xmlmime" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
          'xsi:noNamespaceSchemaLocation="http://www.roblox.com/roblox.xsd" version="4">\n')
        w('<External>null</External>\n<External>nil</External>\n')
        for c in game.children:
            self.item(c, 1)
        w('</roblox>\n')

    def item(self, o, depth):
        w = self.out.write
        w('<Item class="%s" referent="%s">\n<Properties>\n' % (o.cls, self.ref(o)))
        for k, v in o.props.items():
            if k in DROP_PROPS and not (o.cls == 'Configuration' and False):
                continue
            s = self.prop(o, k, v)
            if s:
                w(s)
        w('</Properties>\n')
        for c in o.children:
            self.item(c, depth + 1)
        w('</Item>\n')

    def prop(self, o, name, v):
        kind, val = v
        n = esc(name)
        if kind in ('string', 'Content') or (kind == 'SharedString' and name not in ('Tags',)):
            if val is None:
                if kind == 'Content':
                    return '<Content name="%s"><null></null></Content>\n' % n
                return ''
            if isinstance(val, bytes):
                try:
                    sval = val.decode('utf-8')
                except UnicodeDecodeError:
                    return '<BinaryString name="%s">%s</BinaryString>\n' % (n, base64.b64encode(val).decode())
            else:
                sval = val
            if kind == 'SharedString' or name in ('SmoothGrid', 'PhysicsGrid', 'MaterialColors', 'CollisionGroupData'):
                raw = val if isinstance(val, bytes) else val.encode('utf-8')
                return '<BinaryString name="%s">%s</BinaryString>\n' % (n, base64.b64encode(raw).decode())
            if kind == 'Content' or name in CONTENT_PROPS:
                sval = normalize_content(sval)
                if sval == '':
                    return '<Content name="%s"><null></null></Content>\n' % n
                return '<Content name="%s"><url>%s</url></Content>\n' % (n, esc(sval))
            if not xml_safe(sval):
                return '<BinaryString name="%s">%s</BinaryString>\n' % (n, base64.b64encode(sval.encode('utf-8')).decode())
            return '<string name="%s">%s</string>\n' % (n, esc(sval))
        if kind == 'ProtectedString':
            sval = text(val)
            return '<ProtectedString name="%s"><![CDATA[%s]]></ProtectedString>\n' % (n, sval.replace(']]>', ']]]]><![CDATA[>'))
        if kind == 'bool':
            return '<bool name="%s">%s</bool>\n' % (n, 'true' if val else 'false')
        if kind == 'int':
            return '<int name="%s">%d</int>\n' % (n, int(val))
        if kind == 'int64':
            return '<int64 name="%s">%d</int64>\n' % (n, int(val))
        if kind == 'float':
            return '<float name="%s">%s</float>\n' % (n, fmt(float(val)))
        if kind == 'double':
            return '<double name="%s">%s</double>\n' % (n, fmt(float(val)))
        if kind == 'token':
            return '<token name="%s">%d</token>\n' % (n, int(val))
        if kind == 'BrickColor':
            return '<int name="%s">%d</int>\n' % (n, int(val))
        if kind == 'Vector3':
            return '<Vector3 name="%s"><X>%s</X><Y>%s</Y><Z>%s</Z></Vector3>\n' % (n, fmt(float(val[0])), fmt(float(val[1])), fmt(float(val[2])))
        if kind == 'Vector2':
            return '<Vector2 name="%s"><X>%s</X><Y>%s</Y></Vector2>\n' % (n, fmt(float(val[0])), fmt(float(val[1])))
        if kind == 'Vector3int16':
            return '<Vector3int16 name="%s"><X>%d</X><Y>%d</Y><Z>%d</Z></Vector3int16>\n' % (n, val[0], val[1], val[2])
        if kind == 'CFrame':
            keys = ['X', 'Y', 'Z', 'R00', 'R01', 'R02', 'R10', 'R11', 'R12', 'R20', 'R21', 'R22']
            body = ''.join('<%s>%s</%s>' % (k, fmt(float(x)), k) for k, x in zip(keys, val))
            return '<CoordinateFrame name="%s">%s</CoordinateFrame>\n' % (n, body)
        if kind == 'Color3':
            return '<Color3 name="%s"><R>%s</R><G>%s</G><B>%s</B></Color3>\n' % (n, fmt(float(val[0])), fmt(float(val[1])), fmt(float(val[2])))
        if kind == 'Color3uint8':
            packed = (0xFF << 24) | (val[0] << 16) | (val[1] << 8) | val[2]
            return '<Color3uint8 name="%s">%d</Color3uint8>\n' % (n, packed)
        if kind == 'UDim':
            return '<UDim name="%s"><S>%s</S><O>%d</O></UDim>\n' % (n, fmt(float(val[0])), int(val[1]))
        if kind == 'UDim2':
            return '<UDim2 name="%s"><XS>%s</XS><XO>%d</XO><YS>%s</YS><YO>%d</YO></UDim2>\n' % (n, fmt(float(val[0])), int(val[1]), fmt(float(val[2])), int(val[3]))
        if kind == 'Ref':
            if val is None:
                return '<Ref name="%s">null</Ref>\n' % n
            return '<Ref name="%s">%s</Ref>\n' % (n, self.ref(val))
        if kind == 'NumberSequence':
            return '<NumberSequence name="%s">%s</NumberSequence>\n' % (n, ' '.join('%s %s %s' % (fmt(k[0]), fmt(k[1]), fmt(k[2])) for k in val) + ' ')
        if kind == 'ColorSequence':
            return '<ColorSequence name="%s">%s</ColorSequence>\n' % (n, ' '.join('%s %s %s %s 0' % (fmt(k[0]), fmt(k[1]), fmt(k[2]), fmt(k[3])) for k in val) + ' ')
        if kind == 'NumberRange':
            return '<NumberRange name="%s">%s %s </NumberRange>\n' % (n, fmt(val[0]), fmt(val[1]))
        if kind == 'Rect':
            return '<Rect2D name="%s"><min><X>%s</X><Y>%s</Y></min><max><X>%s</X><Y>%s</Y></max></Rect2D>\n' % (n, fmt(val[0]), fmt(val[1]), fmt(val[2]), fmt(val[3]))
        if kind == 'PhysicalProperties':
            if val is None:
                return '<PhysicalProperties name="%s"><CustomPhysics>false</CustomPhysics></PhysicalProperties>\n' % n
            return ('<PhysicalProperties name="%s"><CustomPhysics>true</CustomPhysics><Density>%s</Density><Friction>%s</Friction>'
                    '<Elasticity>%s</Elasticity><FrictionWeight>%s</FrictionWeight><ElasticityWeight>%s</ElasticityWeight></PhysicalProperties>\n'
                    % (n, fmt(val[0]), fmt(val[1]), fmt(val[2]), fmt(val[3]), fmt(val[4])))
        if kind in ('Faces', 'Axes'):
            return '<%s name="%s"><%s>%d</%s></%s>\n' % (kind, n, 'faces' if kind == 'Faces' else 'axes', val, 'faces' if kind == 'Faces' else 'axes', kind)
        return ''


def xml_safe(s):
    return re.search(r'[\x00-\x08\x0b\x0c\x0e-\x1f￾￿]', s) is None


def esc(s):
    return s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;').replace('"', '&quot;')


class Opts:
    pass


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('input')
    ap.add_argument('output')
    ap.add_argument('--compat', default=None)
    ap.add_argument('--csg', default=None)
    ap.add_argument('--legacy-controls', action='store_true')
    ap.add_argument('--report', default=None)
    a = ap.parse_args()
    data = open(a.input, 'rb').read()
    roots, order, errors = rbxl.load(data)
    if errors:
        print('read errors:', errors[:5])
    compat = open(a.compat).read() if a.compat else os.popen(os.path.join(HERE, 'build_compat.sh')).read()
    opts = Opts()
    opts.legacy_controls = a.legacy_controls
    opts.csg = json.load(open(a.csg)) if a.csg and os.path.exists(a.csg) else {}
    conv = Converter(roots, order, compat, opts)
    game = conv.run()
    with open(a.output, 'w', encoding='utf-8') as f:
        XmlWriter(f).write(game)
    for k in sorted(conv.stats):
        print(k, conv.stats[k])
    for path, err in conv.translate_errors[:20]:
        print('translate failed:', path, err)
    if a.report:
        json.dump({'stats': conv.stats, 'translate_errors': conv.translate_errors}, open(a.report, 'w'), indent=1)


if __name__ == '__main__':
    main()
