<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.2.6：secret 加密存库
 *
 * 之前通讯密钥是明文，数据库被拖走就等于所有互通站都能被冒充调接口。
 * 改成 `enc:` + Crypt::encryptString，读取时兼容没有前缀的老明文，
 * 所以这个迁移只是把存量顺手加个密，不影响任何运行中的同步。
 *
 * 密文比明文长（64 字节明文大约 180 字符），字段放宽到 255。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_xseed_sites', function (Blueprint $table) {
            $table->string('secret', 255)->default('')->change();
        });

        $rows = DB::table('plugin_xseed_sites')
            ->where('secret', '<>', '')
            ->get(['id', 'secret']);

        foreach ($rows as $row) {
            if (strncmp((string) $row->secret, 'enc:', 4) === 0) {
                continue;   // 已经加密过，重跑安全
            }

            DB::table('plugin_xseed_sites')
                ->where('id', $row->id)
                ->update(['secret' => 'enc:' . Crypt::encryptString((string) $row->secret)]);
        }
    }

    public function down(): void
    {
        $rows = DB::table('plugin_xseed_sites')
            ->where('secret', 'like', 'enc:%')
            ->get(['id', 'secret']);

        foreach ($rows as $row) {
            try {
                $plain = Crypt::decryptString(substr((string) $row->secret, 4));
            } catch (\Throwable $throwable) {
                continue;   // 解不开就留着，别把数据弄丢
            }

            DB::table('plugin_xseed_sites')
                ->where('id', $row->id)
                ->update(['secret' => $plain]);
        }

        Schema::table('plugin_xseed_sites', function (Blueprint $table) {
            $table->string('secret', 64)->default('')->change();
        });
    }
};
