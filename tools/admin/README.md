# in game admin

every 2016 game server loads thugshakers admin (a kohl's admin edit) into ServerScriptService before scripts start. only the user ids in `FINOBE_INGAME_ADMINS` get it (default `1`, comma separated for more).

the model is `storage/rbx/files/admin/admin.rbxmx`. the game server gets it from `/asset/?id=admin` with the cloud access key, and the site puts the admin ids in when it sends it.

to build it again from the place file:

```bash
python3 tools/admin/build_admin.py thugshakers_admin.rbxl storage/rbx/files/admin/admin.rbxmx
```

you need `pip install lz4` and `luac5.1`.

what the build changes from the original:

- the old owner names are gone, admin is by user id only, and the place creator and "Player1" don't get owner anymore
- removed 4 commands that ran `require()` on outside asset ids, one of them hidden behind math. those load code we can't see
- f3x's http interface is turned off, it let any player make the game server send web requests
- non admins can't send admin chat or read the remote keys from the settings
- settings don't save to data stores and the ban list is empty
- comments are stripped and every script is checked with luac
- the model is saved as 2016 xml, newer properties are converted or left out

the 2012 client can't run it, it needs ModuleScripts.
