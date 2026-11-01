<?php

namespace TacraftSociality\AutoDpr;

use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use TacraftSociality\AutoDpr\Listener\RegisterSchedule;
use Throwable;

class AutoDprServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // 只要 Flarum 解析计划任务调度器（php flarum schedule:run），
        // 就把自动清理任务挂上去；不依赖具体的控制台事件类。
        try {
            $this->container->afterResolving(Schedule::class, static function ($schedule): void {
                if ($schedule instanceof Schedule) {
                    RegisterSchedule::apply($schedule);
                }
            });
        } catch (Throwable $e) {
            Log::error('[auto-dpr] failed to hook the scheduler: '.$e->getMessage());
        }
    }
}
