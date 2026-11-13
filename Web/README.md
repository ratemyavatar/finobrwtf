# web player

the 2016 client built for the browser with emscripten, like economy simulator's webplayer.

## what is in here

- `gen.py` writes a ninja build from the visual studio projects (the cmake files are out of date)
- `casefix.py` makes a tree of symlinks for includes written with the wrong case
- `widen.py` adds `__EMSCRIPTEN__` next to `__ANDROID__` in preprocessor checks
- `include/` small stand-in headers (jni, vmprotect, android log, msvc intrinsics)
- `libwww/` a tiny HTParse/HTSimplify replacement
- `src/HttpPlatformImplWeb.cpp` http through the browser's fetch instead of curl
- `shell/` the platform layer, based on the android one: place launcher, view, input, webgl context, main
- `player/index.html` the page that loads WebPlayer.js

## build

needs emscripten (`apt install emscripten`) and boost 1.83 headers (`apt install libboost1.83-dev`).
the compiled boost parts (thread, filesystem, iostreams) come from `apt-get source boost1.83`.

```
python3 Web/casefix.py /tmp/web/case
python3 Web/gen.py /tmp/web/b
ninja -C /tmp/web/b WebPlayer.js
```

## networking

browsers can't send udp, so raknet's sockets go over a websocket to `tools/webrelay` on the site
(emscripten's posix socket proxy with an allow list, so it only reaches game servers).

## serving

the page needs `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Embedder-Policy: require-corp`
so threads (SharedArrayBuffer) work.
