# webrelay

lets the browser web player talk to game servers. browsers can't send udp, so the player's
raknet sockets go over one websocket to this relay, which makes the real udp calls.

it's emscripten's websocket_to_posix_proxy with an allow list on top:

- only ipv4 udp sockets
- bind only to port 0
- connect and sendto only to hosts in `WEBRELAY_ALLOW` (comma separated, default `127.0.0.1`)
  and ports in `WEBRELAY_PORTS` (default `53000-65535`)
- listen, accept, socketpair, sendmsg and recvmsg are refused
- it only listens on `127.0.0.1` unless you set `WEBRELAY_BIND`

## build and run

```
g++ -O2 -std=c++17 -pthread -o webrelay *.cpp
WEBRELAY_ALLOW=1.2.3.4 ./webrelay 8090
```

put it behind nginx at `/webrelay` with websocket upgrade headers.
