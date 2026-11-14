<?php
declare(strict_types=1);

namespace App\Middleware;

use Cake\Core\Configure;
use Cake\Http\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds CakePHP's session object to each request.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    /**
     * Reuse an upstream session or attach the application's configured session.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler Request handler.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $session = $request->getAttribute('session');
        if (!$session instanceof Session) {
            $session = new Session((array)Configure::read('Session'));
        }

        return $handler->handle($request->withAttribute('session', $session));
    }
}
