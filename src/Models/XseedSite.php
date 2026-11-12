<?php

namespace NexusPlugin\Xseed\Models;

use App\Models\NexusModel;

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
        'last_sync_at', 'last_sync_result', 'last_sync_cursor',
    ];

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
