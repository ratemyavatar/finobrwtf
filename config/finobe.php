<?php

return [
    'domain' => env('FINOBE_DOMAIN', 'finobe.wtf'),
    'ads' => (bool) env('FINOBE_ADS', false),
    'cloud_key' => (string) env('FINOBE_CLOUD_AUTHORIZATION', ''),
    'roblosecurity' => (string) env('FINOBE_ROBLOSECURITY', ''),
    'python' => env('FINOBE_PYTHON', 'python3'),
    'assetdelivery' => env('FINOBE_ASSETDELIVERY', 'https://assetdelivery.roproxy.com'),
    'client_version' => env('FINOBE_CLIENT_VERSION', 'version-xqs0ranlc9ofKJwiJtSd'),
    'client_versions' => [
        '2007' => env('FINOBE_CLIENT_VERSION_2007', ''),
        '2012' => env('FINOBE_CLIENT_VERSION_2012', ''),
        '2017' => env('FINOBE_CLIENT_VERSION_2017', ''),
        '2018' => env('FINOBE_CLIENT_VERSION_2018', ''),
        '2020' => env('FINOBE_CLIENT_VERSION_2020', ''),
    ],
    'features' => [
        'forum_post' => (bool) env('FINOBE_FORUM_POST', false),
        'create_places' => (bool) env('FINOBE_CREATE_PLACES', false),
        'create_assets' => (bool) env('FINOBE_CREATE_ASSETS', false),
        'create_invite_keys' => (bool) env('FINOBE_CREATE_INVITE_KEYS', false),
    ],
    'trusted_proxies' => env('FINOBE_TRUSTED_PROXIES', '127.0.0.1,::1'),
    'mail' => [
        'mode' => (int) env('FINOBE_MAIL_MODE', 3),
        'from' => env('MAIL_FROM_ADDRESS', 'noreply@finobe.wtf'),
        'zepto_key' => env('FINOBE_ZOHO_API_KEY'),
        'smtp2go_key' => env('FINOBE_SMTP2GO_API_KEY'),
    ],
    'turnstile' => [
        'secret' => env('TURNSTILE_SECRET'),
        'sitekey' => env('TURNSTILE_SITEKEY'),
    ],
    'discord_webhook' => env('DISCORD_WEBHOOK') ?: (env('FINOBE_DISCORD_WEBHOOK') ? 'https://discord.com/api/webhooks/' . env('FINOBE_DISCORD_WEBHOOK') : null),

    'cdn_path' => rtrim(env('FINOBE_CDN_PATH', '/var/www/cdn.finobe.net'), '/'),

    'arbiter' => [
        'host' => env('FINOBE_ARBITER_HOST', '127.0.0.1'),
        'port' => (int) env('FINOBE_ARBITER_PORT', 64989),
    ],

    'gameserver' => [
        'host' => env('FINOBE_GAMESERVER_HOST', env('FINOBE_ARBITER_HOST', '127.0.0.1')),
        'port_min' => (int) env('FINOBE_GAMESERVER_PORT_MIN', 53640),
        'port_max' => (int) env('FINOBE_GAMESERVER_PORT_MAX', 53740),
    ],

    'host2012' => [
        'url' => rtrim((string) env('FINOBE_HOST2012_URL', ''), '/'),
    ],

    'clients' => [
        '2012' => [
            'enabled' => true,
            'server' => 'host2012',
            'join' => 'lua2012',
            'scheme' => 'http',

            'gameserver_host' => env('FINOBE_GAMESERVER_HOST_2012', ''),
        ],
        '2016' => [
            'enabled' => true,
            'server' => 'rcc',
            'script' => 'gameserver2016.lua',
            'join' => 'json',
            'scheme' => 'https',
            'rcc_host' => env('FINOBE_ARBITER_HOST', '127.0.0.1'),
            'rcc_port' => (int) env('FINOBE_ARBITER_PORT', 64989),
            'gameserver_host' => env('FINOBE_GAMESERVER_HOST', env('FINOBE_ARBITER_HOST', '127.0.0.1')),
        ],
    ],

    'about_team' => [],

    'social' => [
        'discord' => env('FINOBE_DISCORD_URL', 'https://discord.gg/finob'),
        'youtube' => env('FINOBE_YOUTUBE_URL', ''),
        'twitter' => env('FINOBE_TWITTER_URL', ''),
    ],

    'server_name' => env('FINOBE_SERVER_NAME', ''),

    'name_change' => [
        'cost' => (int) env('FINOBE_NAME_CHANGE_COST', 150),
        'cooldown_days' => (int) env('FINOBE_NAME_CHANGE_COOLDOWN_DAYS', 0),
    ],
];
