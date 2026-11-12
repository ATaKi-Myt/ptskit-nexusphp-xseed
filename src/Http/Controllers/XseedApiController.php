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
    /** 对端拿一批 pieces_hash 来问「这些在我这儿是哪个种子」 */
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

        $json = json_decode($raw, true);
        $pieces = is_array($json) ? ($json['pieces'] ?? []) : [];

        // 0.1.x 的老对端发的还是 {"hashes": [...]}，明确回一条错误，
        // 免得对端只看到「命中 0」以为是没同源种子。
        if (empty($pieces) && !empty($json['hashes'] ?? [])) {
            return response()->json([
                'ret' => -1,
                'msg' => 'peer expects "pieces", please upgrade xseed to 0.2+ on the other side',
            ], 400);
        }

        if (!is_array($pieces) || empty($pieces)) {
            return response()->json(['ret' => 0, 'msg' => 'OK', 'data' => []]);
        }

        $wanted = [];

        foreach (array_slice(array_values(array_unique($pieces)), 0, XseedRepository::CHUNK_SIZE) as $hash) {
            $hash = strtolower(trim((string) $hash));
            if (preg_match('/^[0-9a-f]{40}$/', $hash)) {
                $wanted[] = $hash;
            }
        }

        if (empty($wanted)) {
            return response()->json(['ret' => 0, 'msg' => 'OK', 'data' => []]);
        }

        $rows = NexusDB::table('torrents')
            ->whereIn('pieces_hash', $wanted)
            ->where('pieces_hash', '<>', '')
            ->select('id', 'pieces_hash')
            ->get();

        $data = [];

        foreach ($rows as $row) {
            $data[(string) $row->pieces_hash] = (string) $row->id;
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
        $siteUrl = strtolower(rtrim(trim((string) $request->header('X-Xseed-Site', '')), '/'));

        if ($siteUrl === '') {
            return null;
        }

        // 只比 host + 路径，忽略协议：站点可能在 CDN / 反代后面，对端 CLI 侧
        // 推断出的协议不一定是 https，严格比对会把合法请求挡成 unauthorized。
        $bare = preg_replace('#^https?://#', '', $siteUrl);

        if ($bare === '') {
            return null;
        }

        return XseedSite::query()
            ->where('enabled', 1)
            ->whereRaw(
                "LOWER(TRIM(TRAILING '/' FROM REPLACE(REPLACE(base_url, 'https://', ''), 'http://', ''))) = ?",
                [$bare]
            )
            ->first();
    }
}
