<?php

return [
    'domain' => env('FINOBE_DOMAIN', 'finobe.wtf'),

    // Filesystem root of the CDN (cdn.<domain>) web root on the server.
    'cdn_path' => rtrim(env('FINOBE_CDN_PATH', '/var/www/cdn.finobe.net'), '/'),

    // The Windows machine running the patched RCCService (see tools/rcc/README.md).
    'arbiter' => [
        // address the web server uses to reach RCC's SOAP port (keep this private/firewalled)
        'host' => env('FINOBE_ARBITER_HOST', '127.0.0.1'),
        'port' => (int) env('FINOBE_ARBITER_PORT', 64989),
    ],

    // Where players connect for game servers (public IP of the RCC machine) and the UDP ports RCC may use.
    'gameserver' => [
        'host' => env('FINOBE_GAMESERVER_HOST', env('FINOBE_ARBITER_HOST', '127.0.0.1')),
        'port_min' => (int) env('FINOBE_GAMESERVER_PORT_MIN', 53640),
        'port_max' => (int) env('FINOBE_GAMESERVER_PORT_MAX', 53740),
    ],

    // 2012 game servers: there's no 2012 RCC, so tools/gameserver2012/host2012.ps1 on the game server machine
    // runs the 2012 client as the server. Address the web server uses to reach it (keep it private/firewalled).
    'host2012' => [
        'url' => rtrim((string) env('FINOBE_HOST2012_URL', ''), '/'),   // e.g. http://10.0.0.5:64990
    ],

    /*
        The clients a place can be made for (the "version" in a place's settings). Each RCC-based year runs
        its own RCCService (same SOAP API as 2016) and can live on its own machine; leave the host/port empty
        to use the 2016 one's machine with the default port. 'enabled' hides a year from the site until its
        client and game servers are set up.
          server: rcc      = RCCService job, script storage/rbx/files/scripts/<script>
                  host2012 = the 2012 client hosts (App\Services\Host2012)
          join:   json     = JSON join script signed --rbxsig (2016+)
                  lua2012  = storage/rbx/files/scripts/join2012.lua signed V1
          Every year is installed and started by FinobeLauncher (finobe-launcher://...+clientversion:<year>):
          publish a year's build with tools/client/package_client.py --year <year> and set
          FINOBE_CLIENT_VERSION_<year> (2016: FINOBE_CLIENT_VERSION).
    */
    'clients' => [
        '2012' => [
            'enabled' => true,
            'server' => 'host2012',
            'join' => 'lua2012',
            'scheme' => 'http',
            // Public address 2012 players connect to for game UDP traffic. Leave FINOBE_GAMESERVER_HOST_2012
            // unset when host2012.ps1 runs on the same machine as the 2016 game servers (falls back to the
            // shared FINOBE_GAMESERVER_HOST). Set it only when the 2012 host is a SEPARATE machine, to that
            // machine's public address. Never the private FINOBE_HOST2012_URL control address.
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

    // About Us > "Who works on Finobe?": your team, in order. The card is hidden while this is empty.
    // e.g. ['role' => 'Owner', 'name' => 'yourname', 'id' => 1]   (id = their /user/<id>, or null for no link)
    'about_team' => [],

    // Home > Quick Links. The real site linked its own Discord, YouTube and Twitter; a card only shows when its link is set.
    'social' => [
        'discord' => env('FINOBE_DISCORD_URL', 'https://discord.gg/finob'),
        'youtube' => env('FINOBE_YOUTUBE_URL', ''),
        'twitter' => env('FINOBE_TWITTER_URL', ''),
    ],

    // the footer's "(name)" after the lucky number, like the real site's "(bumpy)"; blank hides it
    'server_name' => env('FINOBE_SERVER_NAME', ''),

    // Settings > User Details > Name Change
    'name_change' => [
        'cost' => (int) env('FINOBE_NAME_CHANGE_COST', 150),                  // Dius
        'cooldown_days' => (int) env('FINOBE_NAME_CHANGE_COOLDOWN_DAYS', 0),  // the real site had none
    ],
];
