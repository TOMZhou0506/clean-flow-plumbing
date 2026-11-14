<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AppController;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Base controller for JSON API endpoints.
 *
 * I make everything under /api return JSON, including errors. Without it an
 * exception renders CakePHP's HTML error template and a client trying to
 * JSON.parse a page of markup reports "unreadable response" instead of the
 * actual problem.
 */
class ApiController extends AppController
{
    /**
     * Initialize method
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        // I force the JSON view for every action in this namespace.
        $this->viewBuilder()->setClassName('Json');
    }

    /**
     * Applies response headers common to every API endpoint.
     *
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        // These responses carry customer contact details, so I do not let a
        // proxy or the browser retain them on disk.
        $this->response = $this->response
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * My success envelope.
     *
     * @param array<string, mixed> $meta
     */
    protected function ok(mixed $data = null, array $meta = []): void
    {
        $payload = ['ok' => true];

        if ($data !== null) {
            $payload['data'] = $data;
        }
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        $this->set($payload);
        $this->viewBuilder()->setOption('serialize', array_keys($payload));
    }

    /**
     * My error envelope.
     *
     * @param array<string, mixed> $fields
     */
    protected function fail(string $code, string $message, int $status = 400, array $fields = []): Response
    {
        $error = ['code' => $code, 'message' => $message];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        $body = json_encode(
            ['ok' => false, 'error' => $error],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody((string)$body);
    }

    /**
     * I parse and validate a positive integer id from the URL.
     */
    protected function parseId(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $id === false ? null : (int)$id;
    }

    /**
     * The signed-in admin's id, or null.
     *
     * I return an int because the identity is an Admins record and
     * admins.admin_id is an integer primary key. It was a string back when I
     * authenticated against users.id, which is a UUID.
     *
     * If we change the identity model again, this return type and the read_by
     * and audit_logs.user_id columns have to move together. I got that wrong
     * once and it does not throw: it silently stores null, so the audit trail
     * stays empty while every page still looks fine.
     */
    protected function currentUserId(): ?int
    {
        // Delegates rather than reading the identity again. This used to call
        // $identity->getIdentifier(), which returns get('id'), and admins has
        // no id column, so it was the silent-null case the note above warns
        // about. AppController::currentAdminId() reads admin_id.
        return $this->currentAdminId();
    }

    /**
     * The client IP, or 0.0.0.0 when it cannot be determined.
     *
     * @return string
     */
    protected function clientIp(): string
    {
        // Cake's clientIp() honours trusted proxies when they are configured
        // and falls back to REMOTE_ADDR otherwise, so I lean on it.
        $ip = $this->request->clientIp();

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }
}
