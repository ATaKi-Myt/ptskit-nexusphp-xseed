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
 * 单次运行有时间预算（默认 50 秒），到点就停，游标已落库，下一分钟从断点接着跑。
 * 所以首装 6 万条种子两三轮就能推完，之后每轮只处理新增 + 少量复核，
 * 不会重复扫已经问过的部分。
 *
 * 手动强制同步：php artisan xseed:sync --site=1
 */
class SyncCommand extends Command
{
    protected $signature = 'xseed:sync
        {--site= : 只同步指定友站 id（忽略间隔，立即执行）}
        {--all : 忽略间隔，同步所有启用的友站}
        {--budget= : 单站运行时间预算（秒），默认取插件里的 50}';

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

        $budget = (int) $this->option('budget');
        $explicitBudget = $budget > 0;

        if (!$explicitBudget) {
            $budget = XseedRepository::RUN_BUDGET_SECONDS;
        }

        $repository = new XseedRepository();
        $done = 0;

        // 调度是每分钟一次，多个站不能各自吃满 50 秒，否则会拖到下一分钟被
        // withoutOverlapping 跳过。这里掐总时长，没轮到的站下一分钟接着来（游标已存）。
        // 手动指定 --budget 时不掐（命令行跑不受 cron 约束，可以一次推完 6 万条）。
        $deadline = microtime(true) + ($explicitBudget ? $budget : XseedRepository::TOTAL_BUDGET_SECONDS);

        foreach ($sites as $site) {
            if (!$force && !$site->isDue()) {
                continue;   // 定时任务：还没到间隔，跳过
            }

            $left = (int) ($deadline - microtime(true));

            if ($left < 5) {
                $this->info('本轮时间用完，剩下的下一分钟继续。');
                break;
            }

            $done++;
            $this->info(sprintf('同步 %s (%s) ...', $site->name, $site->base_url));

            $result = $repository->pullSite($site, XseedRepository::MAX_CHUNKS_PER_RUN, min($budget, $left));

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

            $this->info(sprintf(
                '  完成：新查 %d 条，命中 %d 条，复核清理 %d 条，共 %d 批',
                $result['total'],
                $result['hit'],
                $result['review'],
                $result['chunks']
            ));

            $site->update([
                'last_sync_at' => time(),
                'last_sync_total' => $result['total'],
                'last_sync_hit' => $result['hit'],
                'last_sync_result' => sprintf(
                    'OK total=%d hit=%d review=%d',
                    $result['total'],
                    $result['hit'],
                    $result['review']
                ),
            ]);
        }

        // 本站删种 / 换种留下的记录，跟友站无关，整轮跑完清一次就够
        $purged = $repository->purgeOrphans();

        if ($purged > 0) {
            $this->info(sprintf('清理本站已不存在的记录：%d 条', $purged));
        }

        if ($done === 0) {
            $this->info('没有到期的友站。');
        }

        return 0;
    }
}
