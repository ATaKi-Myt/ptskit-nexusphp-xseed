<?php

namespace NexusPlugin\Xseed\Console;

use Illuminate\Console\Command;
use NexusPlugin\Xseed\Models\XseedSite;
use NexusPlugin\Xseed\XseedRepository;

/**
 * 手动复核：把已命中的记录逐个核对一遍，清掉友站已经删掉的种子
 *
 * 定时同步每轮只做几批复核（默认 4 批 × 1000 条），走完全量要几小时。
 * 想立刻看到「对方删掉的种子从列表里消失」就跑这个：6 万条命中大约 20 秒。
 *
 *   php artisan xseed:review                # 所有启用的友站，全部核对一遍
 *   php artisan xseed:review --site=1       # 只核对指定友站
 *   php artisan xseed:review --batches=10   # 只查 10 批（1 万条），剩下的下次接着来
 */
class ReviewCommand extends Command
{
    protected $signature = 'xseed:review
        {--site= : 只复核指定友站 id}
        {--batches= : 最多查多少批（每批 1000 条），默认不限}
        {--budget= : 时间预算（秒），默认不限}';

    protected $description = '复核已命中的记录，清理友站已经删掉的种子';

    public function handle(): int
    {
        $query = XseedSite::query()->where('enabled', 1);
        $siteId = (string) $this->option('site');

        if ($siteId !== '') {
            $query->where('id', (int) $siteId);
        }

        $sites = $query->get();

        if ($sites->isEmpty()) {
            $this->info('没有启用中的友站。');
            return 0;
        }

        $repository = new XseedRepository();
        $batches = max(0, (int) $this->option('batches'));
        $budget = max(0, (int) $this->option('budget'));

        foreach ($sites as $site) {
            $this->info(sprintf('复核 %s (%s) ...', $site->name, $site->base_url));

            $result = $repository->reviewSite($site, $batches, $budget);

            if ($result['error'] !== '') {
                $this->error(sprintf('  失败：%s', $result['error']));
                continue;
            }

            $this->info(sprintf(
                '  完成：核对 %d 条，清理 %d 条，共 %d 批',
                $result['checked'],
                $result['cleared'],
                $result['batches']
            ));
        }

        return 0;
    }
}
