<?php

namespace NexusPlugin\Xseed\Console;

use Illuminate\Console\Command;
use NexusPlugin\Xseed\Models\XseedSite;
use NexusPlugin\Xseed\XseedRepository;

/**
 * 同步友站辅种映射（拉取式交集查询）
 *
 * 由 Laravel 调度每分钟叫一次，但真正的节奏由每条友站的「同步间隔」决定：
 * 没到期的站点直接跳过，所以站点再多也只是多几次很轻的本地 SQL。
 *
 * 手动强制同步：php artisan xseed:sync --site=1
 */
class SyncCommand extends Command
{
    protected $signature = 'xseed:sync {--site= : 只同步指定友站 id（忽略间隔，立即执行）} {--all : 忽略间隔，同步所有启用的友站}';

    protected $description = '同步友站辅种映射（按每条友站设置的间隔自动到期）';

    public function handle(): int
    {
        $query = XseedSite::query()->where('enabled', 1);

        $siteId = (string) $this->option('site');
        $force = $siteId !== '' || (bool) $this->option('all');

        if ($siteId !== '') {
            $query->where('id', (int) $siteId);
        }

        $sites = $query->get();

        if ($sites->isEmpty()) {
            $this->info('没有启用中的友站，先到后台「辅种站点」里添加一条。');
            return 0;
        }

        $repository = new XseedRepository();
        $done = 0;

        foreach ($sites as $site) {
            if (!$force && !$site->isDue()) {
                continue;   // 定时任务：还没到间隔，跳过
            }

            $done++;
            $this->info(sprintf('同步 %s (%s) ...', $site->name, $site->base_url));

            $result = $repository->pullSite($site);

            if ($result['error'] !== '') {
                $this->error(sprintf('  失败：%s', $result['error']));
                $site->update([
                    'last_sync_at' => time(),
                    'last_sync_total' => 0,
                    'last_sync_hit' => 0,
                    'last_sync_result' => mb_substr('ERR ' . $result['error'], 0, 250),
                ]);
                continue;
            }

            $this->info(sprintf('  完成：查询 %d 条，命中 %d 条，共 %d 批', $result['total'], $result['hit'], $result['chunks']));

            $site->update([
                'last_sync_at' => time(),
                'last_sync_total' => $result['total'],
                'last_sync_hit' => $result['hit'],
                'last_sync_result' => sprintf('OK total=%d hit=%d', $result['total'], $result['hit']),
            ]);
        }

        if ($done === 0) {
            $this->info('没有到期的友站。');
        }

        return 0;
    }
}
