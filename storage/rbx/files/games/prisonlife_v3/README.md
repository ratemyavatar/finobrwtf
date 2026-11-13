# prison life v3

prison life v3 expanded, converted for the 2016 client.

- place.rbxlx.gz is the place. `{image:guards}` and `{image:inmates}` in it are filled in when it is imported.
- guards.png and inmates.png are the team menu pictures.

## import

```
php artisan finobe:import-place storage/rbx/files/games/prisonlife_v3/place.rbxlx.gz \
  --title="Prison Life V3" \
  --image guards=storage/rbx/files/games/prisonlife_v3/guards.png \
  --image inmates=storage/rbx/files/games/prisonlife_v3/inmates.png
```

use `--place=ID` instead of `--title` to update a game that is already on the site. running it again reuses the images it already uploaded. add `--dry-run` to check first.
