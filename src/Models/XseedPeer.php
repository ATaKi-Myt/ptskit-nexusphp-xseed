<?php

namespace NexusPlugin\Xseed\Models;

use App\Models\NexusModel;

/**
 * 本站种子 <-> 友站种子 的交集缓存
 *
 * peer_torrent_id = 0 表示「这个 hash 在这个站查过了、对方没有」，
 * 这样同步时的差集查询天然排除掉，不会每轮重复问。
 *
 * ★ 这张表整体是可再生缓存：清空后跑一次 xseed:sync 即可恢复。
 */
class XseedPeer extends NexusModel
{
    protected $table = 'plugin_xseed_peers';

    protected $fillable = ['torrent_id', 'site_id', 'info_hash', 'peer_torrent_id', 'checked_at'];
}
