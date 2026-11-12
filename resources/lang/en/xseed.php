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
    'field_interval' => 'Sync interval (minutes)',
    'field_interval_help' => 'How often to sync, default 30. The first sync starts right after saving; no manual button needed.',
    'field_enabled' => 'Enabled',

    // Table
    'col_id' => 'ID',
    'col_name' => 'Site name',
    'col_base_url' => 'Site URL',
    'col_enabled' => 'Status',
    'col_interval' => 'Sync interval',
    'col_last_sync' => 'Last sync at',
    'col_hit' => 'Matched',
    'col_miss' => 'Not matched',
    'status_enabled' => 'Enabled',
    'status_disabled' => 'Disabled',
    'unit_minutes' => 'min',

    // Actions
    'action_create' => 'Add site',

    // Front-end
    'row_label' => 'Cross-seed sites',
    'col_label' => 'XSeed',
    'col_tip' => 'Number of partner sites that have the same files, click for details',
];
