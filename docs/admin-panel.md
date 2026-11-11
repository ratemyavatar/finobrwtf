# Finobe administration

## Classic user sidebar and servers

User management at \`/admin/users/{id}\` now has an icon-based, grouped Syntax-style navigation panel with active-section highlighting. It uses Finobe's existing CSS, not a new brand theme, and the actual \`/s/img/diu_16.png\` asset for currency balances and transaction amounts. The admin homepage uses \`diu_64.png\` for the Give Dius shortcut.

Server management at \`/admin/servers\` has been rebuilt from the original placeholder as dedicated Finobe-themed cards: statistics, an add-server form, a server listing with per-record delete, and a separately confirmed delete-all operation. It corrects the original wrong IP field and writes \`players = []\` when registering a server. Only full administrators can manage servers. Server changes are audited when the admin audit table is present.

## Current classic admin layout

The main admin page now uses the original Finobe card styling, not the large JavaScript control workbench. It links directly to the essential existing pages: Asset Approval, Manage Users, Bans, Warnings, Forum Moderation, Give Dius, Servers, Announcements and Blog (based on permissions). The existing web navbar, original logo and light/night CSS remain in use.

Asset Approval is now a dedicated workflow: `GET /admin/assets` shows the pending queue with upload previews and Approve/Deny buttons; `GET /admin/assets/{id}` shows an individual asset with its uploader, type, ID, description and preview. Approvals and denials are POST-only at `/admin/assets/{id}/approve` and `/admin/assets/{id}/deny`. They use CSRF protection, staff permission checks, the existing audited Workbench handlers, and asset-file validation. Legacy side-effecting GET routes `/api/accept` and `/api/deny` have been removed.

The previous larger workbench implementation is retained in the backend for compatibility, but is not presented as a dashboard.

---

The admin panel is at `/admin`. It uses the existing `v2/Modules/nav.twig` navbar, normal Finobe logo, and the site's light/night stylesheets. The entry page restores the original Finobe icon-card grid. Each category opens its existing controls, with a return link to the grid. Account moderation, 108 essential controls, asset review, and blog management remain available. There is no separate admin navbar, custom wordmark, or numbered sidebar.

## Install

Use the repository's existing PHP 8.2+, Composer, MySQL, and Redis setup. Configure the `finobe` database connection in `.env` before migrating. Back up an existing database first.

```sh
composer install
php artisan migrate --database=finobe --force
php artisan view:clear
```

The additive admin migration creates case notes, audit records, delegated permissions, feature restrictions, feature settings, and reversible profile decorations. It also supplies missing users columns that the source dump's controllers already reference. Existing columns are preserved.

An existing account with `users.status = 'admin'` can open the panel. Set `ADMIN_OWNER_IDS` to comma-separated owner user IDs to restrict who can change administrator accounts or delegate permissions. If omitted, existing full administrators retain owner authority. Delegated staff receive only their granted sections and cannot promote themselves.

Optional `.env` configuration:

```dotenv
ADMIN_OWNER_IDS=1
FINOBE_CDN_PATH=/var/www/cdn.finobe.wtf
FINOBE_CDN_URL=https://cdn.finobe.wtf
```

Use your actual owner IDs and CDN path. No accounts or passwords are seeded into a live site by this change.

## Controls

`config/admin_controls.json` is the server-authoritative catalog. The catalog contains 108 essential controls. Duplicate asset-type copies, warning-reason presets, fixed currency amounts, color presets, and fun effects have been removed. Asset controls accept the asset ID regardless of type; staff permissions use a checked scope selector. They use shared validated handlers in `app/Admin/Workbench.php`.

Sections cover moderation, account restrictions, accounts, economy, catalog assets, places, avatar data, forums, messages, invites, announcements, site settings, staff permissions, audit history, and operations. The complete inventory is in [admin-controls.md](admin-controls.md).

Manage users at `/admin/users`: search by username or ID and open `/admin/users/{id}`. The dedicated page shows account details, moderation history, and only the account actions allowed by your staff scopes. Its forms bind the target to the user ID in the URL on the server. A submitted target cannot select a different account. Missing users return 404; unrelated staff scopes cannot access the directory or lookup API. Other tools require the content's own ID. Search filters the current section. Stars are local browser preferences. Reports paginate in batches of 50; JSON download exports the current result page.

Workbench writes require a reason. High-impact actions also require the confirmation checkbox. Each mutation and its audit record are committed together, and duplicate request IDs cannot double-apply a change. Passwords, tokens, session payloads, and IP fields are excluded from account inspection and audit snapshots. Session revocation supports the existing database-session setup and rotates the account token and remember token.

Warnings display a real acknowledgement page. Temporary and permanent bans block site requests; staff can lift them without deleting their history. Permanent suspension is labeled “Terminate account” but does not erase the account or its records.

Per-account restrictions and global feature pauses/cooldowns are checked by `AdminSitePolicy` on the existing website routes. Administrators can still manage paused features. Restrictions are website permissions; they do not add new engine-side in-game permissions.

Catalog approval and denial check asset files and move them through the configured CDN/review directories. Missing files produce an error instead of falsely reporting success. Texture dependencies explicitly identified by the asset's metadata are included. The handler does not guess dependencies from adjacent asset IDs.

Avatar controls inspect the existing avatar data, remove worn items, or reset the avatar. They do not replace meshes, lighting, or the renderer. Run the site's existing avatar render after a change to update its PNG. This work does not add an arbiter or a native Linux RCC worker.

## User management

The user page keeps Finobe's shared navbar, default logo, light/night CSS, and ordinary cards. It has an account summary and focused sections rather than a long table of controls. No new controls were added to the 108-entry catalog.

| Route | Purpose | Permission |
| --- | --- | --- |
| `/admin/users` | Search, sort, filter active/banned users, and paginate results | Any user-oriented staff scope |
| `/admin/users/{id}` | Account state, avatar, dates, profile blurb, and action links | Any user-oriented staff scope |
| `/admin/users/{id}/account` | Account details and edits | Accounts |
| `/admin/users/{id}/moderation` | Warnings, bans, and paginated moderation history | Moderation |
| `/admin/users/{id}/notes` | Add and read paginated private notes | Moderation |
| `/admin/users/{id}/restrictions` | Current restrictions and block/restore actions | Access restrictions |
| `/admin/users/{id}/economy` | Balance corrections, inventory adjustments, and user transactions | Economy |
| `/admin/users/{id}/avatar` | Existing equipped items and avatar reset/removal actions | Avatar |
| `/admin/users/{id}/messages` | Send a staff message | Messages |
| `/admin/users/{id}/permissions` | Current delegated scopes and permission changes | Staff and owner authority |
| `/admin/users/{id}/changes` | Paginated admin history for this account | Audit |

Forms use the existing audit, validation, and idempotency handling. Successful writes return to their section and reload the current account state. Section permissions are enforced on direct GET and POST requests, including when an action is submitted from a different section. History queries select this account's records; they do not dump credential-bearing account or session data. Rendered account avatars use `FINOBE_CDN_URL`, defaulting to `https://cdn.<FINOBE_DOMAIN>`. Blank avatar fields and the legacy default `avatar/682b8bd6cbc48.png` display the bundled Finobe F image at `/s/img/default-avatar.png`. The same resolver is used across both public themes and admin pages, so existing unrendered accounts need no database or CDN migration. Clear/rebuild Laravel configuration and view caches when deploying the new Twig function (`php artisan optimize:clear`).

The organization was informed by the public [Syntax user page](https://github.com/InnitGroup/syntaxsource/blob/main/syntaxwebsite/app/pages/admin/usermanage/view.html), [user search](https://github.com/InnitGroup/syntaxsource/blob/main/syntaxwebsite/app/pages/admin/usermanage/search.html), [notes](https://github.com/InnitGroup/syntaxsource/blob/main/syntaxwebsite/app/pages/admin/usermanage/moderatornotes.html), and [permissions definition](https://github.com/InnitGroup/syntaxsource/blob/main/syntaxwebsite/app/pages/admin/permissionsdefinition.py). The implementation uses Finobe's own tables, routes, permissions, and appearance.

## Verification

```sh
php vendor/bin/phpunit tests/Feature/AdminWorkbenchTest.php
php vendor/bin/phpunit --testsuite Unit
node --check public/s/admin/workbench.js
node --check public/s/admin/user-actions.js
php artisan view:cache
```

The feature suite migrates an isolated SQLite database, executes every catalog entry against populated tables, and verifies authorization, permission revocation, missing-target checks, idempotency, warnings/acknowledgement, bans, restrictions, avatar edits, and user management routes. Production MySQL, a live CDN, and real RCC output require deployment verification. No production user data is used in the tests or screenshots.

The original repository's unrelated default homepage example test assumes a running MySQL/Redis website; it is not part of the isolated admin feature suite.

Browser QA was unavailable for the focused user-page refinement. Server rendering, route permissions, form writes, history scoping/pagination, and template compilation were verified with the isolated feature suite. Earlier screenshots are historical and do not show the refined user pages.

Third-party navbar dependencies: jQuery 3.6.0 (MIT) and Bootstrap 4.6.2 bundle (MIT), stored locally under `public/s/admin`. Their license headers are preserved. These support the existing navbar's dropdowns and mobile collapse.

Blog publishing is available under **Admin → Blog**. See [blog.md](blog.md) for its setup, public routes, and editor workflow.
