<?php

namespace TacraftSociality\AutoDpr\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TacraftSociality\AutoDpr\AutoDprService;
use TacraftSociality\AutoDpr\Config;
use Throwable;

/**
 * 访问触发：当站点没有配置系统计划任务（cron）时，也能按设定间隔自动清理。
 * 只有“已到间隔时间”的请求才会真正执行删除，其余请求只做一次时间比较。
 * 后台可以通过开关关闭此行为。
 */
class RunIfDue implements MiddlewareInterface
{
    public function __construct(
        private readonly AutoDprService $service,
        private readonly Config $config,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            if ($this->config->enabled() && $this->config->runOnRequest() && $this->service->isDue()) {
                $this->service->run(false, 'request');
            }
        } catch (Throwable $e) {
            // 自动清理无论如何都不应影响正常访问。
        }

        return $handler->handle($request);
    }
}
