# finobe

this is the finobe.com site. it started as the leaked finobe.net source and got rebuilt to match finobe.com.

## what you need

- php 8.2 or newer
- mariadb or mysql
- redis
- composer and npm
- nginx or caddy in front of it

## setup

1. run `composer install` and `npm install`
2. copy `.env.example` to `.env` and fill it in
3. run `php artisan key:generate`
4. run `php artisan migrate`
5. point your web server at the `public` folder

after you change `.env`, run `php artisan config:clear`. `php artisan config:cache` is fine too now, nothing reads `env()` outside the config folder anymore.

## the .env file

the normal laravel keys work like always. these are the finobe ones:

- `FINOBE_DOMAIN` is the main domain, like `finobe.wtf`
- `FINOBE_TRUSTED_PROXIES` is a comma list of proxies you trust to send the real visitor ip. cloudflare is always trusted, you don't need to add it
- `FINOBE_MAIL_MODE` picks how mail goes out. 1 is zeptomail (`FINOBE_ZOHO_API_KEY`), 2 is smtp2go (`FINOBE_SMTP2GO_API_KEY`), 3 is normal smtp from the `MAIL_` keys
- `TURNSTILE_SECRET` and `TURNSTILE_SITEKEY` turn on the cloudflare check on sign up. leave them empty to turn it off
- `DISCORD_WEBHOOK` is where staff alerts go, like new sign ups and things to moderate
- `FINOBE_INVITE_KEYS` makes people need an invite key to sign up. `FINOBE_CREATE_INVITE_KEYS` lets users make keys
- `FINOBE_CREATE_ASSETS`, `FINOBE_CREATE_PLACES` and `FINOBE_FORUM_POST` turn uploads, new places and forum posts on or off
- `FINOBE_ROBLOSECURITY` is an optional cookie from an alt roblox account. some newer items only download when logged in. never use your main account
- `FINOBE_ASSETDELIVERY` is where roblox assets get downloaded from when you import items
- `FINOBE_PYTHON` is the python used to convert new meshes. it needs `pip install DracoPy`
- `FINOBE_CDN_PATH` is the folder the cdn subdomain serves
- `FINOBE_ARBITER_HOST` and `FINOBE_ARBITER_PORT` point at rcc. see `tools/rcc/README.md`
- `FINOBE_GAMESERVER_HOST` and the port range keys are where players connect for games
- `FINOBE_CLOUD_AUTHORIZATION` is the shared key game servers and rcc use to talk to the site
- `FINOBE_CLIENT_VERSION` is the 2016 client build the launcher downloads. the `_2007`, `_2012`, `_2017`, `_2018` and `_2020` keys are the same thing for the other years
- `FINOBE_HOST2012_URL` is the address of the 2012 game server host
- `FINOBE_NAME_CHANGE_COST` and `FINOBE_NAME_CHANGE_COOLDOWN_DAYS` set the price and wait for name changes
- `FINOBE_SERVER_NAME` shows next to the lucky number in the footer. leave it empty to hide it

## how the code is laid out

- `routes/web.php` has every site page. the paths match finobe.com, like `/login`, `/register`, `/register/form` and `/publish/place-edit`. the old finobe.net paths redirect to the new ones
- `app/View/PageData.php` builds what every page shares, like the navbar user, notifications and alerts
- `app/Http/Controllers/Controller.php` has `page()`, which every page uses to render
- `app/Http/Controllers/Auth` has login, sign up, 2fa, password reset and email verify
- `app/Http/Controllers/Settings` has the `/app/*` settings pages
- `app/Http/Middleware/Moderation.php` handles ip bans, account bans, warnings and the verify email page
- `app/Services` has the shared helpers like mail, discord, turnstile and usernames

## more docs

- `docs/admin-panel.md` and `docs/admin-controls.md` for the admin panel
- `docs/blog.md` for the blog
- `docs/gameservers-and-security.md` for game servers
- `docs/2012-join-debugging.md` for 2012 joins
- `tools/rcc/README.md`, `tools/client/README.md` and `tools/bootstrapper/README.md` for the windows side

## ops note

`clientsettings.api.finobe.wtf` has to resolve and give back the fastflags json. it needs a proxied cloudflare cname to the tunnel, a tunnel ingress entry for it before the 404 catch all, and the name in the nginx `server_name`. see `docs/gameservers-and-security.md`.

## credits

original finobe.net team: instance, aesthetiful, karma and waterboi. bepistown: watcha27 rebuilt the db, thugshaker3d put the source on github and karma leaked it.
