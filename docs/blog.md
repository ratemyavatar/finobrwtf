# Finobe blog

The public blog is a patched capture of the January 2021 Finobe.com Ghost/Casper client, backed by this Laravel app's `finobe` database. It has its own original blog header and theme; the administration pages use the website's shared Finobe navbar and stylesheet.

The source capture is https://web.archive.org/web/20210125203354/https://blog.finobe.com/. The page identifies Ghost 3.40. Homepage, article, author archive, and page-two HTML were recovered. The original `screen.css`, `casper.js`, logo, cover, and favicon are stored locally under `public/s/blog`. Their SHA-256 hashes are recorded in [blog-capture.json](blog-capture.json). Casper's MIT notice is included alongside them.

The recovered template structure is in `resources/views/blog/partials`. Dynamic content, dates, authors, images, metadata, and links are supplied by local models. The original built JavaScript consumes the local `rel="next"` link and appends `.post-card` elements from the next HTML page, preserving the original infinite-scroll behavior. There is no dependency on Ghost's production API. The old site's analytics, inactive subscription initialization, and links to its feeds/accounts are not used. Twitter is shown only when configured.

The live Finobe.net blog returned an access-denied page during research. Its client was not recovered or treated as verified. The archived Finobe.com version is the visual reference.

The supplied HTML contains **six** original post titles, timestamps, dated article
URLs, excerpts, and partial read-time labels. It does not contain the full post bodies.
Do not fabricate body text or auto-import incomplete material as published posts.
The Laravel blog continues to read published posts, tags and authors from its
own finobe database. A metadata-only snapshot is kept for reference at
research/finobe-blog/homepage-snapshot.json.

Use the existing website checkout and PHP runtime. After pulling the `finobe` branch:

```sh
php artisan migrate --database=finobe --force
php artisan storage:link
php artisan optimize:clear
```

Configure the real hostnames in `.env`:

```dotenv
APP_URL=https://finobe.wtf
FINOBE_DOMAIN=finobe.wtf
BLOG_URL=https://blog.finobe.wtf
BLOG_TWITTER_URL=
```

When `BLOG_URL` is omitted, it derives `blog.<FINOBE_DOMAIN>` using `APP_URL`'s scheme. `BLOG_URL` allows an explicit port for local development, such as `http://blog.localhost:8080`. The main website and blog must have different hostnames. Both versions of the main navbar read this configuration.

Point the blog hostname's DNS A/AAAA record at the web server, or use a CNAME to the website's hostname when appropriate. Add the hostname to the existing TLS/web-server configuration and serve **the same** Laravel `public/` directory. A separate Ghost installation or Node process is not required. DNS and the production virtual host were not changed by this source update.

Example Caddy site block; adapt paths and PHP socket to the actual server:

```caddyfile
blog.finobe.wtf {
    root * /var/www/finobewtf/public
    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server
}
```

For Nginx, add a server for `blog.finobe.wtf` with the same document root, PHP-FPM configuration, and `try_files $uri $uri/ /index.php?$query_string;` as the existing website. Keep existing PHP upload execution protections. The public blog routes are outside the legacy game-site Redis/IP middleware.

Uploads go to `storage/app/public/blog/YYYY/MM/`. Persist that directory and make it writable by PHP. The `public/storage` symlink makes uploads available from both hostnames. Back up the `blog_*` tables and those uploads together. Original theme assets are tracked files, not runtime uploads.

## Administration

Open **Admin → Blog** on the main website, or `/admin/blog`.

- Create authors and tags, then write a post.
- Save drafts, preview saved drafts privately, publish immediately, or set a scheduled publication date.
- Edit the title, slug, body, excerpt, feature image/description, author, and tags. The editor accepts Markdown; raw executable HTML and unsafe link schemes are stripped.
- Upload JPEG, PNG, GIF, or WebP images, then use them as feature images or insert their paths into the post. SVG and non-image uploads are rejected.
- Archive posts without deleting their history. Restoring an archived post makes it a draft.
- Restore an earlier revision as a new draft revision. Stale editor submissions are rejected instead of overwriting a newer save.
- Change blog title, description, logo, cover, and an optional Twitter link.

Full administrators have Blog access. Owners can grant/revoke the separate **Blog** scope through the existing Staff controls. Blog editors do not gain moderation or account-management access. Every blog write records its actor and before/after state in the admin audit table. Private previews return `noindex` and `no-store` headers.

Scheduled posts become public when their publication time is reached; the visibility query checks time directly, so no cron or queue worker is needed for publication. Times use the configured application timezone, shown in the editor. The URL uses that publication date. Old article URLs redirect after slug/date changes, while unpublished or archived targets remain inaccessible.

No historical Finobe articles, original staff identities, preview accounts, or sample posts are seeded into the live site. The screenshots use disposable local database fixtures.

## Public routes

| Route | Behavior |
| --- | --- |
| `/` | Published posts, newest first |
| `/page/2/` | Next batch of 25 posts, used by Casper's infinite scroll |
| `/YYYY/MM/DD/slug/` | Published article, byline, reading time, tags, related posts |
| `/author/slug/` | Author profile and published posts |
| `/tag/slug/` | Tagged published posts |
| `/author/slug/page/2/`, `/tag/slug/page/2/` | Archive pagination |
| `/rss/` | RSS feed with full rendered post content |
| `/author/slug/rss/`, `/tag/slug/rss/` | Filtered feeds |
| `/sitemap.xml` | Public article URLs |

Drafts and future posts are excluded from lists, article routes, feeds, related posts, and sitemap. Empty blogs display the recovered header with an empty-state message. No fake content is substituted.

## Verification

```sh
php vendor/bin/phpunit tests/Feature/BlogTest.php
php vendor/bin/phpunit tests/Feature/AdminWorkbenchTest.php
php vendor/bin/phpunit --testsuite Unit
node --check public/s/blog/admin.js
php artisan view:cache
```

The blog feature suite covers publication through the admin route; all public page types; original theme assets/markup; next-page links; RSS parsing; scheduled visibility; draft privacy; permission checks; private previews; revision recovery; conflict prevention; redirects; archive/restore; author deletion protection; tag detachment; image upload validation; and executable content rejection. Browser checks additionally verified an admin save and the resulting public article. Production MySQL, DNS, PHP-FPM, and persistent media storage still need verification on the deployment server.
