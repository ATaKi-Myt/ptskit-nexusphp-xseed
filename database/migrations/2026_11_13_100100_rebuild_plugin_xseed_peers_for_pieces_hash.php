<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.2.0：匹配键从 info_hash 换成 pieces_hash。
 *
 * 为什么必须换：NexusPHP 发种时会往 info 字典里塞
 *   source = "[本站URL] 本站名"
 * 所以「同一套片源」在两个站生成的 info_hash 天然不同，用 info_hash 永远匹配不上。
 * 而 torrents.pieces_hash = sha1(info.pieces) 只跟片数据 + 分片大小有关，跨站稳定，
 * 主程序自己也在用它找同源种子（`/api/pieces-hash`）。
 *
 * 这张表是纯缓存（可随时清空重建），所以直接重建，不做数据迁移。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('plugin_xseed_peers');

        Schema::create('plugin_xseed_peers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('torrent_id');
            $table->unsignedInteger('site_id');
            // = torrents.pieces_hash，char(40) 与主表一致
            $table->char('pieces_hash', 40)->default('');
            $table->unsignedInteger('peer_torrent_id')->default(0);  // 0 = 问过，对方没有
            $table->unsignedInteger('checked_at')->default(0);

            $table->unique(['site_id', 'pieces_hash'], 'uk_site_pieces');
            $table->index(['torrent_id', 'site_id'], 'idx_torrent_site');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_xseed_peers');
    }
};
