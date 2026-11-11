<?php
return [
    'owner_ids' => array_filter(array_map('intval', explode(',', env('ADMIN_OWNER_IDS', '')))),
    'invites_enabled' => env('FINOBE_INVITE_KEYS', false),
    'cdn_url' => env('FINOBE_CDN_URL', 'https://cdn.'.env('FINOBE_DOMAIN', 'finobe.wtf')),
    'cdn_path' => env('FINOBE_CDN_PATH', '/var/www/cdn.'.env('FINOBE_DOMAIN', 'finobe.wtf')),
];
