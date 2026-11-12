<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.1.1 及更早版本把 icon 建成了 NOT NULL DEFAULT ''，
 * 而 Filament 的空文本输入 dehydrate 成 null ⇒ 保存友站直接
 * SQLSTATE[23000] 1048 Column 'icon' cannot be null。
 *
 * 这里只做一件事：改成可空。已装过 v0.1.x 的站点跑一次
 * `php artisan plugin install ptskit/nexusphp-xseed` 即可自动执行。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_xseed_sites', function (Blueprint $table) {
            $table->string('icon', 255)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('plugin_xseed_sites', function (Blueprint $table) {
            $table->string('icon', 255)->default('')->nullable(false)->change();
        });
    }
};
