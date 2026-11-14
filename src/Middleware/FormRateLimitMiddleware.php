<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\FormRateLimiter;
use Cake\Http\Exception\ServiceUnavailableException;
use Cake\Http\Exception\TooManyRequestsException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Rejects excessive Contact and Job Request submissions before controllers run.
 */
class FormRateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param \App\Service\FormRateLimiter|null $limiter Form limiter service.
     */
    public function __construct(private ?FormRateLimiter $limiter = null)
    {
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $limiter = $this->limiter ?? new FormRateLimiter();
        if (!$limiter->isEnabled()) {
            return $handler->handle($request);
        }

        try {
            $result = $limiter->reserve($request);
        } catch (Throwable $exception) {
            throw new ServiceUnavailableException(
                'Form submission is temporarily unavailable. Please try again later.',
                null,
                $exception,
            );
        }

        if ($result !== null && !$result['allowed']) {
            $message = $result['form'] === 'contact'
                ? 'Too many messages have been submitted. Please try again later.'
                : 'Too many job requests have been submitted. Please try again later.';
            $exception = new TooManyRequestsException($message);
            $exception->setHeader('Retry-After', (string)max(1, $result['retryAfter']));

            throw $exception;
        }

        return $handler->handle($request);
    }
}
