<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.2.8：secret 字段 255 -> 512
 *
 * 0.2.6 把 secret 改成 `enc:` + Crypt::encryptString 存库，字段只放宽到 255，
 * 而迁移注释里「64 字节明文大约 180 字符」的估算是错的。实测（AES-256-CBC + base64 JSON）：
 *
 *   明文 ≤15 字符 -> 204 字符
 *   明文 16~31    -> 232 字符
 *   明文 32~47    -> 260 字符   <-- 已经超过 255
 *   明文 64       -> 316 字符
 *
 * 也就是说「填一串 32 位随机密钥」这个最常见的用法，密文会被 MySQL 截断成 255
 * （非严格模式静默截断，不报错），base64 解不回完整 JSON，解密抛异常，
 * getSecretAttribute() 只能返回空串 —— 表现为「后台明明填过，同步却说密钥为空」，
 * 或者对端一直 401 bad signature。
 *
 * 放宽到 512 可以容纳 100 字符的明文，足够覆盖表单 maxLength(64) 的上限。
 *
 * 已经被截断的旧数据不在这里清理：解不开的密文和无值在使用上完全等价
 * （读出来都是空串），保留原值反而方便出问题后回查。修完字段宽度后，
 * 在后台把这些站点的密钥重填一次即可恢复。
 */
return new class extends Migration
{
    /** 表单最多允许 64 字符明文，512 足够容纳其密文 */
    const LENGTH = 512;

    public function up(): void
    {
        Schema::table('plugin_xseed_sites', function (Blueprint $table) {
            $table->string('secret', self::LENGTH)->default('')->change();
        });

        // 顺手统计一下有多少条已经解不开了，让 plugin install 的输出能说明问题。
        // 统计只是提示，任何异常都不能让迁移失败 —— 字段已经放宽，这一步不修任何数据。
        try {
            $broken = 0;

            $rows = DB::table('plugin_xseed_sites')
                ->where('secret', 'like', 'enc:%')
                ->get(['id', 'secret']);

            foreach ($rows as $row) {
                try {
                    Crypt::decryptString(substr((string) $row->secret, 4));
                } catch (\Throwable $throwable) {
                    $broken++;
                }
            }

            if ($broken > 0) {
                echo "  [xseed] {$broken} 条站点密钥解不开（多半是 0.2.6/0.2.7 被字段宽度截断），请到后台重填一次。\n";
            }
        } catch (\Throwable $throwable) {
            // 忽略
        }
    }

    public function down(): void
    {
        Schema::table('plugin_xseed_sites', function (Blueprint $table) {
            $table->string('secret', 255)->default('')->change();
        });
    }
};
