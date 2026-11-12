<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.2.0：每条友站可以单独设置同步间隔（分钟），默认 30。
 *
 * 调度器每分钟叫一次 `xseed:sync`，命令内部按这个间隔判断哪些站点到期，
 * 没到期就直接跳过，所以站点一多也不会互相拖累。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_xseed_sites', function (Blueprint $table) {
            $table->unsignedSmallInteger('interval_minutes')->default(30)->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('plugin_xseed_sites', function (Blueprint $table) {
            $table->dropColumn('interval_minutes');
        });
    }
};
