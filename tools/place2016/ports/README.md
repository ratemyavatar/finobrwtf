# ports

hand written scripts for saved places that came without scripts.

## rma

the rate my avatar booth game. build it with:

```
python3 tools/place2016/convert.py place.rbxlx RateMyAvatar_2016.rbxlx --csg csg.json --scripts tools/place2016/ports/rma
```

- RmaConfig: prices, items, booth styles, titles, gamepass ids. gamepass and donation ids are 0 (off) until you set them.
- RmaData: player saves and the ordered stores for the boards.
- RmaServer: booths (claim, text, image, colours, styles, blacklist), credits every 5 minutes, store, cases, ratings, titles, overhead tags, top rated screen.
- RmaLeaderboards: the time, streak, minesweeper, uno and spent boards.
- RmaMinesweeper and RmaUno: the two minigames.
- RmaTools: what the shop tools do.
- the rest are the client side: menus, booth editor, store, rating, profile, titles, uno hand, tool inputs.
