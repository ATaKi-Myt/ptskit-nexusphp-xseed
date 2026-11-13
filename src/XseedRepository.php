<?php

namespace NexusPlugin\Xseed;

use Illuminate\Support\Facades\Http;
use Nexus\Database\NexusDB;
use Nexus\Nexus;
use Nexus\Plugin\BasePlugin;
use NexusPlugin\Xseed\Models\XseedSite;

/**
 * 辅种（cross-seed）映射
 *
 * 核心：跨站靠 pieces_hash 对齐（= sha1(info.pieces)，只跟片数据和分片大小有关）。
 *      不能用 info_hash —— NexusPHP 发种时会往 info 字典写
 *      source = "[本站URL] 本站名"，所以同一套片源在两站生成的 info_hash 必然不同。
 * 数据只是缓存，随时可清空重建，所以「现在本地、后期中心服务器」不需要改表结构。
 *
 * 三道闸门：
 *   ① Laravel 调度每分钟叫一次 xseed:sync，命令自己按每条友站的间隔判断到期才同步
 *   ② 前台详情页 torrent_detail_before_desc 钩子输出一行站点图标
 *   ③ 前台列表页两个钩子：torrent_list 先算好计数写进行数据，
 *      torrent_title_suffix 再把计数埋进该行 HTML，前端脚本同步读 DOM 插列
 *
 * 同步是「增量 + 滚动复核」：
 *   - 新种子（id 大于游标、还没问过）优先，一直问到没有为止
 *   - 扫完后再花少量配额复核老命中，对端删了种就把本地记录清掉
 *   - 本站删种留下的孤儿行，每轮同步结束时清一次
 */
class XseedRepository extends BasePlugin
{
    const ID = 'xseed';

    const VERSION = '0.2.6';

    const COMPATIBLE_NP_VERSION = '1.9.0';

    /** 一次请求带多少 pieces_hash 去问对端 */
    const CHUNK_SIZE = 1000;

    /** 定时任务一轮最多问多少批（硬上限，真正的刹车是下面的时间预算） */
    const MAX_CHUNKS_PER_RUN = 200;

    /** 一轮同步的时间预算（秒）。调度每分钟一次且 withoutOverlapping，留出 10 秒余量 */
    const RUN_BUDGET_SECONDS = 50;

    /** 整轮（所有友站加起来）的上限，防止站点多时单轮拖过一分钟 */
    const TOTAL_BUDGET_SECONDS = 55;

    /**
     * 每轮做多少批复核（每批 1000 条），可用 XSEED_REVIEW_CHUNKS 覆盖
     *
     * 复核不是按时间阈值触发的，而是「谁最久没核对就先查谁」，每轮固定几批。
     * 所以全量轮转周期 ≈ 命中数 ÷ (本值 × 每天轮数)，例如 6 万条命中、30 分钟一轮、
     * 本值 4 ⇒ 约 7.5 小时把所有命中核对一遍；调到 10 就是 3 小时。
     */
    const REVIEW_CHUNKS_PER_RUN = 4;

    /** 「核对过但当时没命中」的记录，隔多少天复查一次（防误判后永久卡 0） */
    const MISS_RECHECK_DAYS = 30;

    /** 列表页一列最多查多少个种子 */
    const LIST_COUNT_LIMIT = 200;

    /** 签名有效期（秒） */
    const SIGN_TTL = 300;

    public function getId(): string
    {
        return self::ID;
    }

    public function install()
    {
        $this->runMigrations($this->getMigrationPath());
    }

    public function uninstall()
    {
        $this->runMigrations($this->getMigrationPath(), true);
    }

    public function boot()
    {
        Nexus::addTranslationNamespace(dirname(__DIR__) . '/resources/lang', self::ID);

        // 后台（Laravel）不需要挂前台页面钩子
        if (!defined('IN_NEXUS') || !IN_NEXUS) {
            return;
        }

        // 详情页：主表格内、基本信息下方的通用钩子，参数是 ($torrentId, $uid)
        add_action('torrent_detail_before_desc', [$this, 'renderDetailRow'], 10, 2);

        // 列表页：torrent_list 在 torrenttable() 之前触发，正好能一次性把整页的计数写进行数据；
        // torrenttable() 没有列级 hook，列头仍然只能由 JS 补，但数据不用再异步拉了。
        add_filter('torrent_list', [$this, 'attachListCounts'], 10, 4);
        add_filter('torrent_title_suffix', [$this, 'injectListColumnJs'], 10, 2);
    }

    // ---------------------------------------------------------------- 前台渲染

    /** 详情页：一行「辅种站点 = 图标+站名」 */
    public function renderDetailRow($torrentId, $userId = null): void
    {
        $torrentId = (int) $torrentId;

        if ($torrentId <= 0 || empty($GLOBALS['CURUSER']['id'])) {
            return;
        }

        // 这里出任何问题都不能让详情页 500 —— 辅种只是个装饰行
        try {
            $rows = NexusDB::table('plugin_xseed_peers as p')
                ->join('plugin_xseed_sites as s', 's.id', '=', 'p.site_id')
                ->where('p.torrent_id', $torrentId)
                ->where('p.peer_torrent_id', '>', 0)
                ->where('s.enabled', 1)
                ->orderBy('s.id')
                ->select('s.name', 's.base_url', 's.icon', 'p.peer_torrent_id')
                ->get();
        } catch (\Throwable $throwable) {
            return;
        }

        if ($rows->isEmpty()) {
            return;
        }

        $items = [];

        foreach ($rows as $row) {
            $baseUrl = rtrim((string) $row->base_url, '/');

            // 只认 http/https：Filament 的 url() 规则放行 javascript:// 之类，
            // 真填进来会变成前台存储型 XSS。
            if (!preg_match('#^https?://#i', $baseUrl)) {
                continue;
            }

            $icon = !empty($row->icon) ? $row->icon : $baseUrl . '/favicon.ico';

            if (!preg_match('#^https?://#i', (string) $icon)) {
                $icon = $baseUrl . '/favicon.ico';
            }

            $url = sprintf('%s/details.php?id=%d', $baseUrl, (int) $row->peer_torrent_id);
            $name = htmlspecialchars((string) $row->name, ENT_QUOTES);

            $items[] = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" title="%s">'
                . '<img src="%s" width="16" height="16" style="vertical-align:middle;margin-right:3px" onerror="this.style.display=\'none\'" />'
                . '%s</a>',
                htmlspecialchars($url, ENT_QUOTES),
                $name,
                htmlspecialchars((string) $icon, ENT_QUOTES),
                $name
            );
        }

        if ($items === []) {
            return;
        }

        // tr() 第三个参数 = noesc，1 表示内容已是 HTML，不要再转义
        tr(self::t('row_label'), implode('&nbsp;&nbsp;', $items), 1);
    }

    /**
     * 统一翻译入口
     *
     * 站点语言在 zh_CN / zh_TW / en 之内就跟随站点；不在这三种之内（或当前语言缺键）
     * 一律回落到 zh_CN —— 即默认中文，不会出现英文混排。
     */
    public static function t(string $key, array $replace = []): string
    {
        $full = 'xseed::xseed.' . $key;

        $line = nexus_trans($full, $replace, self::locale());

        return $line === $full ? nexus_trans($full, $replace, 'zh_CN') : $line;
    }

    /** 当前应使用的插件语言，只可能是这三种之一 */
    private static function locale(): string
    {
        static $locale = null;

        if ($locale === null) {
            $current = function_exists('get_langfolder_cookie') ? get_langfolder_cookie(true) : '';
            $locale = in_array($current, ['zh_CN', 'zh_TW', 'en'], true) ? $current : 'zh_CN';
        }

        return $locale;
    }

    /**
     * 列表页：一次性把整页种子的友站数写进 $row['xseed_count']
     *
     * 挂在 torrent_list 上（torrenttable() 之前），所以行渲染时数据一定已就绪。
     * 从这里拿不到列头，列头仍由 injectListColumnJs 注入的脚本补 —— 但数据不用再发 XHR，
     * 少一次往返，也消掉了「先出页面、列空着、几百毫秒后填上」的跳变。
     */
    public function attachListCounts($rows, $page = 1, $sectionType = 0, $search = '')
    {
        if (!is_array($rows) || $rows === []) {
            return $rows;
        }

        $ids = [];

        foreach ($rows as $row) {
            $id = is_array($row) ? (int) ($row['id'] ?? 0) : (int) ($row->id ?? 0);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return $rows;
        }

        // 辅种列只是附加信息：表缺失、DB 抖动、锁超时都不该把种子列表页打成 500
        try {
            $counts = $this->countsByIds($ids);
        } catch (\Throwable $throwable) {
            if (function_exists('do_log')) {
                do_log('[xseed] attach list counts failed: ' . $throwable->getMessage(), 'error');
            }

            return $rows;
        }

        foreach ($rows as $key => $row) {
            $id = is_array($row) ? (int) ($row['id'] ?? 0) : (int) ($row->id ?? 0);
            $count = $counts[$id] ?? 0;

            if (is_array($row)) {
                $rows[$key]['xseed_count'] = $count;
            } elseif (is_object($row)) {
                $row->xseed_count = $count;
            }
        }

        return $rows;
    }

    /** [torrent_id => 有同源的启用友站数] */
    public function countsByIds(array $ids): array
    {
        $ids = array_slice(
            array_values(array_unique(array_filter(array_map('intval', $ids)))),
            0,
            self::LIST_COUNT_LIMIT
        );

        if ($ids === []) {
            return [];
        }

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

        return $data;
    }

    /**
     * 列表页注入 JS + 给每行埋一个隐藏计数
     *
     * 埋点放在 title suffix 里（这是唯一能拿到 $row 的行内钩子），
     * 前端脚本读 <span class="xseed-n" data-c="3">，不再 XHR。
     * 读不到（某些不走 torrent_list 的页面）才回落到原来的接口。
     */
    public function injectListColumnJs($titleSuffix, $row = null)
    {
        static $injected = false;

        if (empty($GLOBALS['CURUSER']['id'])) {
            return $titleSuffix;
        }

        if (!$injected) {
            $injected = true;
            Nexus::js($this->listColumnJs(), 'footer', false);
        }

        $count = is_array($row) ? ($row['xseed_count'] ?? null) : null;

        if ($count !== null) {
            $titleSuffix .= sprintf(
                '<span class="xseed-n" data-c="%d" style="display:none"></span>',
                (int) $count
            );
        }

        return $titleSuffix;
    }

    private function listColumnJs(): string
    {
        $api = getSchemeAndHttpHost(true) . '/plugin-xseed/api/counts';
        $label = self::t('col_label');
        $tip = self::t('col_tip');

        $js = <<<'JS'
(function () {
  if (window.__xseedInit) { return; }
  window.__xseedInit = true;

  var tables = document.querySelectorAll('table.torrents');
  var table = null, i;
  for (i = 0; i < tables.length; i++) {
    if (tables[i].querySelector('a[href*="details.php?id="]')) { table = tables[i]; break; }
  }
  if (!table) { return; }

  var head = table.querySelector('tr');
  if (!head || !head.querySelector('td.colhead')) { return; }

  var rows = table.querySelectorAll('tr'), ids = [], map = {}, cells = [], inline = 0;
  for (i = 1; i < rows.length; i++) {
    var tr = rows[i];
    if (tr.closest('table') !== table) { continue; }
    if (tr.querySelector('td.colhead')) { continue; }
    var a = tr.querySelector('a[href*="details.php?id="]');
    if (!a) { continue; }
    var m = /details\.php\?id=(\d+)/.exec(a.getAttribute('href'));
    if (!m) { continue; }
    var id = m[1];
    if (!map[id]) { map[id] = []; ids.push(id); }
    map[id].push(tr);
    cells.push(tr);
    if (tr.querySelector('span.xseed-n')) { inline++; }
  }
  if (!ids.length || ids.length > 200) { return; }

  var th = document.createElement('td');
  th.className = 'colhead';
  th.style.textAlign = 'center';
  th.title = '__TIP__';
  th.textContent = '__LABEL__';
  head.appendChild(th);

  // 先把空单元格全部插好：列宽第一帧就定下来，后面只填内容，不再重排
  for (i = 0; i < cells.length; i++) {
    var td = document.createElement('td');
    td.className = 'rowfollow';
    td.style.textAlign = 'center';
    td.innerHTML = '<span style="color:#999">-</span>';
    cells[i].appendChild(td);
    cells[i].__xseedTd = td;
  }

  function fill(data) {
    for (var k = 0; k < ids.length; k++) {
      var id = ids[k], c = parseInt(data[id] || 0, 10), tds = map[id];
      for (var j = 0; j < tds.length; j++) {
        var cell = tds[j].__xseedTd;
        if (!cell || !(c > 0)) { continue; }
        cell.innerHTML = '';
        var link = document.createElement('a');
        link.href = 'details.php?id=' + id;
        link.title = '__TIP__';
        link.textContent = c;
        cell.appendChild(link);
      }
    }
  }

  // 服务端已经把计数埋进每一行了，直接读，同步完成，不会看到闪动
  if (inline === cells.length) {
    var data0 = {};
    for (i = 0; i < ids.length; i++) {
      var span = map[ids[i]][0].querySelector('span.xseed-n');
      data0[ids[i]] = span ? span.getAttribute('data-c') : 0;
    }
    fill(data0);
    return;
  }

  // 兜底：这个列表没走 torrent_list（收藏页等），还按老办法问一下接口
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '__API__?ids=' + ids.join(','), true);
  xhr.onload = function () {
    var data = {};
    try { data = (JSON.parse(xhr.responseText) || {}).data || {}; } catch (e) { data = {}; }
    fill(data);
  };
  xhr.send();
})();
JS;

        return str_replace(['__API__', '__LABEL__', '__TIP__'], [$api, $label, $tip], $js);
    }

    // ---------------------------------------------------------------- 同步

    /**
     * 同步一个友站
     *
     * 一轮里的优先级：
     *   ① 新种子（id 大于游标、本站还没问过）—— 首扫时就是它，一直问到没有为止
     *   ② 同 id 但 hash 变了的（换过种子文件）—— 极少，每轮最多一批
     *   ③ 老命中复核 —— 每轮固定少量配额，用来发现友站删种
     *
     * 跑多久由 $budgetSeconds 控制，不是「固定几批」，所以 6 万条种子一轮就能推完，
     * 推完之后的每一轮只处理新增 + 少量复核，不会重复扫全量。
     *
     * @return array{total:int,hit:int,chunks:int,review:int,error:string}
     */
    public function pullSite(
        XseedSite $site,
        int $maxChunks = self::MAX_CHUNKS_PER_RUN,
        int $budgetSeconds = self::RUN_BUDGET_SECONDS,
        ?int $reviewChunks = null
    ): array {
        $result = ['total' => 0, 'hit' => 0, 'chunks' => 0, 'review' => 0, 'error' => ''];

        if (empty($site->secret) || empty($site->base_url)) {
            $result['error'] = 'secret or base_url is empty';
            return $result;
        }

        if ($this->selfBaseUrl() === '') {
            $result['error'] = 'self base url is empty, set basic.BASEURL in site settings';
            return $result;
        }

        $schemaProblem = $this->schemaProblem();

        if ($schemaProblem !== null) {
            $result['error'] = $schemaProblem;
            return $result;
        }

        $startedAt = microtime(true);
        $reviewSince = time();
        $siteId = (int) $site->id;
        $cursor = $this->syncCursor($site);
        $reviewLeft = max(0, $reviewChunks ?? $this->reviewChunks());
        $changedChecked = false;
        $slowDone = false;
        $lastFreshId = 0;

        while ($result['chunks'] < $maxChunks) {
            if (microtime(true) - $startedAt >= $budgetSeconds) {
                break;      // 到点了，剩下的下一分钟接着跑（游标已落库，不会重扫）
            }

            $isChanged = false;
            $rows = $this->nextFresh($siteId, $cursor, self::CHUNK_SIZE);

            if ($rows->isEmpty()) {
                // 快路径到头了：游标推到表尾，之后的新种子一定在它后面
                $maxId = (int) NexusDB::table('torrents')->max('id');

                if ($maxId > $cursor) {
                    $cursor = $maxId;
                    $this->saveCursor($siteId, $cursor);
                }

                // 慢路径：再从头兜一遍。兜的是「当初 pieces_hash 为空、后来被
                // torrent:load_pieces_hash 补齐」的种子 —— 它们 id 小于游标，
                // 靠快路径永远看不到。全表 join 一次，几万条也就是百毫秒级。
                if (!$slowDone) {
                    $rows = $this->nextFresh($siteId, 0, self::CHUNK_SIZE);
                    $slowDone = $rows->isEmpty();
                }
            }

            // 实在没有新东西了，顺手检查一次「同 id 换了 hash」的（种子文件被替换）
            if ($rows->isEmpty() && !$changedChecked) {
                $changedChecked = true;
                $rows = $this->nextChanged($siteId, self::CHUNK_SIZE);
                $isChanged = $rows->isNotEmpty();
            }

            if ($rows->isEmpty()) {
                // 没有新东西了，把剩余配额用在复核上
                if ($reviewLeft <= 0) {
                    break;
                }

                $reviewLeft--;
                $hashes = $this->nextStale($siteId, self::CHUNK_SIZE, $reviewSince);

                if ($hashes === []) {
                    break;
                }

                try {
                    $this->reviewBatch($site, $hashes, $result);
                } catch (\Throwable $throwable) {
                    $result['error'] = $throwable->getMessage();
                    break;
                }

                $result['chunks']++;
                continue;
            }

            // 同一批被连续取到 = upsert 没写进去（典型原因：迁移没跑，唯一键还是
            // (site_id, pieces_hash)），继续跑只会空转烧对端流量，直接停下来报错。
            $firstId = (int) $rows->first()->id;

            if (!$isChanged && $firstId === $lastFreshId) {
                $result['error'] = 'stuck on same batch, run: php artisan plugin install ptskit/nexusphp-xseed';
                break;
            }

            $lastFreshId = $firstId;

            $pieces = [];
            $maxId = 0;

            foreach ($rows as $row) {
                $pieces[] = $row->pieces_hash;
                $maxId = max($maxId, (int) $row->id);
            }

            try {
                $mapped = $this->remoteLookup($site, $pieces);
            } catch (\Throwable $throwable) {
                $result['error'] = $throwable->getMessage();
                break;
            }

            $now = time();
            $insert = [];

            foreach ($rows as $row) {
                $hash = (string) $row->pieces_hash;
                $peerId = isset($mapped[$hash]) ? (int) $mapped[$hash] : 0;

                $insert[] = [
                    'torrent_id' => (int) $row->id,
                    'site_id' => $siteId,
                    'pieces_hash' => $hash,
                    'peer_torrent_id' => $peerId,
                    'checked_at' => $now,
                ];

                if ($peerId > 0) {
                    $result['hit']++;
                }
            }

            NexusDB::table('plugin_xseed_peers')->upsert(
                $insert,
                ['site_id', 'pieces_hash', 'torrent_id'],
                ['peer_torrent_id', 'checked_at']
            );

            $result['total'] += count($insert);
            $result['chunks']++;

            // 换过种子的：旧 hash 的行已经没用了，立刻清掉，
            // 否则 nextChanged 下一轮还会把同一批捞出来重复问。
            if ($isChanged) {
                $this->purgeOrphans();
            }

            // 游标推进：新种子的 id 一定比它大，所以之后每轮只需要看新增的部分
            if ($maxId > $cursor) {
                $cursor = $maxId;
                $this->saveCursor($siteId, $cursor);
            }
        }

        return $result;
    }

    /**
     * 本站在这个友站上「问到哪个 id 了」
     *
     * 种子表被重建 / 从备份恢复过的话游标会失效（比现存最大 id 还大），
     * 这时归零重扫一遍，免得永远看不到「新」种子。
     */
    private function syncCursor(XseedSite $site): int
    {
        $cursor = (int) $site->last_sync_cursor;

        if ($cursor <= 0) {
            return 0;
        }

        $maxId = (int) NexusDB::table('torrents')->max('id');

        if ($maxId < $cursor) {
            $this->saveCursor((int) $site->id, 0);
            return 0;
        }

        return $cursor;
    }

    private function saveCursor(int $siteId, int $cursor): void
    {
        NexusDB::table('plugin_xseed_sites')
            ->where('id', $siteId)
            ->update(['last_sync_cursor' => (string) $cursor]);
    }

    /**
     * 本站「这个站还没问过」的种子，按 id 游标往后取
     *
     * 用 pieces_hash 对齐（不是 info_hash）：NexusPHP 发种时会往 info 字典里写
     * source = "[本站URL] 本站名"，两站的 info_hash 必然不同，只有 pieces_hash 跨站稳定。
     * pieces_hash 为空的老种子跳过 —— 主程序每天 01:00 的 torrent:load_pieces_hash 会补。
     */
    private function nextFresh(int $siteId, int $cursor, int $limit)
    {
        return NexusDB::table('torrents as t')
            ->leftJoin('plugin_xseed_peers as p', function ($join) use ($siteId) {
                $join->on('p.pieces_hash', '=', 't.pieces_hash')
                    ->on('p.torrent_id', '=', 't.id')
                    ->where('p.site_id', '=', $siteId);
            })
            ->whereNull('p.id')
            ->where('t.id', '>', $cursor)
            ->where('t.pieces_hash', '<>', '')
            ->orderBy('t.id')
            ->limit($limit)
            ->select('t.id', 't.pieces_hash')
            ->get();
    }

    /**
     * 同 id 但 pieces_hash 变了的种子（种子文件被替换过）
     *
     * 正常发种流程是「删了重发」，id 会变、走 nextFresh 就够了；
     * 这里兜住「原地换种子」的情况。换完留下旧 hash 的行由 purgeOrphans 清掉。
     */
    private function nextChanged(int $siteId, int $limit)
    {
        return NexusDB::table('torrents as t')
            ->join('plugin_xseed_peers as p', function ($join) use ($siteId) {
                $join->on('p.torrent_id', '=', 't.id')
                    ->where('p.site_id', '=', $siteId);
            })
            ->whereColumn('p.pieces_hash', '<>', 't.pieces_hash')
            ->where('t.pieces_hash', '<>', '')
            ->orderBy('t.id')
            ->limit($limit)
            ->select('t.id', 't.pieces_hash')
            ->distinct()
            ->get();
    }

    /**
     * 该复核的 hash：命中过的记录里，最久没核对的那一批
     *
     * $before 是本轮开始时间：本轮查过的记录会被 touch 到 >= $before，
     * 这样同一轮不会把同一条反复捞出来（命中总数不足一批时尤其明显）。
     */
    private function nextStale(int $siteId, int $limit, int $before = 0): array
    {
        // 不用 distinct：MySQL 8 的 ONLY_FULL_GROUP_BY 下
        // `select distinct x ... order by y` 会直接报 3065，重复的在下面去重即可。
        //
        // 不设时间阈值：靠 order by checked_at 天然轮转 —— 查过的会被 touch 到 now，
        // 自动排到队尾。设阈值反而会出现「删了种要等 7 天才清理」这种反直觉行为
        // （升级后所有老记录都得等满阈值才第一次进池）。
        $missSince = time() - self::MISS_RECHECK_DAYS * 86400;

        $query = NexusDB::table('plugin_xseed_peers')
            ->where('site_id', $siteId)
            ->where(function ($query) use ($missSince) {
                // 命中过的正常轮转；另外把「很久以前核对过、当时没命中」的也捞进来，
                // 这样万一某次复核误判（对端瞬时返回不全）把记录置 0，还能自己恢复，
                // 不会永久卡在 0（那种情况以前只能手工 TRUNCATE）。
                $query->where('peer_torrent_id', '>', 0)
                    ->orWhere('checked_at', '<', $missSince);
            });

        if ($before > 0) {
            $query->where('checked_at', '<', $before);
        }

        $rows = $query->orderBy('checked_at')
            ->limit($limit)
            ->pluck('pieces_hash');

        $hashes = [];

        foreach ($rows as $hash) {
            $hash = strtolower(trim((string) $hash));

            if (preg_match('/^[0-9a-f]{40}$/', $hash)) {
                $hashes[] = $hash;
            }
        }

        return array_values(array_unique($hashes));
    }

    /**
     * 复核一批：只更新本地，不新增行
     *
     * 对端返回里没有的，说明对方把种子删了 —— 清掉本地那条命中（peer_torrent_id = 0），
     * 列表页立刻就不再显示这个站，而不是留着一个指向 404 的链接。
     */
    private function reviewBatch(XseedSite $site, array $hashes, array &$result): void
    {
        $siteId = (int) $site->id;
        $mapped = $this->remoteLookup($site, $hashes);
        $now = time();

        // 发出去的都算刚核对过，避免每轮都在同一批上打转
        NexusDB::table('plugin_xseed_peers')
            ->where('site_id', $siteId)
            ->whereIn('pieces_hash', $hashes)
            ->update(['checked_at' => $now]);

        $hit = [];
        $miss = [];

        foreach ($hashes as $hash) {
            $peerId = isset($mapped[$hash]) ? (int) $mapped[$hash] : 0;

            if ($peerId > 0) {
                $hit[$hash] = $peerId;
            } else {
                $miss[] = $hash;
            }
        }

        if ($hit !== []) {
            $cases = [];

            foreach ($hit as $hash => $peerId) {
                $cases[] = sprintf("when '%s' then %d", $hash, $peerId);
            }

            NexusDB::statement(sprintf(
                'UPDATE plugin_xseed_peers SET peer_torrent_id = CASE pieces_hash %s END '
                . 'WHERE site_id = %d AND pieces_hash IN (%s)',
                implode(' ', $cases),
                $siteId,
                $this->quoteList(array_keys($hit))
            ));
        }

        if ($miss !== []) {
            NexusDB::table('plugin_xseed_peers')
                ->where('site_id', $siteId)
                ->whereIn('pieces_hash', $miss)
                ->update(['peer_torrent_id' => 0]);

            $result['review'] += count($miss);
        }
    }

    /** 每轮复核批复数，可用 XSEED_REVIEW_CHUNKS 覆盖（想更快发现对端删种就调大） */
    public function reviewChunks(): int
    {
        return max(1, (int) env('XSEED_REVIEW_CHUNKS', self::REVIEW_CHUNKS_PER_RUN));
    }

    /**
     * 手动把某个友站的命中全部核对一遍（xseed:review）
     *
     * 按「最久没核对」的顺序一路查到底，查过的会排到队尾，所以重复跑会接着上次的地方走。
     *
     * @return array{checked:int,cleared:int,batches:int,error:string}
     */
    public function reviewSite(XseedSite $site, int $maxBatches = 0, int $budgetSeconds = 0): array
    {
        $result = ['checked' => 0, 'cleared' => 0, 'batches' => 0, 'error' => ''];
        $startedAt = microtime(true);
        $since = time();
        $lastBatch = [];

        while (true) {
            if ($maxBatches > 0 && $result['batches'] >= $maxBatches) {
                break;
            }

            if ($budgetSeconds > 0 && microtime(true) - $startedAt >= $budgetSeconds) {
                break;
            }

            $hashes = $this->nextStale((int) $site->id, self::CHUNK_SIZE, $since);

            if ($hashes === []) {
                break;
            }

            // 兜底：正常情况 touch 之后不会再取到同一批，万一更新没生效就停，别死循环
            if ($hashes === $lastBatch) {
                break;
            }

            $lastBatch = $hashes;

            $batch = ['total' => 0, 'hit' => 0, 'chunks' => 0, 'review' => 0, 'error' => ''];

            try {
                $this->reviewBatch($site, $hashes, $batch);
            } catch (\Throwable $throwable) {
                $result['error'] = $throwable->getMessage();
                break;
            }

            $result['checked'] += count($hashes);
            $result['cleared'] += $batch['review'];
            $result['batches']++;
        }

        return $result;
    }

    private function quoteList(array $values): string
    {
        $quoted = [];

        foreach ($values as $value) {
            $quoted[] = "'" . str_replace("'", "''", (string) $value) . "'";
        }

        return implode(',', $quoted);
    }

    /**
     * 清掉「本站已经不存在的种子」留下的记录
     *
     * 两种：种子被删（t.id 没了），种子被原地换过（id 还在但 hash 变了）。
     * 不清理的话，列表页/详情页会一直挂着一个查不到的映射。
     *
     * 分批删：首次升级时可能一次有几万条孤儿，单条 DELETE 不带上限会长时间锁表，
     * 把正在跑的种子列表查询一起堵住。
     */
    public function purgeOrphans(int $batchSize = 5000, int $maxBatches = 20): int
    {
        $sql = 'DELETE p FROM plugin_xseed_peers p '
            . 'LEFT JOIN torrents t ON t.id = p.torrent_id AND t.pieces_hash = p.pieces_hash '
            . 'WHERE t.id IS NULL LIMIT ' . max(1, $batchSize);

        try {
            $connection = NexusDB::table('plugin_xseed_peers')->getConnection();
        } catch (\Throwable $throwable) {
            return 0;
        }

        $total = 0;

        for ($i = 0; $i < $maxBatches; $i++) {
            try {
                $deleted = (int) $connection->delete($sql);
            } catch (\Throwable $throwable) {
                break;
            }

            if ($deleted <= 0) {
                break;
            }

            $total += $deleted;

            if ($deleted < $batchSize) {
                break;      // 没删满说明已经清空了
            }
        }

        return $total;
    }

    /**
     * 跑之前先确认表结构是新的
     *
     * 万一只 composer update 没跑 plugin install，唯一键还是老的 (site_id, pieces_hash)，
     * upsert 会把同 hash 的第二个种子顶掉、这批种子每轮都被重新捞出来 —— 不报错，
     * 但会一直空转、白烧对端流量。这里提前拦下来，后台直接显示原因。
     */
    private function schemaProblem(): ?string
    {
        static $problem = null;
        static $checked = false;

        if ($checked) {
            return $problem;
        }

        $checked = true;

        try {
            $indexes = NexusDB::table('plugin_xseed_peers')->getConnection()
                ->select('show index from plugin_xseed_peers');
        } catch (\Throwable $throwable) {
            $problem = 'table plugin_xseed_peers missing, run: php artisan plugin install ptskit/nexusphp-xseed';
            return $problem;
        }

        foreach ($indexes as $index) {
            if (($index->Key_name ?? '') === 'uk_site_pieces_torrent') {
                return null;
            }
        }

        $problem = 'index uk_site_pieces_torrent missing, run: php artisan plugin install ptskit/nexusphp-xseed';

        return $problem;
    }

    /** 问对端：给我这批 pieces_hash 对应的 torrent_id */
    private function remoteLookup(XseedSite $site, array $pieces): array
    {
        $body = json_encode(['pieces' => array_values($pieces)], JSON_UNESCAPED_SLASHES);
        $ts = (string) time();

        $response = Http::withHeaders([
            'X-Xseed-Site' => $this->selfBaseUrl(),
            'X-Xseed-Ts' => $ts,
            'X-Xseed-Sign' => hash_hmac('sha256', $ts . $body, (string) $site->secret),
            'Accept' => 'application/json',
        ])
            ->withBody($body, 'application/json')
            ->connectTimeout(5)
            ->timeout(20)
            ->post(rtrim((string) $site->base_url, '/') . '/plugin-xseed/api/lookup');

        if (!$response->ok()) {
            throw new \RuntimeException(sprintf('lookup failed, http %s', $response->status()));
        }

        $json = $response->json();

        if (!is_array($json) || !isset($json['ret']) || (int) $json['ret'] !== 0) {
            throw new \RuntimeException(sprintf('lookup failed, resp: %s', mb_substr((string) $response->body(), 0, 200)));
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    /**
     * 本站对外地址，用作 X-Xseed-Site 头（对端靠它找到「你是谁」）
     *
     * 不能直接用 getSchemeAndHttpHost(true)：CLI（调度器）下 isHttps() 会拿
     * security.securelogin 猜协议，站点设在 CDN / 反向代理后面、securelogin=no 时
     * 会被猜成 http://，而对端按 https:// 存的记录就匹配不上 → 401 unauthorized。
     *
     * 所以以 basic.BASEURL 为准（NexusPHP 里它通常只有域名），缺协议默认补 https；
     * 真的是 http 站就在 .env 里设 XSEED_SELF_URL=http://your.domain 覆盖。
     */
    public function selfBaseUrl(): string
    {
        $override = trim((string) env('XSEED_SELF_URL', ''));

        if ($override !== '') {
            return rtrim($override, '/');
        }

        $host = rtrim(trim((string) get_setting('basic.BASEURL')), '/');

        if ($host === '') {
            $host = rtrim((string) getSchemeAndHttpHost(true), '/');
        }

        if (preg_match('#^https?://#i', $host)) {
            return $host;
        }

        $host = ltrim(preg_replace('#^//#', '', $host), '/');

        return $host === '' ? '' : 'https://' . $host;
    }

    private function getMigrationPath(): string
    {
        return dirname(__DIR__) . '/database/migrations';
    }
}
