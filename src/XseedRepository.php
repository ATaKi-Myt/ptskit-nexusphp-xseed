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
 * 核心：跨站只能靠 info_hash 对齐（binary(20) 完全相同），不做 piece 级匹配。
 * 数据只是缓存，随时可清空重建，所以「现在本地、后期中心服务器」不需要改表结构。
 *
 * 三道闸门：
 *   ① 后台/命令行定时跑 pullSite()，把本站 hash 分批发给友站，换回对方的 torrent_id
 *   ② 前台详情页 torrent_detail_before_desc 钩子输出一行站点图标
 *   ③ 前台列表页用 torrent_title_suffix 钩子延迟注入一段 JS，异步补一列
 */
class XseedRepository extends BasePlugin
{
    const ID = 'xseed';

    const VERSION = '0.1.0';

    const COMPATIBLE_NP_VERSION = '1.9.0';

    /** 一次请求带多少 hash 去问对端 */
    const CHUNK_SIZE = 1000;

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

        // 列表页：torrenttable() 没有列级 hook，只能借行内 filter 延迟注入 JS
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

        $rows = NexusDB::table('plugin_xseed_peers as p')
            ->join('plugin_xseed_sites as s', 's.id', '=', 'p.site_id')
            ->where('p.torrent_id', $torrentId)
            ->where('p.peer_torrent_id', '>', 0)
            ->where('s.enabled', 1)
            ->orderBy('s.id')
            ->select('s.name', 's.base_url', 's.icon', 'p.peer_torrent_id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $items = [];

        foreach ($rows as $row) {
            $baseUrl = rtrim((string) $row->base_url, '/');
            $icon = !empty($row->icon) ? $row->icon : $baseUrl . '/favicon.ico';
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

        // tr() 第三个参数 = noesc，1 表示内容已是 HTML，不要再转义
        tr(nexus_trans('xseed::xseed.row_label'), implode('&nbsp;&nbsp;', $items), 1);
    }

    /**
     * 列表页注入 JS
     *
     * 只在这里做（而不是 boot 里）是因为：torrenttable() 执行时 CURUSER 一定已就绪，
     * 而且这个 filter 只在种子列表渲染时触发，天然限定了作用页面。
     */
    public function injectListColumnJs($titleSuffix, $row = null)
    {
        static $injected = false;

        if (!$injected && !empty($GLOBALS['CURUSER']['id'])) {
            $injected = true;
            Nexus::js($this->listColumnJs(), 'footer', false);
        }

        return $titleSuffix;
    }

    private function listColumnJs(): string
    {
        $api = getSchemeAndHttpHost(true) . '/plugin-xseed/api/counts';
        $label = nexus_trans('xseed::xseed.col_label');
        $tip = nexus_trans('xseed::xseed.col_tip');

        $js = <<<JS
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

  var ids = [], map = {};
  var rows = table.querySelectorAll('tr');
  for (i = 1; i < rows.length; i++) {
    var tr = rows[i];
    if (tr.closest('table') !== table) { continue; }   // 跳过子分类行里的嵌套表格
    if (tr.querySelector('td.colhead')) { continue; }
    var a = tr.querySelector('a[href*="details.php?id="]');
    if (!a) { continue; }
    var m = /details\.php\?id=(\d+)/.exec(a.getAttribute('href'));
    if (!m) { continue; }
    if (!map[m[1]]) { map[m[1]] = []; ids.push(m[1]); }
    map[m[1]].push(tr);
  }
  if (!ids.length || ids.length > 200) { return; }

  var th = document.createElement('td');
  th.className = 'colhead';
  th.style.textAlign = 'center';
  th.title = '__TIP__';
  th.textContent = '__LABEL__';
  head.appendChild(th);

  var xhr = new XMLHttpRequest();
  xhr.open('GET', '__API__?ids=' + ids.join(','), true);
  xhr.onload = function () {
    var data = {};
    try { data = (JSON.parse(xhr.responseText) || {}).data || {}; } catch (e) { data = {}; }
    for (var k = 0; k < ids.length; k++) {
      var id = ids[k], c = parseInt(data[id] || 0, 10), tds = map[id];
      for (var j = 0; j < tds.length; j++) {
        var td = document.createElement('td');
        td.className = 'rowfollow';
        td.style.textAlign = 'center';
        if (c > 0) {
          var link = document.createElement('a');
          link.href = 'details.php?id=' + id;
          link.title = '__TIP__';
          link.textContent = c;
          td.appendChild(link);
        } else {
          td.innerHTML = '<span style="color:#999">-</span>';
        }
        tds[j].appendChild(td);
      }
    }
  };
  xhr.send();
})();
JS;

        return str_replace(['__API__', '__LABEL__', '__TIP__'], [$api, $label, $tip], $js);
    }

    // ---------------------------------------------------------------- 同步

    /**
     * 同步一个友站：分批把本站还没问过的 hash 发过去，换回对方的 torrent_id
     *
     * @return array{total:int,hit:int,chunks:int,error:string}
     */
    public function pullSite(XseedSite $site, int $maxChunks = 2000): array
    {
        $result = ['total' => 0, 'hit' => 0, 'chunks' => 0, 'error' => ''];

        if (empty($site->secret) || empty($site->base_url)) {
            $result['error'] = 'secret or base_url is empty';
            return $result;
        }

        while ($result['chunks'] < $maxChunks) {
            $rows = $this->nextDiff($site->id, self::CHUNK_SIZE);

            if ($rows->isEmpty()) {
                break;
            }

            $hashes = [];
            foreach ($rows as $row) {
                $hashes[] = bin2hex($row->info_hash);
            }

            try {
                $mapped = $this->remoteLookup($site, $hashes);
            } catch (\Throwable $throwable) {
                $result['error'] = $throwable->getMessage();
                break;
            }

            $now = time();
            $insert = [];

            foreach ($rows as $row) {
                $hex = bin2hex($row->info_hash);
                $peerId = isset($mapped[$hex]) ? (int) $mapped[$hex] : 0;

                $insert[] = [
                    'torrent_id' => (int) $row->id,
                    'site_id' => (int) $site->id,
                    'info_hash' => $row->info_hash,
                    'peer_torrent_id' => $peerId,
                    'checked_at' => $now,
                ];

                if ($peerId > 0) {
                    $result['hit']++;
                }
            }

            NexusDB::table('plugin_xseed_peers')->upsert(
                $insert,
                ['site_id', 'info_hash'],
                ['peer_torrent_id', 'checked_at']
            );

            $result['total'] += count($insert);
            $result['chunks']++;
        }

        return $result;
    }

    /** 本站「这个站还没问过」的种子（limit 保证内存可控） */
    private function nextDiff(int $siteId, int $limit)
    {
        return NexusDB::table('torrents as t')
            ->leftJoin('plugin_xseed_peers as p', function ($join) use ($siteId) {
                $join->on('p.torrent_id', '=', 't.id')->where('p.site_id', '=', $siteId);
            })
            ->whereNull('p.id')
            ->orderBy('t.id')
            ->limit($limit)
            ->select('t.id', 't.info_hash')
            ->get();
    }

    /** 问对端：给我这批 hash 对应的 torrent_id */
    private function remoteLookup(XseedSite $site, array $hashes): array
    {
        $body = json_encode(['hashes' => array_values($hashes)], JSON_UNESCAPED_SLASHES);
        $ts = (string) time();

        $response = Http::withHeaders([
            'X-Xseed-Site' => $this->selfBaseUrl(),
            'X-Xseed-Ts' => $ts,
            'X-Xseed-Sign' => hash_hmac('sha256', $ts . $body, (string) $site->secret),
            'Accept' => 'application/json',
        ])
            ->withBody($body, 'application/json')
            ->timeout(60)
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

    public function selfBaseUrl(): string
    {
        return rtrim(getSchemeAndHttpHost(true), '/');
    }

    private function getMigrationPath(): string
    {
        return dirname(__DIR__) . '/database/migrations';
    }
}
