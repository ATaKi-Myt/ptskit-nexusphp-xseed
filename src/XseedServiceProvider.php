<?php

namespace NexusPlugin\Xseed;

use Filament\Panel;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use NexusPlugin\Xseed\Console\SyncCommand;

class XseedServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name(XseedRepository::ID)
            ->hasTranslations()
            ->hasCommands([
                SyncCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        if (!XseedRepository::checkMainApplicationVersion()) {
            return;
        }

        Panel::configureUsing(function (Panel $panel) {
            $panel->plugin(Xseed::make());
        });
    }

    public function packageBooted(): void
    {
        if (!XseedRepository::checkMainApplicationVersion()) {
            return;
        }

        // 🔴 不能用 loadRoutesFrom()：spatie 那个方法会套 web 中间件组，
        //    于是 VerifyCsrfToken 会挡掉跨站的 POST（主程序的 $except 是硬编码的，插件改不了）。
        //    这里用空 group 加载，路由就完全没有中间件 —— 跨站调用要的正是这个。
        if (!$this->app->routesAreCached()) {
            Route::group([], dirname(__DIR__) . '/routes/web.php');
        }
    }
}
