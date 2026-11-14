<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Assistant;

use App\Service\Assistant\AssistantClientInterface;

/**
 * A stand-in for the model that records what it was asked and returns whatever
 * the test tells it to.
 *
 * Two jobs. It keeps the suite off the network, so nobody needs a Gemini key to
 * run the tests and nobody spends money running them. More importantly it lets
 * a test make the model behave badly on purpose: return HTML, return nothing,
 * fail outright. An assistant whose safety claims have only ever been checked
 * against a well-behaved model has not really been checked.
 *
 * It also records the calls, which is how the tests prove the model was *not*
 * called for a question we have no knowledge about. That is a claim about
 * absence, and absence is only testable if something is counting.
 */
class RecordingClient implements AssistantClientInterface
{
    /**
     * @var list<array{system:string, context:string, question:string}>
     */
    public array $calls = [];

    /**
     * @var string|null
     */
    private ?string $reply;

    /**
     * @var bool
     */
    private bool $configured;

    /**
     * @param string|null $reply What to return, or null to simulate a failure.
     * @param bool $configured Whether to claim a key is present.
     */
    public function __construct(?string $reply = 'A helpful answer.', bool $configured = true)
    {
        $this->reply = $reply;
        $this->configured = $configured;
    }

    /**
     * @inheritDoc
     */
    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /**
     * @inheritDoc
     */
    public function ask(string $systemPrompt, string $context, string $question): ?string
    {
        $this->calls[] = [
            'system' => $systemPrompt,
            'context' => $context,
            'question' => $question,
        ];

        return $this->reply;
    }

    /**
     * Everything the model was shown, across every call.
     *
     * Used to assert that text the asker was not entitled to never reached the
     * prompt. Checking the reply alone would be too weak: the point is that the
     * information was never sent to a third party in the first place.
     *
     * @return string
     */
    public function everythingSent(): string
    {
        $seen = [];
        foreach ($this->calls as $call) {
            $seen[] = $call['system'] . "\n" . $call['context'] . "\n" . $call['question'];
        }

        return implode("\n", $seen);
    }
}
