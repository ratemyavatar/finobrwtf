import os, re, sys
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
DIRS = ['Base', 'Log', 'App', 'Network', 'Rendering/g3d', 'Rendering/RbxG3D', 'Rendering/GfxBase', 'Rendering/GfxCore', 'Rendering/GfxRender', 'Rendering/AppDraw', 'App.BulletPhysics', 'ClientShared']
W = '(defined(__ANDROID__) || defined(__EMSCRIPTEN__))'
changed = []
for d in DIRS:
    for dp, dn, fn in os.walk(os.path.join(ROOT, d)):
        if '/Android' in dp or 'VrApi' in dp:
            continue
        for f in fn:
            if not f.endswith(('.cpp', '.h', '.c', '.hpp', '.inl')):
                continue
            p = os.path.join(dp, f)
            s = open(p, encoding='latin-1').read()
            if '__ANDROID__' not in s or '__EMSCRIPTEN__' in s:
                continue
            out = []
            for line in s.split('\n'):
                m = re.match(r'^(\s*#\s*)(ifdef|ifndef|if|elif)\b(.*)$', line)
                if m and '__ANDROID__' in m.group(3):
                    pre, kw, rest = m.groups()
                    if kw == 'ifdef':
                        line = '%sif %s%s' % (pre, W, rest.replace('__ANDROID__', '', 1))
                    elif kw == 'ifndef':
                        line = '%sif !%s%s' % (pre, W, rest.replace('__ANDROID__', '', 1))
                    else:
                        rest = re.sub(r'defined\s*\(\s*__ANDROID__\s*\)', '@@', rest)
                        rest = re.sub(r'defined\s+__ANDROID__', '@@', rest)
                        rest = rest.replace('__ANDROID__', '@@').replace('@@', W)
                        line = '%s%s%s' % (pre, kw, rest)
                out.append(line)
            ns = '\n'.join(out)
            if ns != s:
                open(p, 'w', encoding='latin-1').write(ns)
                changed.append(os.path.relpath(p, ROOT))
print(len(changed))
for c in changed: print(c)
