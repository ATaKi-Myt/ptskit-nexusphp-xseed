<?php

namespace NexusPlugin\Xseed\Console;

use Illuminate\Console\Command;
use NexusPlugin\Xseed\Models\XseedSite;
use NexusPlugin\Xseed\XseedRepository;

/**
 * 手动同步：php artisan xseed:sync
 *
 * v0 用它验证链路，v1 挂到 Laravel schedule（每小时）。
 */
class SyncCommand extends Command
{
    protected $signature = 'xseed:sync {--site= : 只同步指定站点 id}';

    protected $description = '同步友站辅种映射（拉取式交集查询）';

    public function handle(): int
    {
        $query = XseedSite::query()->where('enabled', 1);
        $siteId = $this->option('site');

        if ($siteId !== null && $siteId !== '') {
            $query->where('id', (int) $siteId);
        }

        $sites = $query->get();

        if ($sites->isEmpty()) {
            $this->info('没有启用中的友站，先到后台「辅种站点」里添加一条。');
            return 0;
        }

        $repository = new XseedRepository();

        foreach ($sites as $site) {
            $this->info(sprintf('同步 %s (%s) ...', $site->name, $site->base_url));

            $result = $repository->pullSite($site);

            if ($result['error'] !== '') {
                $this->error(sprintf('  失败：%s', $result['error']));
                $site->update([
                    'last_sync_at' => time(),
                    'last_sync_result' => mb_substr('ERR ' . $result['error'], 0, 250),
                ]);
                continue;
            }

            $this->info(sprintf('  完成：查询 %d 条，命中 %d 条，共 %d 批', $result['total'], $result['hit'], $result['chunks']));

            $site->update([
                'last_sync_at' => time(),
                'last_sync_result' => sprintf('OK total=%d hit=%d', $result['total'], $result['hit']),
            ]);
        }

        return 0;
    }
}
