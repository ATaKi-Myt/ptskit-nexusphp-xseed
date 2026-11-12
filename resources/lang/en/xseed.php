<?php

return [
    'nav_label' => 'Cross-seed sites',
    'field_name' => 'Site name',
    'field_name_help' => 'Shown on the torrent details page.',
    'field_base_url' => 'Site URL',
    'field_base_url_help' => 'e.g. https://a.example.com — no trailing slash. Must match the "this site URL" on the remote installation.',
    'field_secret' => 'Shared secret',
    'field_secret_help' => 'The same string on both sides, used for HMAC signing. Use 32+ random characters.',
    'field_icon' => 'Icon URL',
    'field_icon_help' => 'Leave empty to use "<site URL>/favicon.ico".',
    'field_enabled' => 'Enabled',
    'col_last_sync' => 'Last sync',
    'action_sync' => 'Sync now',
    'row_label' => 'Cross-seed sites',
    'col_label' => 'XSeed',
    'col_tip' => 'Number of partner sites that have the same info_hash',
];
