<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.2.1：把「命中 / 未命中」存成真实列，不再让人从 last_sync_result 的文本里猜。
 *
 * last_sync_total = 本轮问了多少个种子
 * last_sync_hit   = 其中对方也有的
 * 未命中 = total - hit，由模型访问器算出来，不单独存。
 *
 * last_sync_result 保留，用来放 OK/ERR 原始文案（列表里当 tooltip 用）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_xseed_sites', function (Blueprint $table) {
            $table->unsignedInteger('last_sync_total')->default(0)->after('last_sync_at');
            $table->unsignedInteger('last_sync_hit')->default(0)->after('last_sync_total');
        });
    }

    public function down(): void
    {
        Schema::table('plugin_xseed_sites', function (Blueprint $table) {
            $table->dropColumn(['last_sync_total', 'last_sync_hit']);
        });
    }
};
