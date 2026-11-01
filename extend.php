<?php

use Flarum\Extend;
use TacraftSociality\AutoDpr\Api\RunNowController;
use TacraftSociality\AutoDpr\Api\StatusController;
use TacraftSociality\AutoDpr\AutoDprServiceProvider;
use TacraftSociality\AutoDpr\Console\RunCommand;
use TacraftSociality\AutoDpr\Listener\RegisterSchedule;
use TacraftSociality\AutoDpr\Http\Middleware\RunIfDue;

$prefix = 'tacraft-sociality-auto-dpr.';

$extenders = [
    new Extend\ServiceProvider(AutoDprServiceProvider::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/auto-dpr/status', 'auto-dpr.status', StatusController::class)
        ->post('/auto-dpr/run', 'auto-dpr.run', RunNowController::class),

    (new Extend\Settings())
        ->default($prefix.'enabled', '')
        ->default($prefix.'interval_value', '1')
        ->default($prefix.'interval_unit', 'hours')
        ->default($prefix.'retention_value', '0')
        ->default($prefix.'retention_unit', 'days')
        ->default($prefix.'delete_discussions', '1')
        ->default($prefix.'delete_posts', '1')
        ->default($prefix.'only_author_hidden', '')
        ->default($prefix.'batch_size', '200')
        ->default($prefix.'run_on_request', '1')
        ->default($prefix.'last_run_at', '')
        ->default($prefix.'last_run_source', '')
        ->default($prefix.'last_deleted_discussions', '0')
        ->default($prefix.'last_deleted_posts', '0')
        ->default($prefix.'lock_until', ''),

    // 访问触发：没有配置系统计划任务时，也能按设定的间隔自动清理。
    (new Extend\Middleware('forum'))->add(RunIfDue::class),
    (new Extend\Middleware('api'))->add(RunIfDue::class),
];

/*
 * 定时任务：Flarum 基于 Illuminate Scheduler。
 * 在控制台事件中注册一个每 5 分钟运行一次的闭包，具体是否真正清理由
 * AutoDprService 按后台设置的间隔自行判断，因此不需要修改系统 crontab 的周期，
 * 只需要让 `php flarum schedule:run` 每分钟执行一次即可。
 */
$extenders[] = (new Extend\Event())
    ->listen(\Flarum\Console\Event\Configuring::class, RegisterSchedule::class);

// 可选的控制台命令：php flarum auto-dpr:run
try {
    if (class_exists(\Flarum\Extend\Console::class)) {
        $console = new \Flarum\Extend\Console();

        if (method_exists($console, 'command')) {
            $extenders[] = $console->command(RunCommand::class);
        }
    }
} catch (\Throwable $e) {
    // 命令注册失败不影响“访问触发”与定时任务，忽略即可。
}

return $extenders;
