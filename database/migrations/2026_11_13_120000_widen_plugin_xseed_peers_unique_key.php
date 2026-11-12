<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.2.4：修「同一 pieces_hash 有多个本地种子时互相覆盖」
 *
 * 老唯一键是 (site_id, pieces_hash)，一个站点一个 hash 只能存一行。
 * 本站同一个片源发了两遍（不同 torrent_id、同 pieces_hash）时，
 * 第二行 upsert 会把第一行的 torrent_id 顶掉，于是两个种子里有且只有
 * 一个能在列表里看到友站数。
 *
 * 改成 (site_id, pieces_hash, torrent_id)：每个本地种子各存一行，
 * 同一 hash 的不同种子都能查到自己的计数。upsert 的冲突列同步改。
 *
 * 顺带加 idx_site_hit_checked，给「滚动复核」用：
 *   where site_id = ? and peer_torrent_id > 0 and checked_at < ? order by checked_at
 * 没有这个索引，每次复核都要扫全表。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_xseed_peers', function (Blueprint $table) {
            $table->dropUnique('uk_site_pieces');
            $table->unique(['site_id', 'pieces_hash', 'torrent_id'], 'uk_site_pieces_torrent');
            $table->index(['site_id', 'peer_torrent_id', 'checked_at'], 'idx_site_hit_checked');
        });
    }

    public function down(): void
    {
        // 老唯一键不允许「同 site + 同 hash 的多行」，回滚前先把重复行去掉，
        // 否则会撞 Duplicate entry，卸载流程会中断在半路。
        DB::statement(
            'DELETE p1 FROM plugin_xseed_peers p1 '
            . 'INNER JOIN plugin_xseed_peers p2 '
            . 'ON p1.site_id = p2.site_id AND p1.pieces_hash = p2.pieces_hash AND p1.id > p2.id'
        );

        Schema::table('plugin_xseed_peers', function (Blueprint $table) {
            $table->dropUnique('uk_site_pieces_torrent');
            $table->dropIndex('idx_site_hit_checked');
            $table->unique(['site_id', 'pieces_hash'], 'uk_site_pieces');
        });
    }
};
