<?php

declare(strict_types=1);

namespace Zana\Testing\Security\Internal;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The last middleware of a route while it is attacked: a request that gets
 * here passed every access check of the route. In "stop" mode it answers
 * itself, so the handler never runs and an attack changes nothing; in "pass"
 * mode the handler runs, to see how it takes hostile input.
 *
 * @internal
 */
final class HandlerProbe implements MiddlewareInterface
{
    public const int REACHED = 299;

    public int $hits = 0;

    public bool $stop = true;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->hits++;

        return $this->stop ? new Response(self::REACHED, [], 'reached the handler') : $handler->handle($request);
    }
}
