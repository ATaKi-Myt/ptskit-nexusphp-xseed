<?php

namespace NexusPlugin\Xseed\Http\Controllers;

use Illuminate\Http\Request;
use Nexus\Database\NexusDB;
use NexusPlugin\Xseed\Models\XseedSite;
use NexusPlugin\Xseed\XseedRepository;

/**
 * 对端调用的两个端点
 *
 * 都不走 web 中间件组（routes/web.php 里没加 middleware），
 * 所以既没有 CSRF 也不依赖 session —— 这正是跨站调用需要的。
 */
class XseedApiController
{
    /** 对端拿一批 hash 来问「这些在我这儿是哪个种子」 */
    public function lookup(Request $request)
    {
        $site = $this->verifySite($request);

        if ($site === null) {
            return response()->json(['ret' => -1, 'msg' => 'unauthorized'], 401);
        }

        $raw = $request->getContent();          // 验签必须用原始 body
        $ts = (string) $request->header('X-Xseed-Ts', '');

        if (!ctype_digit($ts) || abs(time() - (int) $ts) > XseedRepository::SIGN_TTL) {
            return response()->json(['ret' => -1, 'msg' => 'expired'], 401);
        }

        $expected = hash_hmac('sha256', $ts . $raw, (string) $site->secret);

        if (!hash_equals($expected, (string) $request->header('X-Xseed-Sign', ''))) {
            return response()->json(['ret' => -1, 'msg' => 'bad signature'], 401);
        }

        $hashes = json_decode($raw, true);
        $hashes = is_array($hashes) ? ($hashes['hashes'] ?? []) : [];

        if (!is_array($hashes) || empty($hashes)) {
            return response()->json(['ret' => 0, 'msg' => 'OK', 'data' => []]);
        }

        $binaries = [];
        $map = [];

        foreach (array_slice(array_values(array_unique($hashes)), 0, XseedRepository::CHUNK_SIZE) as $hash) {
            $hash = strtolower(trim((string) $hash));
            if (preg_match('/^[0-9a-f]{40}$/', $hash)) {
                $bin = hex2bin($hash);
                $binaries[] = $bin;
                $map[$bin] = $hash;
            }
        }

        if (empty($binaries)) {
            return response()->json(['ret' => 0, 'msg' => 'OK', 'data' => []]);
        }

        $rows = NexusDB::table('torrents')
            ->whereIn('info_hash', $binaries)
            ->select('id', 'info_hash')
            ->get();

        $data = [];

        foreach ($rows as $row) {
            $hex = $map[$row->info_hash] ?? bin2hex($row->info_hash);
            $data[$hex] = (string) $row->id;
        }

        return response()->json(['ret' => 0, 'msg' => 'OK', 'data' => $data]);
    }

    /** 本站列表页用：这批种子各有多少个友站有 */
    public function counts(Request $request)
    {
        $raw = (string) $request->query('ids', '');
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));

        if (empty($ids)) {
            return response()->json(['ret' => 0, 'msg' => 'OK', 'data' => []]);
        }

        $ids = array_slice($ids, 0, XseedRepository::LIST_COUNT_LIMIT);

        $rows = NexusDB::table('plugin_xseed_peers as p')
            ->join('plugin_xseed_sites as s', 's.id', '=', 'p.site_id')
            ->whereIn('p.torrent_id', $ids)
            ->where('p.peer_torrent_id', '>', 0)
            ->where('s.enabled', 1)
            ->groupBy('p.torrent_id')
            ->select('p.torrent_id', NexusDB::raw('count(distinct p.site_id) as c'))
            ->get();

        $data = [];

        foreach ($rows as $row) {
            $data[(int) $row->torrent_id] = (int) $row->c;
        }

        return response()->json(['ret' => 0, 'msg' => 'OK', 'data' => $data]);
    }

    /** 按 X-Xseed-Site 头找到对端站点，找不到 = 未授权 */
    private function verifySite(Request $request): ?XseedSite
    {
        $siteUrl = rtrim((string) $request->header('X-Xseed-Site', ''), '/');

        if ($siteUrl === '') {
            return null;
        }

        return XseedSite::query()
            ->where('base_url', $siteUrl)
            ->where('enabled', 1)
            ->first();
    }
}
