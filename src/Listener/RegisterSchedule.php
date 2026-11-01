<?php

namespace TacraftSociality\AutoDpr\Listener;

use Illuminate\Console\Scheduling\Schedule;
use TacraftSociality\AutoDpr\AutoDprService;
use TacraftSociality\AutoDpr\Log;
use Throwable;

/**
 * 把自动清理挂到 Flarum 的计划任务调度器上。
 *
 * 任务每 5 分钟触发一次，由 AutoDprService 按后台设置的间隔判断是否真正执行，
 * 因此系统 crontab 只需每分钟运行一次 `php flarum schedule:run`。
 */
class RegisterSchedule
{
    private static bool $applied = false;

    /**
     * 供控制台事件监听与容器解析钩子共用。
     */
    public static function apply(Schedule $schedule): void
    {
        if (self::$applied) {
            return;
        }

        self::$applied = true;

        try {
            $schedule
                ->call(static function (): void {
                    try {
                        resolve(AutoDprService::class)->run(false, 'scheduler');
                    } catch (Throwable $e) {
                        Log::error('[auto-dpr] scheduled run failed: '.$e->getMessage());
                    }
                })
                ->name('flarum-auto-dpr')
                ->everyFiveMinutes();
        } catch (Throwable $e) {
            self::$applied = false;
            Log::error('[auto-dpr] failed to register schedule: '.$e->getMessage());
        }
    }

    public function handle(object $event): void
    {
        $schedule = $this->scheduleFrom($event);

        if ($schedule instanceof Schedule) {
            self::apply($schedule);
        }
    }

    private function scheduleFrom(object $event): ?Schedule
    {
        foreach (['schedule', 'scheduler'] as $property) {
            try {
                if (property_exists($event, $property) && $event->{$property} instanceof Schedule) {
                    return $event->{$property};
                }
            } catch (Throwable) {
                // 私有属性无法访问时跳过
            }
        }

        if (method_exists($event, 'getSchedule')) {
            try {
                $schedule = $event->getSchedule();

                if ($schedule instanceof Schedule) {
                    return $schedule;
                }
            } catch (Throwable) {
                // 忽略
            }
        }

        try {
            $schedule = resolve(Schedule::class);

            if ($schedule instanceof Schedule) {
                return $schedule;
            }
        } catch (Throwable) {
            // 忽略
        }

        return null;
    }
}
