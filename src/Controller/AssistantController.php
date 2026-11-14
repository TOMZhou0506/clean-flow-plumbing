<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Assistant\Assistant;
use App\Service\Assistant\Audience;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Throwable;

/**
 * The website assistant's one endpoint.
 *
 * Open to everybody including signed-out visitors, which is the point: US 10.1
 * is about someone getting help without having to contact staff, and making
 * them register first would defeat it.
 *
 * Open does not mean unguarded. CSRF still applies, the audience is decided from
 * the session rather than from anything the browser sends, the question is
 * capped, and the whole thing is rate limited per IP because this is the only
 * route in the application that costs money per request.
 */
class AssistantController extends AppController
{
    /**
     * @var string
     */
    private const RATE_SCOPE = 'assistant_ip';

    /**
     * @var \App\Service\Assistant\Assistant|null
     */
    private ?Assistant $assistant = null;

    /**
     * Lets the tests supply an assistant with a stub model client, so the suite
     * never needs a network or a key.
     *
     * @param \App\Service\Assistant\Assistant $assistant The assistant.
     * @return void
     */
    public function setAssistant(Assistant $assistant): void
    {
        $this->assistant = $assistant;
    }

    /**
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        $this->Authentication->allowUnauthenticated(['ask']);
    }

    /**
     * Answer one question.
     *
     * Always returns 200 with a JSON body, even when it cannot help. US 10.1
     * says an unsupported question must not make the page fail, and a widget
     * that has to interpret five different status codes is a widget with five
     * ways to break. The only non-200 here is the rate limit, which the browser
     * genuinely needs to tell apart.
     *
     * @return \Cake\Http\Response
     */
    public function ask(): Response
    {
        $this->request->allowMethod(['post']);

        if (!(bool)Configure::read('Assistant.enabled', true)) {
            // The kill switch. Answers exactly as it would for a question it
            // cannot help with, so turning the assistant off mid-showcase looks
            // like a quiet assistant rather than a broken one.
            return $this->json($this->unavailable());
        }

        // Office accounts are refused outright. The widget is not rendered for
        // them, but a hidden button is not a control: the URL is still there
        // and anybody signed in can post to it.
        //
        // 403 rather than the usual "I cannot help" reply, because this is not
        // the assistant failing to answer. It is not their feature, and a plain
        // refusal is easier to understand than a chatty one that implies they
        // should keep trying.
        if (!Audience::isAvailableTo($this->request)) {
            return $this->json([
                'answered' => false,
                'answer' => 'The assistant is for customers and visitors.',
                'links' => [],
            ], 403);
        }

        $retryAfter = $this->rateLimitExceeded();
        if ($retryAfter !== null) {
            return $this->json([
                'answered' => false,
                'answer' => 'That is a lot of questions at once. Give me a moment and try again.',
                'links' => [],
            ], 429)->withHeader('Retry-After', (string)$retryAfter);
        }

        $question = $this->request->getData('question');
        $question = is_string($question) ? $question : '';

        // The audience comes from the session, never the request body. A
        // customer cannot ask to be treated as an admin, because there is
        // nothing in the payload that would let them.
        $audiences = Audience::forRequest($this->request);

        $result = ($this->assistant ?? new Assistant())->answer($question, $audiences);

        if (!$result['answered']) {
            $this->logGap($question, $audiences, $result['reason']);
        }

        return $this->json([
            'answered' => $result['answered'],
            'answer' => $result['answer'],
            'links' => $result['links'],
        ]);
    }

    /**
     * Records a question we could not answer, without ever letting that break
     * the reply.
     *
     * The visitor's answer is already decided by the time this runs. If the
     * table is missing because somebody has not migrated, or the write fails
     * for any other reason, they should still get their response.
     *
     * @param string $question The question asked.
     * @param list<string> $audiences Audiences the asker holds.
     * @param string $reason Why we could not answer.
     * @return void
     */
    private function logGap(string $question, array $audiences, string $reason): void
    {
        // Only genuine knowledge gaps are stored.
        //
        // This table answers one question: what should we write an answer for
        // next. An outage does not belong in it. When Gemini returns a 503 the
        // visitor's question may have been perfectly answerable, and filing it
        // as a gap sends the team off writing an answer that already exists
        // while the real fault, an unreachable API, is recorded nowhere useful.
        //
        // The other reasons are not silent. not_configured and unavailable are
        // already logged by the client with the status code that caused them,
        // which is where an operational problem belongs. An empty box is
        // somebody pressing enter and is not worth recording at all.
        if ($reason !== 'no_match' || trim($question) === '') {
            return;
        }

        try {
            $this->fetchTable('AssistantUnansweredQuestions')->record(
                $question,
                Audience::label($audiences),
                $reason,
            );
        } catch (Throwable $exception) {
            $this->log('Could not record an unanswered assistant question: ' . $exception::class, 'warning');
        }
    }

    /**
     * Per-IP throttle, reusing the counter table the public forms already use.
     *
     * This endpoint spends money on every call, so it is the one place where an
     * unthrottled loop is not merely rude but expensive. Keyed on IP alone
     * because signed-out visitors are the main audience and there is no account
     * to key on.
     *
     * @return int|null Seconds to wait, or null when the request is allowed.
     */
    private function rateLimitExceeded(): ?int
    {
        $ip = (string)($this->request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = 'unknown';
        }

        $salt = (string)Configure::read('FormRateLimit.keySalt', '');
        if ($salt === '') {
            $salt = (string)Configure::read('Security.salt', '');
        }

        try {
            $result = $this->fetchTable('FormRateLimits')->consume(
                hash_hmac('sha256', self::RATE_SCOPE . "\0" . $ip, $salt),
                self::RATE_SCOPE,
                (int)Configure::read('Assistant.rateLimit.limit', 20),
                (int)Configure::read('Assistant.rateLimit.window', 300),
            );
        } catch (Throwable $exception) {
            // A counter that cannot be read must not become a closed door. The
            // assistant is a help feature, and failing open here is the right
            // trade: the worst case is an unthrottled window, not an outage.
            $this->log('Assistant rate limit check failed: ' . $exception::class, 'warning');

            return null;
        }

        return $result['allowed'] ? null : max(1, (int)$result['retryAfter']);
    }

    /**
     * @return array{answered:bool, answer:string, links:list<array{label:string, url:string}>}
     */
    private function unavailable(): array
    {
        // The number comes from the website content editor, like everywhere else.
        return [
            'answered' => false,
            'answer' => 'I do not have an answer for that one. The team at Clean Flow '
                . 'can help, either on ' . $this->officePhone() . ' or through the contact form.',
            'links' => [['label' => 'Contact us', 'url' => '/contacts/add']],
        ];
    }

    /**
     * @param array<string, mixed> $payload Response body.
     * @param int $status HTTP status.
     * @return \Cake\Http\Response
     */
    private function json(array $payload, int $status = 200): Response
    {
        // JSON_HEX_TAG turns < and > into < and >.
        //
        // The widget reads this with response.json() and writes it with
        // textContent, so it is already inert there. This is the second layer,
        // for the day somebody renders the body into a page some other way, or
        // drops it into an inline script tag. "</script>" inside a string
        // closes the element regardless of what the JSON meant, and escaping
        // the angle brackets is the cheap, standard way of making that
        // impossible rather than merely unlikely.
        //
        // Nothing has to decode it specially: < is ordinary JSON and any
        // parser returns the original character.
        return $this->response
            ->withType('application/json')
            ->withStatus($status)
            ->withStringBody((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG));
    }

    /**
     * The office phone number as set in the website content editor.
     */
    private function officePhone(): string
    {
        return $this->fetchTable('SiteContents')->current('contact.phone');
    }
}
