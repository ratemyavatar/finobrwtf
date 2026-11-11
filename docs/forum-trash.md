# forum trash posts

some forum titles are trash, like "is finobe a virus".

the list is in config/forum.php under trash. matching ignores case and punctuation and only counts whole words.

when someone types one of these titles and hits post, a box pops up and warns them. if they press continue anyway, they get a perm ban with the reason in trash_ban_reason, and the post is not made.

it also works without javascript. the first post only shows the warning, it does not ban.

admins are skipped.

the angry face is the old noto blob pouting face from noto emoji 2017 (apache 2.0), saved at public/s/img/emoji/pouting.png.
