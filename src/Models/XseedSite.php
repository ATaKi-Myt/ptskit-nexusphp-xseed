<?php

namespace NexusPlugin\Xseed\Models;

use App\Models\NexusModel;
use Nexus\Database\NexusDB;

/**
 * 友站名单
 *
 * enabled=1 才会参与同步。secret 与对端站点配置里填的必须是同一个值。
 * interval_minutes 决定这个站多久同步一次（默认 30 分钟）。
 */
class XseedSite extends NexusModel
{
    protected $table = 'plugin_xseed_sites';

    protected $fillable = [
        'name', 'base_url', 'secret', 'icon', 'enabled', 'interval_minutes',
        'last_sync_at', 'last_sync_total', 'last_sync_hit', 'last_sync_result', 'last_sync_cursor',
    ];

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
