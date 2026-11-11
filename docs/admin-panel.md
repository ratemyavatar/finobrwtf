# admin panel

the admin panel is at `/admin`. it uses the normal site navbar, logo and light and night styles. the main page is the original finobe grid of icon cards. each card opens its tools and has a link back to the grid.

## setup

back up the database first. then:

```sh
composer install
```

```sh
php artisan migrate --database=finobe --force
```

```sh
php artisan view:clear
```

the admin migration adds tables for notes, audit records, staff permissions, restrictions and feature settings. it keeps your existing columns.

anyone with `users.status = 'admin'` can open the panel. set `ADMIN_OWNER_IDS` to a comma list of owner ids to limit who can change admin accounts or give out permissions. if you leave it empty, every full admin counts as an owner. staff with given permissions only see their own sections and can't promote themselves.

other `.env` keys you might want:

```dotenv
ADMIN_OWNER_IDS=1
FINOBE_CDN_PATH=/var/www/cdn.finobe.wtf
FINOBE_CDN_URL=https://cdn.finobe.wtf
```

## main pages

- asset approval at `/admin/assets` shows the queue with previews and approve and deny buttons. `/admin/assets/{id}` shows one asset. approve and deny only work as posts with a csrf token
- manage users at `/admin/users` lets you search by name or id
- servers at `/admin/servers` shows stats, lets you add a server, delete one, or delete all after a confirm. only full admins can use it
- bans, warnings, forum moderation, give dius, announcements and the blog are linked from the grid too, based on what you're allowed to do

## controls

`config/admin_controls.json` lists all 108 controls. they all run through `app/Admin/Workbench.php`, which checks the input. the full list is in [admin-controls.md](admin-controls.md).

- every change needs a reason. big changes also need the confirm box
- each change and its audit record save together, and the same request can't apply twice
- passwords, tokens, sessions and ip fields never show up in account views or audit records
- revoking sessions also rotates the account token and remember token
- a warning shows the original finobe moderation page with a reactivate button
- bans block the site until they end. staff can lift a ban without deleting its history
- "terminate account" is a permanent ban. it doesn't delete the account
- restrictions and feature pauses are checked by `AdminSitePolicy`. admins can still use paused features
- approving an asset moves its files through the cdn and review folders. a missing file shows an error instead of a fake success
- avatar tools can remove worn items or reset the avatar. run a render after to update the picture

## user pages

| route | what it shows | who can use it |
| --- | --- | --- |
| `/admin/users` | search, sort, filter and pages | any user staff scope |
| `/admin/users/{id}` | account state, avatar, dates and blurb | any user staff scope |
| `/admin/users/{id}/account` | account details and edits | accounts |
| `/admin/users/{id}/moderation` | warnings, bans and history | moderation |
| `/admin/users/{id}/notes` | private notes | moderation |
| `/admin/users/{id}/restrictions` | restrictions | access restrictions |
| `/admin/users/{id}/economy` | balance, inventory and transactions | economy |
| `/admin/users/{id}/avatar` | worn items and avatar reset | avatar |
| `/admin/users/{id}/messages` | send a staff message | messages |
| `/admin/users/{id}/permissions` | staff permissions | owners |
| `/admin/users/{id}/changes` | admin history for this account | audit |

the forms always act on the user id in the url, so a changed form can't hit another account. permissions are checked on every get and post.

## tests

```sh
php vendor/bin/phpunit tests/Feature/AdminWorkbenchTest.php
```

```sh
php vendor/bin/phpunit --testsuite Unit
```

the feature tests use their own sqlite database and run every control. they don't touch real user data.

jquery 3.6.0 and the bootstrap 4.6.2 bundle (both mit) live in `public/s/admin` for the navbar dropdowns.

the blog is under admin, then blog. see [blog.md](blog.md).
