<?php

return [
    // 側欄 / 頁面標題
    'nav_label' => '輔種站點',
    'model_label' => '輔種站點',
    'model_label_plural' => '輔種站點',

    // 表單
    'field_name' => '站點名稱',
    'field_name_help' => '前台詳情頁會顯示這個名字。',
    'field_base_url' => '站點地址',
    'field_base_url_help' => '如 https://a.example.com，末尾不要帶斜線。必須和對端插件裡填的「本站地址」一致。',
    'field_secret' => '通訊密鑰',
    'field_secret_help' => '雙方約定的同一個字串，用於 HMAC 簽名。建議 32 位以上隨機串。',
    'field_icon' => '圖示地址',
    'field_icon_help' => '留空則自動用「站點地址/favicon.ico」。',
    'field_interval' => '同步間隔（分鐘）',
    'field_interval_help' => '每隔多少分鐘同步一次，預設 30。儲存後立刻開始第一次同步，之後自動執行，不用再點按鈕。',
    'field_enabled' => '啟用',

    // 列表
    'col_id' => 'ID',
    'col_name' => '站點名稱',
    'col_base_url' => '站點地址',
    'col_enabled' => '狀態',
    'col_interval' => '間隔（分鐘）',
    'col_last_sync' => '最後同步',
    'col_last_sync_result' => '最後同步結果',
    'status_enabled' => '啟用',
    'status_disabled' => '停用',

    // 動作
    'action_create' => '新增友站',

    // 前台
    'row_label' => '輔種站點',
    'col_label' => '輔種',
    'col_tip' => '有同源片源的友站數量，點擊查看詳情',
];
