<?php

return [
    'nav_label' => '辅种站点',
    'field_name' => '站点名称',
    'field_name_help' => '前台详情页会显示这个名字。',
    'field_base_url' => '站点地址',
    'field_base_url_help' => '如 https://a.example.com，末尾不要带斜杠。必须和对端插件里填的「本站地址」一致。',
    'field_secret' => '通讯密钥',
    'field_secret_help' => '双方约定的同一个字符串，用于 HMAC 签名。建议 32 位以上随机串。',
    'field_icon' => '图标地址',
    'field_icon_help' => '留空则自动用「站点地址/favicon.ico」。',
    'field_enabled' => '启用',
    'col_last_sync' => '最后同步',
    'action_sync' => '立即同步',
    'row_label' => '辅种站点',
    'col_label' => '辅种',
    'col_tip' => '有相同 info_hash 的友站数量，点击查看详情',
];
