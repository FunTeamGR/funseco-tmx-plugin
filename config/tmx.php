<?php
return [
    'default' => 'tmnf',

    // json_api: the host serves the TMNF-X JSON API (/api/tracks, /api/replays). Only those
    // can be looked up / searched; the others still work for /admin add downloads.
    // image_url: screenshot ({id} = TMX id). The TMF client may not load https images, so
    // TMNF uses the legacy http host that still serves screenshots.
    'exchanges' => [
        'tmnf' => [
            'name'      => 'TM Nations Forever',
            'section'   => 'TMNF',
            'base_url'  => 'https://tmnf.exchange',
            'json_api'  => true,
            'image_url' => 'http://tmnforever.tm-exchange.com/get.aspx?action=trackscreen&id={id}',
        ],
        'tmu' => [
            'name'      => 'TM United Forever',
            'section'   => 'TMU',
            'base_url'  => 'https://tmuf.exchange',
            'json_api'  => true,
            'image_url' => 'https://tmuf.exchange/trackshow/{id}/image/1',
        ],
        'tms' => [
            'name'     => 'TM Sunrise',
            'section'  => 'TMS',
            'base_url' => 'https://sunrise.tm-exchange.com',
            'legacy_api' => true,
        ],
        'tmn' => [
            'name'     => 'TM Nations',
            'section'  => 'TMN',
            'base_url' => 'https://nations.tm-exchange.com',
            'legacy_api' => true,
        ],
    ],

    // legacy_api: the old TMX host (apiget.aspx, tab-separated lines). Lookup (/tmxinfo, /tmxrecs) only.
    // Section names players may type (/tmxinfo 123 tmu). TMO has no exchange any more.
    'sections' => ['TMO', 'TMS', 'TMN', 'TMU', 'TMNF'],
    // Used when no section is given (XAseco: TMNF on a Stadium-only server, else TMU).
    'default_section' => 'TMNF',
    // Map-start lookup order by environment (first section that knows the track wins).
    'lookup_sections' => [
        'Stadium' => ['TMNF', 'TMU'],
        '*'       => ['TMU'],
    ],

    // TMX world record at map start: 0 = off, 1 = chat (2 = message window in XAseco; sent to chat here).
    'show_tmxrec' => 1,

    // /rtmx: random TMX map. url = TMX random endpoint with the owner's filters (tag ids 1,2,3,6,12,
    // difficulty 1, author time 10-21 s); it redirects to .../trackshow/<id>. The exact query-string
    // names are UNKNOWN (the owner's URL was not in the spec): placeholder, check against the exchange.
    // extra_allowed: non-admin logins who may use it too. bot_login: login of the synthetic requester
    // (empty = server.login); the map stays queued even when the caller leaves.
    'rtmx' => [
        'url' => env('TMX_RTMX_URL', 'https://tmnf.exchange/trackrandom?tags=1,2,3,6,12&difficulty=1&authortimemin=10000&authortimemax=20999'),
        'extra_allowed' => array_filter(array_map('trim', explode(',', env('TMX_RTMX_ALLOWED', '')))),
        'bot_login' => env('TMX_RTMX_BOT_LOGIN', ''),
        'bot_nickname' => '$0f0TMX $fffRandomizer',
        'timeout' => 10,
    ],

    'xlist' => [
        // Rows of /xlist that can be clicked (TMX id → /tmxinfo, name → /add, Add → /admin add, author → /xlist auth:).
        'clickable' => true,
        'clickable_rows' => 500,
        'max_results' => 500,
        'page_size' => 100,
        'recent_count' => 10,
    ],

    // TMNF-X returns numbers for these. Unknown codes are shown as the number.
    'enums' => [
        'mood' => [0 => 'Sunrise', 1 => 'Day', 2 => 'Sunset', 3 => 'Night'],
        'difficulty' => [0 => 'Beginner', 1 => 'Intermediate', 2 => 'Expert', 3 => 'Lunatic'],
        // only 0 verified; others: legacy TMX names
        'routes' => [0 => 'Single', 1 => 'Multiple', 2 => 'Symmetrical'],
        // only 0 verified; others: legacy TMX type names
        'type' => [0 => 'Race', 1 => 'Puzzle', 2 => 'Platform', 3 => 'Stunts', 4 => 'Shortcut', 5 => 'Laps'],
        // only 7 (Stadium) verified (the only TMNF-X value)
        'environment' => [7 => 'Stadium'],
        // GET /api/meta/tags
        'style' => [
            0 => 'Race', 1 => 'Stunt', 2 => 'Maze', 3 => 'Offroad', 4 => 'Multilap', 5 => 'FullSpeed', 6 => 'LOL',
            7 => 'Tech', 8 => 'SpeedTech', 9 => 'RPG', 10 => 'PressForward', 11 => 'Trial', 12 => 'Grass',
            13 => 'Story', 14 => 'Nascar', 15 => 'Speedfun', 16 => 'Endurance', 17 => 'Altered Nadeo', 18 => 'Transitional',
        ],
    ],

    'messages' => [
        'tmxrec'      => '{#server}>> {#record}TMX World Record: {#highlite}{1}{#record} by {#highlite}{2}',
        'no_section'  => '{#server}> {#error}No such section on TMX!',
        'invalid_id'  => '{#server}> {#highlite}{1}{#error} is not a valid Track/TMX ID!',
        'not_found'   => '{#server}> {#highlite}{1}{#error} is not a known TMX track, or wrong TMX section, or TMX is down!',
        'no_records'  => '{#server}> {#error}No TMX records found for {#highlite}$i {1}',
        'rtmx_fetching' => '{#server}> {#highlite}Fetching random TMX map...',
        'rtmx_busy'   => '{#server}> {#error}TMX busy or ID not found. Try again.',
        'rtmx_denied' => '{#server}> {#error}You do not have permission to use this command.',
        'xlist_none'  => '{#server}> {#error}No tracks found, or TMX is down!',
    ],
];
