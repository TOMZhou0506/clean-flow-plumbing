<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\FormRateLimitMiddleware;
use Cake\Core\Configure;
use Cake\Http\Exception\TooManyRequestsException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Isolated form-throttling tests; no traffic is sent to the deployed site.
 */
class FormRateLimitMiddlewareTest extends TestCase
{
    protected array $fixtures = ['app.FormRateLimits'];

    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConfig = (array)Configure::read('FormRateLimit');
        Configure::write('FormRateLimit', [
            'enabled' => true,
            'keySalt' => 'test-only-form-rate-limit-key',
            'contact' => ['limit' => 2, 'window' => 900],
            'jobRequest' => ['limit' => 2, 'window' => 900],
        ]);
    }

    protected function tearDown(): void
    {
        Configure::write('FormRateLimit', $this->originalConfig);
        parent::tearDown();
    }

    public function testThirdContactSubmissionFromSameIpIsBlocked(): void
    {
        $middleware = new FormRateLimitMiddleware();
        $handler = $this->handler();
        $request = $this->request('Contacts', 'add');

        $middleware->process($request, $handler);
        $middleware->process($request, $handler);

        try {
            $middleware->process($request, $handler);
            $this->fail('The third contact submission should have been rate limited.');
        } catch (TooManyRequestsException $exception) {
            $this->assertStringContainsString('Too many messages', $exception->getMessage());
            $this->assertGreaterThan(0, (int)$exception->getHeaders()['Retry-After']);
        }

        $this->assertSame(2, $handler->handled);
    }

    public function testThirdJobRequestForSameCustomerAndIpIsBlocked(): void
    {
        $middleware = new FormRateLimitMiddleware();
        $handler = $this->handler();
        $request = $this->request('JobRequests', 'add', 42);

        $middleware->process($request, $handler);
        $middleware->process($request, $handler);

        $this->expectException(TooManyRequestsException::class);
        $this->expectExceptionMessage('Too many job requests');
        $middleware->process($request, $handler);
    }

    public function testDifferentCustomersOnSameIpHaveSeparateJobAllowances(): void
    {
        $middleware = new FormRateLimitMiddleware();
        $handler = $this->handler();

        $middleware->process($this->request('JobRequests', 'add', 42), $handler);
        $middleware->process($this->request('JobRequests', 'add', 42), $handler);
        $middleware->process($this->request('JobRequests', 'add', 84), $handler);

        $this->assertSame(3, $handler->handled);
    }

    public function testDifferentIpsHaveSeparateContactAllowances(): void
    {
        $middleware = new FormRateLimitMiddleware();
        $handler = $this->handler();

        $middleware->process($this->request('Contacts', 'add'), $handler);
        $middleware->process($this->request('Contacts', 'add'), $handler);
        $middleware->process($this->request('Contacts', 'add', null, '198.51.100.8'), $handler);

        $this->assertSame(3, $handler->handled);
    }

    public function testGetAndUnrelatedPostRequestsAreIgnored(): void
    {
        $middleware = new FormRateLimitMiddleware();
        $handler = $this->handler();
        $get = $this->request('Contacts', 'add')->withMethod('GET');
        $other = $this->request('Customers', 'register');

        $middleware->process($get, $handler);
        $middleware->process($other, $handler);

        $this->assertSame(2, $handler->handled);
        $this->assertSame(0, $this->getTableLocator()->get('FormRateLimits')->find()->count());
    }

    private function request(
        string $controller,
        string $action,
        ?int $customerId = null,
        string $ip = '203.0.113.25',
    ): ServerRequest {
        $request = (new ServerRequest(['environment' => ['REMOTE_ADDR' => $ip], 'post' => []]))
            ->withMethod('POST')
            ->withAttribute('params', compact('controller', 'action'));

        if ($customerId !== null) {
            $identity = new class ($customerId) {
                public function __construct(private int $customerId)
                {
                }

                public function get(string $field): mixed
                {
                    return $field === 'cust_id' ? $this->customerId : null;
                }
            };
            $request = $request->withAttribute('identity', $identity);
        }

        return $request;
    }

    /**
     * @return \Psr\Http\Server\RequestHandlerInterface&object{handled: int}
     */
    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public int $handled = 0;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->handled++;

                return new Response();
            }
        };
    }
}
