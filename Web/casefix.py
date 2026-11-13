import os, re, sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
OUT = sys.argv[1]
DIRS = ['Base', 'Log', 'App', 'Network', 'Rendering', 'App.BulletPhysics', 'ClientShared', 'Web', 'ClientBase']

index = {}
for d in DIRS:
    for dp, dn, fn in os.walk(os.path.join(ROOT, d)):
        for f in fn:
            if f.lower().endswith(('.h', '.hpp', '.inl', '.inc', '.ipp', '.c', '.cpp', '.def', '.cxx', '.hxx')):
                p = os.path.join(dp, f)
                index.setdefault(f.lower(), []).append(p)

SYS = ['/usr/share/emscripten/cache/sysroot/include', '/usr/share/emscripten/cache/sysroot/include/c++/v1', '/usr/include']
inc_re = re.compile(r'^\s*#\s*include\s*[<"]([^>"]+)[>"]', re.M)
made = 0
for d in DIRS:
    for dp, dn, fn in os.walk(os.path.join(ROOT, d)):
        for f in fn:
            if not f.lower().endswith(('.h', '.hpp', '.inl', '.c', '.cpp', '.ipp')):
                continue
            try:
                s = open(os.path.join(dp, f), encoding='latin-1').read()
            except Exception:
                continue
            for inc in inc_re.findall(s):
                inc = inc.replace('\\', '/')
                if os.path.exists(os.path.join(dp, inc)):
                    continue
                if any(os.path.exists(os.path.join(x, inc)) for x in SYS):
                    continue
                base = os.path.basename(inc).lower()
                cands = index.get(base, [])
                parts = [x.lower() for x in inc.split('/')]
                best = None
                for c in cands:
                    cp = [x.lower() for x in c.split('/')]
                    if cp[-len(parts):] == parts:
                        best = c
                        break
                if not best:
                    continue
                if best.endswith('/' + inc):
                    continue
                tgt = os.path.join(OUT, inc)
                if os.path.lexists(tgt):
                    continue
                os.makedirs(os.path.dirname(tgt), exist_ok=True)
                os.symlink(best, tgt)
                made += 1
                sd = os.path.dirname(best)
                for sib in os.listdir(sd):
                    sp = os.path.join(sd, sib)
                    st = os.path.join(os.path.dirname(tgt), sib)
                    if os.path.isfile(sp) and not os.path.lexists(st):
                        os.symlink(sp, st)
print('links', made)
