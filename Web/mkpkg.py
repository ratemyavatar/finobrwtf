import os
import shutil
import sys

root, out = sys.argv[1], sys.argv[2]
pkg = os.path.join(out, 'pkg')
shutil.rmtree(pkg, ignore_errors=True)
shutil.copytree(os.path.join(root, 'shaders'), os.path.join(pkg, 'shaders'))
shutil.copytree(os.path.join(root, 'content', 'fonts'), os.path.join(pkg, 'content', 'fonts'), ignore=shutil.ignore_patterns('fonts.dds'))
shutil.copytree(os.path.join(root, 'content', 'textures', 'loading'), os.path.join(pkg, 'content', 'textures', 'loading'))
os.makedirs(os.path.join(pkg, 'PlatformContent', 'pc'))
open(os.path.join(pkg, 'content', '.keep'), 'w').close()
open(os.path.join(pkg, 'PlatformContent', 'pc', '.keep'), 'w').close()
pc = os.path.join(root, 'PlatformContent', 'pc')
names = []
for d, _, files in os.walk(pc):
    for f in files:
        names.append(os.path.relpath(os.path.join(d, f), pc).replace(os.sep, '/'))
with open(os.path.join(pkg, 'pcfiles.txt'), 'w') as f:
    f.write('\n'.join(sorted(names)) + '\n')
print(pkg, len(names))
content = os.path.join(root, 'content')
cnames = []
for d, _, files in os.walk(content):
    for f in files:
        cnames.append(os.path.relpath(os.path.join(d, f), content).replace(os.sep, '/'))
with open(os.path.join(pkg, 'contentfiles.txt'), 'w') as f:
    f.write('\n'.join(sorted(cnames)) + '\n')
print('content', len(cnames))
