<?php

/**
 * 插件路由：不带任何中间件组
 *
 * 不加 web 组的原因：跨站调用没有 session，也不需要 CSRF；
 * 加了 web 反而会被 VerifyCsrfToken 挡掉（主程序的 $except 是硬编码的，插件改不了）。
 */

use Illuminate\Support\Facades\Route;
use NexusPlugin\Xseed\Http\Controllers\XseedApiController;

Route::post('plugin-xseed/api/lookup', [XseedApiController::class, 'lookup']);
Route::get('plugin-xseed/api/counts', [XseedApiController::class, 'counts']);
