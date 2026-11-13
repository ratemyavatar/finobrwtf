import os, re, sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
OUT = sys.argv[1] if len(sys.argv) > 1 else os.path.join(ROOT, 'Web', 'build')
CONTRIB = os.environ.get('CONTRIB_PATH', '/tmp/claude-0/web/contrib')

PROJECTS = {
    'Log': 'Log/Log.vcxproj',
    'Base': 'Base/Base.vcxproj',
    'G3D': 'Rendering/g3d/graphics3D.vcxproj',
    'RbxG3D': 'Rendering/RbxG3D/RbxG3D.vcxproj',
    'Bullet': 'App.BulletPhysics/App.BulletPhysics.vcxproj',
    'App': 'App/App.vcxproj',
    'Network': 'Network/Network.vcxproj',
    'AppDraw': 'Rendering/AppDraw/AppDraw.vcxproj',
    'GfxBase': 'Rendering/GfxBase/GfxBase.vcxproj',
    'GfxCore': 'Rendering/GfxCore/GfxCore.vcxproj',
    'GfxRender': 'Rendering/GfxRender/GfxRender.vcxproj',
    'Shell': None,
    'Boost': None,
}

SKIP = re.compile(r'(^|/)(Win|win32|D3D9|D3D11|Durango|XboxOne|Xbox|DirectX|Wininet|WinInet)(/|\.|$)|'
                  r'ContextGLWin32|ContextGLAndroid|ContextGLEAGL|ContextGLNSGL|DeviceD3D|VMProtect|'
                  r'CrashReporter|DumpErrorUploader|RegistryUtil|ATL|Telemetry|stdafx\.cpp$', re.I)

EXTRA_SKIP = set(l.strip() for l in open(os.path.join(ROOT, 'Web', 'skip.txt')) if l.strip() and not l.startswith('#')) \
    if os.path.exists(os.path.join(ROOT, 'Web', 'skip.txt')) else set()
EXTRA_SRC = {}
if os.path.exists(os.path.join(ROOT, 'Web', 'extra.txt')):
    for l in open(os.path.join(ROOT, 'Web', 'extra.txt')):
        l = l.strip()
        if l and not l.startswith('#'):
            m, p = l.split(None, 1)
            EXTRA_SRC.setdefault(m, []).append(p)


def ci_path(p):
    if os.path.exists(p):
        return p
    parts = p.split('/')
    cur = '/'
    for part in parts[1:]:
        if not part:
            continue
        nxt = os.path.join(cur, part)
        if not os.path.exists(nxt):
            try:
                m = [x for x in os.listdir(cur) if x.lower() == part.lower()]
            except Exception:
                return None
            if not m:
                return None
            nxt = os.path.join(cur, m[0])
        cur = nxt
    return cur


def parse(proj):
    path = os.path.join(ROOT, proj)
    d = os.path.dirname(path)
    x = open(path, encoding='utf-8-sig', errors='replace').read()
    srcs = []
    for m in re.finditer(r'<ClCompile Include="([^"]+)"\s*(/>|>(.*?)</ClCompile>)', x, re.S):
        inc, body = m.group(1), m.group(3) or ''
        if re.search(r"<ExcludedFromBuild Condition=\"'\$\(Configuration\)\|\$\(Platform\)'=='Release\|Win32'\">true", body):
            continue
        p = ci_path(os.path.normpath(os.path.join(d, inc.replace('\\', '/')))) or os.path.normpath(os.path.join(d, inc.replace('\\', '/')))
        rel = os.path.relpath(p, ROOT)
        if SKIP.search(rel) or rel in EXTRA_SKIP:
            continue
        srcs.append(rel)
    incs = []
    m = re.search(r"Release\|Win32'\">.*?<AdditionalIncludeDirectories>([^<]*)", x, re.S) or re.search(r'<AdditionalIncludeDirectories>([^<]*)', x)
    for i in m.group(1).split(';'):
        i = i.strip().replace('\\', '/')
        if not i or i.startswith('%') or '$(' in i:
            continue
        p = ci_path(os.path.normpath(os.path.join(d, i)))
        if p and os.path.isdir(p):
            incs.append(p)
    return srcs, incs


COMMON_INC_TAIL = ['/tmp/claude-0/web/case']
COMMON_INC = [os.path.join(ROOT, 'Web', 'include'), os.path.join(ROOT, 'Web', 'libwww'), os.path.join(ROOT, 'zlib'), os.path.join(CONTRIB, 'boost_1_55_0', 'include'), os.path.join(ROOT, 'Base', 'include'),
              os.path.join(ROOT, 'Log', 'include'), os.path.join(ROOT, 'ClientShared'), os.path.join(ROOT, 'App', 'include'),
              os.path.join(ROOT, 'App', 'lua-5.1.4', 'src'), os.path.join(ROOT, 'Network', 'include'), os.path.join(ROOT, 'App.BulletPhysics'),
              os.path.join(ROOT, 'Rendering', 'g3d', 'include'), os.path.join(ROOT, 'Rendering', 'RbxG3D', 'include'),
              os.path.join(ROOT, 'Rendering', 'GfxBase', 'include'), os.path.join(ROOT, 'Rendering', 'AppDraw', 'include'),
              os.path.join(ROOT, 'Rendering', 'GfxCore', 'include'), os.path.join(ROOT, 'Rendering')]
DEFS = '-DNDEBUG -DRBX_PLATFORM_WEB -DGLEW_NO_GLU -DFT2_BUILD_LIBRARY -DBOOST_SYSTEM_NO_DEPRECATED -DBOOST_ALL_NO_LIB -DBOOST_BIND_GLOBAL_PLACEHOLDERS -DBOOST_TIMER_ENABLE_DEPRECATED -DBOOST_FILESYSTEM_VERSION=3 -DBOOST_FILESYSTEM_DEPRECATED -DBOOST_CHRONO_HEADER_ONLY -DBOOST_FILESYSTEM_NO_CXX20_ATOMIC_REF'
FLAGS = '-O2 -pthread -msimd128 -fexceptions -w -Wno-error -fno-strict-aliasing -Wno-c++11-narrowing -Wno-error=register -msse -msse2 -include web_compat.h'

os.makedirs(OUT, exist_ok=True)
n = []
n.append('rule cxx\n  command = em++ -std=gnu++14 %s %s $incs -MMD -MF $out.d -c $in -o $out\n  depfile = $out.d\n  deps = gcc\n' % (FLAGS, DEFS))
n.append('rule cc\n  command = emcc -std=gnu99 %s %s $incs -MMD -MF $out.d -c $in -o $out\n  depfile = $out.d\n  deps = gcc\n' % (FLAGS, DEFS))
n.append('rule cxxc\n  command = em++ -x c++ -std=gnu++14 %s %s $incs -MMD -MF $out.d -c $in -o $out\n  depfile = $out.d\n  deps = gcc\n' % (FLAGS, DEFS))
n.append('rule ar\n  command = rm -f $out && emar rcs $out $in\n')
total = 0
for mod, proj in PROJECTS.items():
    srcs, incs = parse(proj) if proj else ([], [os.path.join(ROOT, 'Web', 'shell'), os.path.join(ROOT, 'ClientBase'), os.path.join(ROOT, 'Rendering', 'GfxCore'), os.path.join(ROOT, 'Rendering', 'GfxCore', 'GL'), os.path.join(ROOT, 'Rendering', 'GfxRender', 'include'), os.path.join(ROOT, 'fmod', 'include')])
    srcs += EXTRA_SRC.get(mod, [])
    seen = []
    for s in srcs:
        if s not in seen and os.path.exists(os.path.join(ROOT, s)):
            seen.append(s)
    allinc = ' '.join('-I' + i for i in COMMON_INC[:2] + incs + COMMON_INC[2:] + COMMON_INC_TAIL)
    objs = []
    for s in seen:
        o = '%s/%s.o' % (mod, s.replace('/', '_'))
        rule = 'cc' if s.endswith('.c') and (mod != 'App' or 'libwww' in s.lower()) else 'cxx'
        if rule == 'cxx' and s.endswith('.c'):
            rule = 'cxxc'
        n.append('build %s: %s %s\n  incs = %s\n' % (o, rule, os.path.join(ROOT, s), allinc))
        objs.append(o)
    n.append('build lib%s.a: ar %s\n' % (mod, ' '.join(objs)))
    n.append('build %s: phony lib%s.a\n' % (mod, mod))
    total += len(seen)
    print(mod, len(seen))
LINK = ('-O2 -pthread -fexceptions -sPROXY_TO_PTHREAD=1 -sPTHREAD_POOL_SIZE=24 -sOFFSCREENCANVAS_SUPPORT=1 '
        '-sOFFSCREENCANVASES_TO_PTHREAD=#canvas -sMAX_WEBGL_VERSION=2 -sMIN_WEBGL_VERSION=1 -sFULL_ES3=1 -sFETCH=1 '
        '-sALLOW_MEMORY_GROWTH=1 -sINITIAL_MEMORY=536870912 -sMAXIMUM_MEMORY=2147483648 -sSTACK_SIZE=1048576 '
        '-sDEFAULT_PTHREAD_STACK_SIZE=1048576 -sPROXY_POSIX_SOCKETS=1 -lwebsocket.js -sEXPORTED_RUNTIME_METHODS=UTF8ToString,allocateUTF8 '
        '-sENVIRONMENT=web,worker -sMODULARIZE=0 -sEXIT_RUNTIME=0 $extra')
order = ['Shell', 'App', 'Network', 'GfxRender', 'GfxCore', 'GfxBase', 'AppDraw', 'RbxG3D', 'G3D', 'Bullet', 'Boost', 'Base', 'Log']
n.append('rule link\n  command = em++ %s -Wl,--start-group $in -Wl,--end-group -o $out\n' % LINK)
n.append('build WebPlayer.js: link %s\n  extra = --preload-file /tmp/claude-0/web/pkg/root@/ -sFORCE_FILESYSTEM=1\n' % ' '.join('lib%s.a' % m for m in order))
open(os.path.join(OUT, 'build.ninja'), 'w').write('\n'.join(n))
print('total', total)
