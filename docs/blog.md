# finobe blog

the blog is a capture of the january 2021 finobe.com ghost blog (casper theme), running on this laravel app and the `finobe` database. there's no ghost or node install.

the capture came from the wayback machine. the original `screen.css`, `casper.js`, logo, cover and favicon are in `public/s/blog`. their hashes are in [blog-capture.json](blog-capture.json). casper's mit notice is next to them.

the templates are in `resources/views/blog/partials`. the original javascript reads the `rel="next"` link and adds the next page's posts, so infinite scroll works like before. old analytics and subscriptions aren't used. twitter only shows if you set it.

the capture has six old post titles, dates and short excerpts, but not the full posts. don't make up the missing text. a snapshot of what's there is in `research/finobe-blog/homepage-snapshot.json`.

## setup

```sh
php artisan migrate --database=finobe --force
```

```sh
php artisan storage:link
```

```sh
php artisan optimize:clear
```

then set the hostnames in `.env`:

```dotenv
APP_URL=https://finobe.wtf
FINOBE_DOMAIN=finobe.wtf
BLOG_URL=https://blog.finobe.wtf
BLOG_TWITTER_URL=
```

if `BLOG_URL` is empty it uses `blog.<FINOBE_DOMAIN>`. the blog and the main site need different hostnames.

point the blog hostname at the web server and serve the same `public` folder. a caddy example:

```caddyfile
blog.finobe.wtf {
    root * /var/www/finobewtf/public
    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server
}
```

for nginx, copy the main site's server block and change the name.

uploads go to `storage/app/public/blog/YYYY/MM/`. keep that folder and make it writable. back it up with the `blog_*` tables.

## writing posts

open admin, then blog, or go to `/admin/blog`.

- make authors and tags, then write a post
- save a draft, preview it privately, publish now, or pick a date to publish later
- posts use markdown. unsafe html and links get removed
- upload jpeg, png, gif or webp images. svg and other files are refused
- archive a post to hide it without losing it. restoring makes it a draft again
- old versions can be brought back as a new draft. if someone saved after you opened the editor, your save is refused instead of overwriting theirs
- change the blog title, description, logo, cover and twitter link

full admins can use the blog. owners can give the separate blog permission to other staff. every blog change goes in the audit log.

scheduled posts show up on their own when their time comes. no cron is needed.

## public pages

| route | what it shows |
| --- | --- |
| `/` | newest posts |
| `/page/2/` | the next 25 posts |
| `/YYYY/MM/DD/slug/` | a post |
| `/author/slug/` | an author and their posts |
| `/tag/slug/` | posts with a tag |
| `/rss/` | the rss feed |
| `/author/slug/rss/`, `/tag/slug/rss/` | feeds for one author or tag |
| `/sitemap.xml` | all public post urls |

drafts and future posts never show up on any of these.

## tests

```sh
php vendor/bin/phpunit tests/Feature/BlogTest.php
```
