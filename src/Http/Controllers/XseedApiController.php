<?php

namespace NexusPlugin\Xseed\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            $hash = (string) $row->pieces_hash;
            $id = (int) $row->id;

            // 同一套片源可以发好几次（同 hash 多个 torrent_id），
            // 直接覆盖会让结果随查询顺序变化，取最小的那个保证每次一致。
            if (!isset($data[$hash]) || $id < (int) $data[$hash]) {
                $data[$hash] = (string) $id;
            }
        }

        return response()->json(['ret' => 0, 'msg' => 'OK', 'data' => $data]);
    }

    /** 本站列表页用：这批种子各有多少个友站有（列表页内联后的兜底路径） */
    public function counts(Request $request)
    {
        if (!$this->allowCounts($request)) {
            return response()->json(['ret' => -1, 'msg' => 'too many requests'], 429);
        }

        $raw = (string) $request->query('ids', '');
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));

        if (empty($ids)) {
            return response()->json(['ret' => 0, 'msg' => 'OK', 'data' => (object) []]);
        }

        $ids = array_slice($ids, 0, XseedRepository::LIST_COUNT_LIMIT);

        // 翻页 / 刷新时同一批 id 会重复问，缓存 60 秒足够，省掉重复的聚合查询。
        // 缓存驱动出问题也不能影响列表页，所以失败就直算。
        try {
            $data = Cache::remember(
                'xseed:counts:' . md5(implode(',', $ids)),
                60,
                fn () => (new XseedRepository())->countsByIds($ids)
            );
        } catch (\Throwable $throwable) {
            $data = (new XseedRepository())->countsByIds($ids);
        }

        // 强制转对象：torrent_id 恰好是连续整数时，json_encode 会把数组编成列表，
        // 前端按 id 取值就会错位。
        return response()->json(['ret' => 0, 'msg' => 'OK', 'data' => (object) $data]);
    }

    /**
     * 按 IP 做分钟级限流
     *
     * 这个接口不需要签名（列表页会自己调），所以谁都能刷；不同 id 组合还能绕过
     * 60 秒结果缓存，等于每次请求一条聚合查询。正常用户一页只调一次，
     * 放 300 次/分钟足够，越是脚本越容易被挡。
     *
     * 按分钟分桶，key 自带 TTL，不用维护窗口状态。
     */
    private function allowCounts(Request $request): bool
    {
        $key = 'xseed:rl:' . md5((string) $request->ip()) . ':' . intdiv(time(), 60);

        try {
            $count = (int) Cache::increment($key);

            if ($count === 1) {
                Cache::put($key, 1, 120);
            }

            return $count <= 300;
        } catch (\Throwable $throwable) {
            return true;    // 缓存不可用就别拦，列表页优先
        }
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
