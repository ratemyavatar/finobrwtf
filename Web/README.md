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
- `gencs.py` pulls the core scripts out of the patched finobe RobloxPlayerBeta.exe into `LuaGenCS.inl`
- `src/FmodStub.cpp` no sound for now, fmod calls fail and the sound service turns itself off
- `dist/` a ready built player, so the server doesn't need emscripten

## build

needs emscripten (`apt install emscripten`) and boost 1.83 headers (`apt install libboost1.83-dev`).
the compiled boost parts (thread, filesystem, iostreams) come from `apt-get source boost1.83`.

```
python3 Web/casefix.py /tmp/web/case
python3 Web/gencs.py <finobe client>/RobloxPlayerBeta.exe /tmp/web/gen/LuaGenCS.inl
python3 Web/gen.py /tmp/web/b
ninja -C /tmp/web/b WebPlayer.js
```

## networking

browsers can't send udp, so raknet's sockets go over a websocket to `tools/webrelay` on the site
(emscripten's posix socket proxy with an allow list, so it only reaches game servers).

## serving

the page needs `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Embedder-Policy: require-corp`
so threads (SharedArrayBuffer) work.

## server setup

1. copy `dist/` to the server and run `gunzip WebPlayer.data.gz`
2. nginx on the main site (finobe.wtf):

```
location /play/web/ {
    alias /var/www/finobe-play/;
    add_header Cross-Origin-Opener-Policy same-origin always;
    add_header Cross-Origin-Embedder-Policy require-corp always;
    add_header Cross-Origin-Resource-Policy same-origin always;
    types { application/wasm wasm; application/javascript js; text/html html; application/octet-stream data; }
    gzip on;
    gzip_types application/wasm application/javascript application/octet-stream;
}
location = /webrelay {
    proxy_pass http://127.0.0.1:8090;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 3600;
}
```

3. run `tools/webrelay` from the website branch with `WEBRELAY_ALLOW` set to the game server ips
4. open `https://finobe.wtf/play/web/?placeId=<id>` while logged in

the player talks to the site through `/web/<subdomain>/...` on the same origin (the website branch
mounts the client routes there), so cookies work and nothing needs cors.
