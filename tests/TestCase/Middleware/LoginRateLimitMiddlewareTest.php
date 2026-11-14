<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\LoginRateLimitMiddleware;
use App\Service\LoginRateLimiter;
use Cake\Core\Configure;
use Cake\Http\Exception\TooManyRequestsException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Isolated logic tests. They do not send traffic to the deployed website and
 * do not perform password guesses.
 */
class LoginRateLimitMiddlewareTest extends TestCase
{
    /**
     * @var array<string>
     */
    protected array $fixtures = ['app.LoginRateLimits'];

    /**
     * @var array<string, mixed>
     */
    private array $originalConfig = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConfig = (array)Configure::read('LoginRateLimit');
        Configure::write('LoginRateLimit', [
            'enabled' => true,
            'keySalt' => 'test-only-rate-limit-key',
            'accountIp' => ['limit' => 2, 'window' => 900],
            'ip' => ['limit' => 20, 'window' => 60],
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Configure::write('LoginRateLimit', $this->originalConfig);
        parent::tearDown();
    }

    /**
     * The request that exceeds a configured account limit is rejected before
     * the downstream authentication handler runs.
     *
     * @return void
     */
    public function testAccountLimitStopsRequestBeforeAuthentication(): void
    {
        $middleware = new LoginRateLimitMiddleware();
        $handler = $this->handler();
        $request = $this->unifiedRequest('admin', 'tom');

        $middleware->process($request, $handler);
        $middleware->process($request, $handler);

        try {
            $middleware->process($request, $handler);
            $this->fail('The third request should have been rate limited.');
        } catch (TooManyRequestsException $exception) {
            $this->assertGreaterThan(0, (int)$exception->getHeaders()['Retry-After']);
        }

        $this->assertSame(2, $handler->handled);
    }

    /**
     * The unified and legacy admin pages share the same account counter.
     *
     * @return void
     */
    public function testLegacyAndUnifiedLoginShareAccountLimit(): void
    {
        $middleware = new LoginRateLimitMiddleware();
        $handler = $this->handler();

        $middleware->process($this->unifiedRequest('admin', 'tom'), $handler);
        $middleware->process($this->adminRequest('tom'), $handler);

        $this->expectException(TooManyRequestsException::class);
        $middleware->process($this->unifiedRequest('admin', 'tom'), $handler);
    }

    /**
     * Customer login aliases also share one account counter.
     *
     * @return void
     */
    public function testLegacyAndUnifiedCustomerLoginShareAccountLimit(): void
    {
        $middleware = new LoginRateLimitMiddleware();
        $handler = $this->handler();

        $middleware->process($this->unifiedRequest('customer', 'customer@example.com'), $handler);
        $middleware->process($this->customerRequest('customer@example.com'), $handler);

        $this->expectException(TooManyRequestsException::class);
        $middleware->process($this->unifiedRequest('customer', 'customer@example.com'), $handler);
    }

    /**
     * A successful login resets only the narrow account-and-IP counter.
     *
     * @return void
     */
    public function testSuccessfulLoginClearsAccountLimit(): void
    {
        $limiter = new LoginRateLimiter();
        $request = $this->unifiedRequest('customer', 'customer@example.com');

        $this->assertTrue($limiter->reserve($request)['allowed']);
        $this->assertTrue($limiter->reserve($request)['allowed']);
        $this->assertFalse($limiter->reserve($request)['allowed']);

        $limiter->clearSuccessfulAttempt($request);

        $this->assertTrue($limiter->reserve($request)['allowed']);
    }

    /**
     * The broad IP limit counts attempts across different accounts and roles.
     *
     * @return void
     */
    public function testIpLimitIsSharedAcrossAccountsAndRoles(): void
    {
        Configure::write('LoginRateLimit.ip.limit', 2);
        Configure::write('LoginRateLimit.accountIp.limit', 10);

        $limiter = new LoginRateLimiter();
        $this->assertTrue($limiter->reserve($this->unifiedRequest('admin', 'first'))['allowed']);
        $this->assertTrue($limiter->reserve($this->unifiedRequest('customer', 'second'))['allowed']);
        $this->assertFalse($limiter->reserve($this->adminRequest('third'))['allowed']);
    }

    /**
     * Expired windows reset automatically without an account unlock action.
     *
     * @return void
     */
    public function testExpiredAccountWindowAllowsLoginAgain(): void
    {
        $limiter = new LoginRateLimiter();
        $request = $this->unifiedRequest('admin', 'tom');

        $this->assertTrue($limiter->reserve($request)['allowed']);
        $this->assertTrue($limiter->reserve($request)['allowed']);

        $table = $this->getTableLocator()->get('LoginRateLimits');
        $accountCounter = $table->find()->where(['scope' => 'account_ip'])->firstOrFail();
        $accountCounter->set('expires_at', DateTime::now()->subSeconds(1));
        $table->saveOrFail($accountCounter);

        $this->assertTrue($limiter->reserve($request)['allowed']);
    }

    /**
     * Ordinary page requests never create or consume a login counter.
     *
     * @return void
     */
    public function testNonLoginRequestIsIgnored(): void
    {
        $handler = $this->handler();
        $request = (new ServerRequest())
            ->withMethod('GET')
            ->withAttribute('params', ['controller' => 'Login', 'action' => 'index']);

        (new LoginRateLimitMiddleware())->process($request, $handler);

        $this->assertSame(1, $handler->handled);
        $this->assertSame(0, $this->getTableLocator()->get('LoginRateLimits')->find()->count());
    }

    /**
     * @param string $role Selected account role.
     * @param string $account Submitted email.
     * @return \Cake\Http\ServerRequest
     */
    private function unifiedRequest(string $role, string $account): ServerRequest
    {
        return $this->request(
            'Login',
            'index',
            ['role' => $role, 'email' => $account, 'password' => 'not-used'],
        );
    }

    /**
     * @param string $account Submitted admin email.
     * @return \Cake\Http\ServerRequest
     */
    private function adminRequest(string $account): ServerRequest
    {
        return $this->request(
            'Admins',
            'login',
            ['admin_email' => $account, 'admin_password' => 'not-used'],
        );
    }

    /**
     * @param string $account Submitted customer email.
     * @return \Cake\Http\ServerRequest
     */
    private function customerRequest(string $account): ServerRequest
    {
        return $this->request(
            'Customers',
            'login',
            ['cust_email' => $account, 'password' => 'not-used'],
        );
    }

    /**
     * @param string $controller Routed controller.
     * @param string $action Routed action.
     * @param array<string, string> $body Parsed request body.
     * @return \Cake\Http\ServerRequest
     */
    private function request(string $controller, string $action, array $body): ServerRequest
    {
        return (new ServerRequest([
            'environment' => ['REMOTE_ADDR' => '203.0.113.25'],
            'post' => $body,
        ]))
            ->withMethod('POST')
            ->withAttribute('params', compact('controller', 'action'));
    }

    /**
     * A small spy proving whether authentication would have been reached.
     *
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
