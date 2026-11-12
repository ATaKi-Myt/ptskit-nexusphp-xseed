<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 友站名单：我拉谁
        Schema::create('plugin_xseed_sites', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 50)->default('');
            $table->string('base_url', 255)->default('');
            $table->string('secret', 64)->default('');
            $table->string('icon', 255)->default('');
            $table->tinyInteger('enabled')->default(1);
            $table->unsignedInteger('last_sync_at')->default(0);
            $table->string('last_sync_result', 255)->default('');
            $table->string('last_sync_cursor', 32)->default('');
            $table->unique('base_url');
        });

        // 交集缓存：本站 torrent_id <-> 对方 torrent_id
        // 全部是「缓存」，可随时 TRUNCATE 后重建，后期搬中心服务器不用改结构
        Schema::create('plugin_xseed_peers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('torrent_id');
            $table->unsignedInteger('site_id');
            $table->unsignedInteger('peer_torrent_id')->default(0);  // 0 = 查过但对方没有
            $table->unsignedInteger('checked_at')->default(0);
        });

        // info_hash 用 binary(20)，跟主表 torrents 完全一致，JOIN / 比对不用转换
        DB::statement("ALTER TABLE `plugin_xseed_peers`
            ADD COLUMN `info_hash` binary(20) NOT NULL AFTER `site_id`,
            ADD UNIQUE KEY `uk_site_hash` (`site_id`, `info_hash`),
            ADD KEY `idx_torrent_site` (`torrent_id`, `site_id`)");
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_xseed_peers');
        Schema::dropIfExists('plugin_xseed_sites');
    }
};
