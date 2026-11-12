# place2016

turns a modern roblox place (.rbxl) into a place the 2016 client and rcc can run.

## what it does

- luau2lua.py turns every luau script into lua 5.1. types are removed, `continue`, `+=`, if expressions, backtick strings, `//` and generic for loops are rewritten. method calls, property reads and property writes go through the compat module so new apis work.
- compat/ is the runtime. build_compat.sh joins it into one module that gets saved as ReplicatedStorage.__Compat2016. it adds task, typeof, TweenInfo, TweenService, attributes, tags, CollectionService, Raycast, pivots, Random, DateTime, utf8, bit32, new enums, proximity prompts, text chat commands, ui layouts, ui padding, anchor points, text size and more.
- rbxl.py reads binary rbxl files (lz4 and zstd).
- convert.py writes a 2016 xml place. meshparts become parts with a hidden special mesh, unions become parts with a mesh made by csg.py, attributes and tags become hidden folders, classes 2016 does not have become hidden configuration objects that the compat module acts like.
- csg.py downloads union assets and turns them into old style meshes. old union data is read directly, newer union data is rebuilt from the union's child parts (cut outs are skipped).

## use

```
FINOBE_ROBLOSECURITY=... CSG_CACHE=/tmp/csg python3 tools/place2016/csg.py place.rbxl storage/rbx/files/csg csg.json
python3 tools/place2016/convert.py place.rbxl place2016.rbxlx --csg csg.json
```

then upload place2016.rbxlx as the place and set the game to the 2016 client.

`--legacy-controls` keeps the 2016 camera and movement scripts instead of the place's own player module.

## limits

- ui corners, gradients and strokes are only faked (strokes become borders or text strokes, gradients tint the colour).
- beams, trails, highlights and surface appearances do nothing.
- union cut outs made with negate parts are lost on newer unions.
- collision groups and no collision constraints do not change physics.
