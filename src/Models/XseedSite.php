<?php

namespace NexusPlugin\Xseed\Models;

use App\Models\NexusModel;
use Illuminate\Support\Facades\Crypt;
use Nexus\Database\NexusDB;

/**
 * 友站名单
 *
 * enabled=1 才会参与同步。secret 与对端站点配置里填的必须是同一个值。
 * interval_minutes 决定这个站多久同步一次（默认 30 分钟）。
 *
 * secret 以 `enc:` 前缀加密存库（v0.2.6 起）：拖库拿到的只是密文，
 * 不能直接拿去冒充本站调对端接口。老明文数据无需手工处理，
 * 读的时候没前缀就当明文用，下次在后台保存时自动加密。
 */
class XseedSite extends NexusModel
{
    /** 库里 secret 的密文前缀；没有这个前缀的按明文兼容 */
    const SECRET_PREFIX = 'enc:';

    /**
     * 密钥解密失败标记
     *
     * getSecretAttribute() 解不开时只能返回空串，从外部看和「没填过」完全一样，
     * 同步侧就只会报一句 secret is empty，查起来要命。这里留个标记，
     * 让上层能区分「没填」和「填了但解不开（APP_KEY 换过 / 密文被截断）」。
     */
    public bool $secretDecryptFailed = false;

    protected $table = 'plugin_xseed_sites';

    protected $fillable = [
        'name', 'base_url', 'secret', 'icon', 'enabled', 'interval_minutes',
        'last_sync_at', 'last_sync_total', 'last_sync_hit', 'last_sync_result', 'last_sync_cursor',
    ];

    public function getSecretAttribute($value): string
    {
        $this->secretDecryptFailed = false;

        if (!is_string($value) || $value === '') {
            return '';
        }

        if (strncmp($value, self::SECRET_PREFIX, 4) !== 0) {
            return $value;      // 老数据，明文
        }

        try {
            return Crypt::decryptString(substr($value, 4));
        } catch (\Throwable $throwable) {
            // 两种可能：换过 APP_KEY，或者密文被字段宽度截断过（0.2.6/0.2.7 的 bug）。
            // 无论哪种都只能重填，这里标记一下让同步侧报出准确原因。
            $this->secretDecryptFailed = true;

            if (function_exists('do_log')) {
                do_log('[xseed] decrypt secret failed, re-fill it in admin: ' . $throwable->getMessage(), 'error');
            }

            return '';
        }
    }

    public function setSecretAttribute($value): void
    {
        $value = (string) $value;

        $this->attributes['secret'] = $value === ''
            ? ''
            : self::SECRET_PREFIX . Crypt::encryptString($value);
    }

    /** 未命中 = 本轮问过、对方没有的（不单独存列，派生出来） */
    public function getLastSyncMissAttribute(): int
    {
        return max(0, (int) $this->last_sync_total - (int) $this->last_sync_hit);
    }

    /**
     * 累计命中 / 累计未命中
     *
     * 表格里显示的是「到现在为止一共命中多少」，不是上一轮的。
     * 直接数缓存表（每问过一条就落一行，命中记 peer_torrent_id>0），
     * 所以重建缓存或清表后数字会自然归零，不需要自己维护累加器。
     */
    private function peerTotals(): array
    {
        static $cache = [];

        $siteId = (int) $this->getKey();

        if (!isset($cache[$siteId])) {
            $row = NexusDB::table('plugin_xseed_peers')
                ->where('site_id', $siteId)
                ->selectRaw('count(*) as total, sum(case when peer_torrent_id > 0 then 1 else 0 end) as hit')
                ->first();

            $total = (int) ($row->total ?? 0);
            $hit = (int) ($row->hit ?? 0);

            $cache[$siteId] = [$hit, max(0, $total - $hit)];
        }

        return $cache[$siteId];
    }

    /** 累计命中数 */
    public function getHitTotalAttribute(): int
    {
        return $this->peerTotals()[0];
    }

    /** 累计未命中数 */
    public function getMissTotalAttribute(): int
    {
        return $this->peerTotals()[1];
    }

    /** 上一次同步是否报错（last_sync_result 以 ERR 开头） */
    public function lastSyncFailed(): bool
    {
        return strncmp((string) $this->last_sync_result, 'ERR', 3) === 0;
    }

    /** 到点了吗？last_sync_at = 0 表示刚绑定、还没同步过，立刻算到期 */
    public function isDue(): bool
    {
        if ((int) $this->last_sync_at === 0) {
            return true;
        }

        $interval = max(1, (int) ($this->interval_minutes ?: 30)) * 60;

        return (time() - (int) $this->last_sync_at) >= $interval;
    }

    /**
     * 是不是明显停摆了（超过 3 倍间隔没同步）
     *
     * 后台把「最后同步时间」标红。之前这个字段只是慢慢变旧，没人会发现
     * 同步被残留的 withoutOverlapping 锁挡住了一整天。
     */
    public function isStalled(): bool
    {
        if ((int) $this->last_sync_at === 0) {
            return false;   // 刚绑定还没跑过，不算异常
        }

        $interval = max(1, (int) ($this->interval_minutes ?: 30)) * 60;

        return (time() - (int) $this->last_sync_at) > $interval * 3;
    }

    /** 取站点入口图标，没配就用 favicon */
    public function iconUrl(): string
    {
        if (!empty($this->icon)) {
            return $this->icon;
        }

        return rtrim($this->base_url, '/') . '/favicon.ico';
    }

    /** 拼到对方种子详情页的 URL */
    public function torrentUrl(int $peerTorrentId): string
    {
        return sprintf('%s/details.php?id=%d', rtrim($this->base_url, '/'), $peerTorrentId);
    }
}
