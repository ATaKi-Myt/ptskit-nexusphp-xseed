<?php

return [
    // 侧栏 / 页面标题
    'nav_label' => '辅种站点',
    'model_label' => '辅种站点',
    'model_label_plural' => '辅种站点',

    // 表单
    'field_name' => '站点名称',
    'field_name_help' => '前台详情页会显示这个名字。',
    'field_base_url' => '站点地址',
    'field_base_url_help' => '如 https://a.example.com，末尾不要带斜杠。必须和对端插件里填的「本站地址」一致。',
    'field_secret' => '通讯密钥',
    'field_secret_help' => '双方约定的同一个字符串，用于 HMAC 签名。建议 32 位以上随机串。',
    'field_icon' => '图标地址',
    'field_icon_help' => '留空则自动用「站点地址/favicon.ico」。',
    'field_enabled' => '启用',

    // 列表
    'col_id' => 'ID',
    'col_name' => '站点名称',
    'col_base_url' => '站点地址',
    'col_enabled' => '状态',
    'col_last_sync' => '最后同步',
    'col_last_sync_result' => '最后同步结果',
    'status_enabled' => '启用',
    'status_disabled' => '停用',

    // 动作
    'action_create' => '添加友站',
    'action_sync' => '立即同步',

    // 前台
    'row_label' => '辅种站点',
    'col_label' => '辅种',
    'col_tip' => '有相同 info_hash 的友站数量，点击查看详情',
];
