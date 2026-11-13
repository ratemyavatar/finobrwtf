# finobe studio

roblox studio 0.271.1.97962 from 2016, patched and rebranded for finobe.wtf.

- `patch_studio.py` changes `roblox.com` to `finobe.wtf`, puts in finobe's script signing key, points the api and upload hosts at finobe, turns off the "offline mode" check and sets the base url in `AppSettings.xml`
- `rebrand_studio.py` changes roblox to finobe in the menus, title, about box and messages, and swaps the splash screens, icon and logos

you need `pip install pefile pillow`.

## build it

```bat
python tools\studio\patch_studio.py <studio folder>
python tools\studio\rebrand_studio.py <studio folder>
```

then delete `RobloxStudioLauncherBeta.exe`, rename `RobloxStudioBeta.exe` to `FinobeStudio.exe` and zip the folder.

if the site gets a new signing key, pass `--key privateKey1024.pem` (or `--blob`) to `patch_studio.py`, same as the client.

## what works

- start page at `/ide/welcome`: log in with your finobe email and password, new baseplate, open your games
- toolbox: models, decals and audio, plus your own. select a part before you click a decal
- publish (alt+p) saves over the open game, publish as (alt+shift+p) picks a game or makes a new one
- publish selection to finobe makes a model that waits for review in the asset approval queue
- file > published projects lists your games
- play solo (f5)

the site side is `routes/services/studio.php`, `app/Http/Controllers/Studio` and `resources/views/studio`.
