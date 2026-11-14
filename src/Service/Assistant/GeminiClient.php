<?php
declare(strict_types=1);

namespace App\Service\Assistant;

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\Log\Log;
use Throwable;

/**
 * Talks to the Gemini API.
 *
 * The API key is read from Configure, which reads it from config/app_local.php.
 * That file is gitignored and is the one place in this project a secret can live
 * without ending up on the remote. The key is never written into a template,
 * never sent to the browser, and never logged: the catch below records that a
 * call failed and what kind of failure it was, not the request that caused it.
 *
 * Everything that can go wrong returns null. A missing key, a refused
 * connection, a timeout, a 429, a 500, a response whose shape has changed under
 * us. The assistant then says it cannot help right now, which is honest and,
 * more to the point, leaves the page working. Monash hosting may block outbound
 * HTTPS altogether, so "the call did not happen" is a case to expect in the
 * demo rather than treat as exceptional.
 */
class GeminiClient implements AssistantClientInterface
{
    /**
     * Short on purpose. Somebody is watching a typing indicator, and a request
     * that is going to be blocked by a proxy should fail while they are still
     * interested rather than holding a PHP worker open.
     *
     * @var int
     */
    private const TIMEOUT_SECONDS = 15;

    /**
     * Deliberately generous, and this is the one number here not to "optimise"
     * downwards.
     *
     * Gemini 3.8 Flash thinks by default (medium), and maxOutputTokens is a
     * hard cutoff covering thinking tokens *and* the reply. Set it tight and
     * the model can spend the whole budget reasoning, stop with status
     * "incomplete", and hand back an empty answer that you are still billed
     * for. Google's own guidance is to lower the thinking level rather than
     * squeeze this number.
     *
     * The real length control is the system prompt, which asks for two short
     * paragraphs.
     *
     * @var int
     */
    private const MAX_OUTPUT_TOKENS = 2048;

    /**
     * Two attempts total. See the note in ask().
     *
     * @var int
     */
    private const MAX_ATTEMPTS = 2;

    /**
     * Half a second between them. Long enough for a busy model to clear,
     * short enough that nobody watching the panel notices.
     *
     * @var int
     */
    private const RETRY_PAUSE_MICROSECONDS = 500000;

    /**
     * Enough for Google's error status, the sentence after it, and the metric
     * name that follows the help URLs.
     *
     * 300 was too short: a quota refusal spends most of its length on two
     * documentation links before it says which quota was exceeded, and the
     * metric name is the only part that distinguishes a per-minute limit from
     * a daily one.
     *
     * @var int
     */
    private const MAX_DETAIL_LENGTH = 600;

    /**
     * @var \Cake\Http\Client
     */
    private Client $http;

    /**
     * @param \Cake\Http\Client|null $http Injectable for tests.
     */
    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => self::TIMEOUT_SECONDS]);
    }

    /**
     * @inheritDoc
     */
    public function isConfigured(): bool
    {
        $key = Configure::read('Assistant.apiKey');

        return is_string($key) && trim($key) !== '';
    }

    /**
     * @inheritDoc
     */
    public function ask(string $systemPrompt, string $context, string $question): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $payload = json_encode([
            // A real system instruction, not rules pasted on top of the user
            // turn. The model weights these differently, which is the point
            // when the user turn contains text from the public that may be
            // trying to override them.
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [[
                        // Context and question both sit in the user turn,
                        // separated and labelled. A visitor writing "ignore
                        // your instructions" is writing it inside text the
                        // system instruction has already defined as data.
                        'text' => "<knowledge>\n" . $context . "\n</knowledge>\n\n"
                            . "<question>\n" . $question . "\n</question>",
                    ]],
                ],
            ],
            'generationConfig' => [
                // Low, because the answer should track the supplied knowledge
                // rather than wander off it.
                'temperature' => 0.2,
                'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
            ],
        ], JSON_THROW_ON_ERROR);

        // One retry, no more.
        //
        // 503 from this API means the model is busy, not that the request was
        // wrong, and it clears on its own. Giving up on the first one tells a
        // visitor we cannot help when waiting half a second would have
        // answered them, which is what was happening in practice.
        //
        // Two attempts and a short pause, because somebody is watching a
        // typing indicator. A proper backoff belongs in a queue, not in a
        // request a person is waiting on.
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->http->post($this->endpoint(), $payload, [
                    'type' => 'json',
                    'headers' => [
                        'x-goog-api-key' => (string)Configure::read('Assistant.apiKey'),
                        'content-type' => 'application/json',
                    ],
                ]);

                if ($response->isOk()) {
                    return $this->extractText($response->getJson());
                }

                $status = $response->getStatusCode();

                // Google's own explanation, and nothing else from the body.
                //
                // This used to log the status alone, on the reasoning that a
                // failed call can echo back parts of the request and the
                // request contains whatever the visitor typed. That reasoning
                // still holds for the body as a whole, which is why this reads
                // two named fields rather than dumping it.
                //
                // error.status and error.message are Google's text, not ours
                // and not the visitor's: "RESOURCE_EXHAUSTED", "quota exceeded
                // for quota metric", "models/... is not found for API version
                // v1beta". Those three sentences are the difference between
                // "we are over quota" and "the model name is wrong", and
                // without them a 429 and a 404 look equally mysterious. We
                // spent an afternoon on exactly that.
                Log::warning('Assistant API returned {code} on attempt {attempt}. {detail}', [
                    'code' => $status,
                    'attempt' => $attempt,
                    'detail' => $this->failureDetail($response),
                ]);

                if (!$this->worthRetrying($status) || $attempt === self::MAX_ATTEMPTS) {
                    return null;
                }
            } catch (Throwable $exception) {
                Log::warning('Assistant API call failed on attempt {attempt}: {class}', [
                    'attempt' => $attempt,
                    'class' => $exception::class,
                ]);

                // A timeout or a refused connection is worth one more go for
                // the same reason a 503 is. A blocked proxy will refuse twice
                // and cost one extra pause, which is acceptable.
                if ($attempt === self::MAX_ATTEMPTS) {
                    return null;
                }
            }

            usleep(self::RETRY_PAUSE_MICROSECONDS);
        }

        return null;
    }

    /**
     * Whether a status is worth one more go.
     *
     * 503 means the model is busy rather than the request being wrong, and 429,
     * 500, 502 and 504 are the same kind of answer: try again and you may well
     * get through. A 400 or a 403 will fail identically every time, so retrying
     * those only doubles the wait before the person is told we cannot help.
     *
     * @param int $status The HTTP status we got.
     * @return bool
     */

    /**
     * Pull Google's own error status and message out of a failed response.
     *
     * Deliberately an allowlist of two fields rather than a dump of the body.
     * The body of a failed call can carry parts of the request back, and the
     * request carries whatever the visitor typed, so the whole body must not
     * reach the log. These two fields are generated by Google and describe the
     * call rather than its contents.
     *
     * Truncated, because a quota message can arrive with a long help URL
     * attached and a log line should stay one line.
     *
     * @param \Cake\Http\Client\Response $response The failed response.
     * @return string A short description, or a note that there was not one.
     */
    private function failureDetail(Response $response): string
    {
        try {
            $body = $response->getJson();
        } catch (Throwable $exception) {
            return 'No readable error body.';
        }

        if (!is_array($body) || !is_array($body['error'] ?? null)) {
            return 'No error detail in the body.';
        }

        $status = is_scalar($body['error']['status'] ?? null)
            ? (string)$body['error']['status']
            : '';
        $message = is_scalar($body['error']['message'] ?? null)
            ? (string)$body['error']['message']
            : '';

        $detail = trim($status . ' ' . $message);

        if ($detail === '') {
            return 'No error detail in the body.';
        }

        // Newlines would break the line into fragments that look like separate
        // log entries.
        $detail = (string)preg_replace('/\s+/', ' ', $detail);

        return mb_strimwidth($detail, 0, self::MAX_DETAIL_LENGTH, '...');
    }

    /**
     * @param int $status HTTP status from the API.
     * @return bool
     */
    private function worthRetrying(int $status): bool
    {
        // 429 is deliberately not in this list, which looks wrong at a glance.
        //
        // 503 and the 5xx family mean the model is busy right now, and half a
        // second later it often is not. 429 means we are over quota, and the
        // wait that would clear it is measured in seconds or minutes, not
        // milliseconds. Retrying it immediately cannot succeed, spends another
        // request against the same quota that is already exhausted, and makes
        // the person wait twice as long to be told we cannot help.
        return in_array($status, [500, 502, 503, 504], true);
    }

    /**
     * The generateContent URL for the configured model.
     *
     * Both halves are configurable so the team can move to a newer Flash
     * release, or point at a mock during a demo, without touching this class.
     *
     * @return string
     */
    private function endpoint(): string
    {
        $base = rtrim((string)Configure::read(
            'Assistant.endpoint',
            'https://generativelanguage.googleapis.com/v1beta/models',
        ), '/');

        $model = (string)Configure::read('Assistant.model', 'gemini-3.8-flash');

        return $base . '/' . rawurlencode($model) . ':generateContent';
    }

    /**
     * Pulls the answer text out of the response.
     *
     * Written to walk the structure rather than reaching straight for
     * $body['candidates'][0]['content']['parts'][0]['text'], for two reasons.
     * A thinking model can return several parts, and if the shape is not what
     * we expect the right outcome is a polite refusal rather than a TypeError
     * on a page a customer is looking at.
     *
     * It also understands the newer Interactions API shape, so pointing
     * Assistant.endpoint at that surface later does not need a code change.
     *
     * @param mixed $body Decoded response body.
     * @return string|null
     */
    private function extractText(mixed $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        // generateContent: candidates[].content.parts[].text
        $parts = [];
        foreach ($body['candidates'] ?? [] as $candidate) {
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                // Skip thought parts. They are the model's reasoning, not its
                // answer, and showing them to a customer would be noise at best.
                if (($part['thought'] ?? false) === true) {
                    continue;
                }
                if (is_string($part['text'] ?? null)) {
                    $parts[] = $part['text'];
                }
            }
        }

        // Interactions API: a convenience field, then the steps it summarises.
        if ($parts === [] && is_string($body['interaction']['output_text'] ?? null)) {
            $parts[] = $body['interaction']['output_text'];
        }

        if ($parts === []) {
            foreach ($body['interaction']['steps'] ?? [] as $step) {
                if (($step['type'] ?? null) !== 'model_output') {
                    continue;
                }
                foreach ($step['content'] ?? [] as $block) {
                    if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                        $parts[] = $block['text'];
                    }
                }
            }
        }

        $text = trim(implode("\n", $parts));

        return $text === '' ? null : $text;
    }
}
