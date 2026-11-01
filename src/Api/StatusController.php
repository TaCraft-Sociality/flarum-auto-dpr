<?php

namespace TacraftSociality\AutoDpr\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TacraftSociality\AutoDpr\AutoDprService;

class StatusController implements RequestHandlerInterface
{
    public function __construct(private readonly AutoDprService $service)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse(['data' => $this->service->status()]);
    }
}
