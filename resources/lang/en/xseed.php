<?php

return [
    // Sidebar / page title
    'nav_label' => 'Cross-seed sites',
    'model_label' => 'Cross-seed site',
    'model_label_plural' => 'Cross-seed sites',

    // Form
    'field_name' => 'Site name',
    'field_name_help' => 'Shown on the torrent details page.',
    'field_base_url' => 'Site URL',
    'field_base_url_help' => 'e.g. https://a.example.com — no trailing slash. Must match the "this site URL" on the remote installation.',
    'field_secret' => 'Shared secret',
    'field_secret_help' => 'The same string on both sides, used for HMAC signing. Use 32+ random characters.',
    'field_icon' => 'Icon URL',
    'field_icon_help' => 'Leave empty to use "<site URL>/favicon.ico".',
    'field_enabled' => 'Enabled',

    // Table
    'col_id' => 'ID',
    'col_name' => 'Site name',
    'col_base_url' => 'Site URL',
    'col_enabled' => 'Status',
    'col_last_sync' => 'Last sync',
    'col_last_sync_result' => 'Last sync result',
    'status_enabled' => 'Enabled',
    'status_disabled' => 'Disabled',

    // Actions
    'action_create' => 'Add site',
    'action_sync' => 'Sync now',

    // Front-end
    'row_label' => 'Cross-seed sites',
    'col_label' => 'XSeed',
    'col_tip' => 'Number of partner sites that have the same info_hash',
];
