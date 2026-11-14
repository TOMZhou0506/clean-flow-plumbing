<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\LoginRateLimiter;
use Cake\Http\Exception\ServiceUnavailableException;
use Cake\Http\Exception\TooManyRequestsException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Rejects excessive login POSTs before password hashing takes place.
 */
class LoginRateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param \App\Service\LoginRateLimiter|null $limiter Limiter service.
     */
    public function __construct(private ?LoginRateLimiter $limiter = null)
    {
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $limiter = $this->limiter ?? new LoginRateLimiter();
        if (!$limiter->isEnabled()) {
            return $handler->handle($request);
        }

        try {
            $result = $limiter->reserve($request);
        } catch (Throwable $exception) {
            throw new ServiceUnavailableException(
                'Sign in is temporarily unavailable. Please try again later.',
                null,
                $exception,
            );
        }

        if ($result !== null && !$result['allowed']) {
            $exception = new TooManyRequestsException(
                'Too many login attempts. Please try again later.',
            );
            $exception->setHeader('Retry-After', (string)max(1, $result['retryAfter']));

            throw $exception;
        }

        return $handler->handle($request);
    }
}
