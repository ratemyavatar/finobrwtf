# finobe launcher

`FinobeLauncher.exe` is a small windows program. it needs no runtime. it shows a white window with the spinning finobe logo, a progress bar and a cancel button, like the 2016 roblox one.

## what it does

1. asks `https://prod1-setup.finobe.wtf/version` for the current client build
2. if that build isn't installed, it downloads `<version>-FinobePlayer.zip`, unzips it into `%LOCALAPPDATA%\Finobe\Versions\<version>` and removes older builds
3. copies itself to `%LOCALAPPDATA%\Finobe\FinobeLauncher.exe` and registers `finobe-launcher://` for the current user, so play buttons on the site open it
4. when a play button starts it, it runs the client with the login token and the place launcher url. when you start it by itself, it says finobe is installed and closes

## publishing a client build

on the web server, run this with the patched client folder:

```bash
python3 tools/client/package_client.py /path/to/RobloxPlayer
```

it writes the zip into `storage/app/private/setup` and prints a `FINOBE_CLIENT_VERSION=` line. put that line in `.env` and run `php artisan config:clear`. every launcher updates the next time it starts.

`/download` on the site points at `storage/setup/FinobeLauncher.exe`.

## building

```bash
sudo apt install g++-mingw-w64-i686 binutils-mingw-w64-i686
```

```bash
./build.sh
```

then copy `FinobeLauncher.exe` to `storage/setup/`. it uses miniz (mit license, see `miniz.LICENSE`) to unzip. set the `FINOBE_SETUP_URL` environment variable to test against another setup server.
