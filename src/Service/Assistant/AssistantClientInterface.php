<?php
declare(strict_types=1);

namespace App\Service\Assistant;

/**
 * The language model, behind a door we control.
 *
 * Two reasons this is an interface rather than a class the service calls
 * directly. The tests need to run without a network or an API key, and they
 * need to be able to make the model return something hostile on purpose to
 * prove the handling around it holds. The second reason matters more: an
 * assistant whose safety claims are only ever tested against a cooperative
 * model has not been tested at all.
 *
 * Implementations return null rather than throwing when they cannot produce an
 * answer, for any reason: no key configured, the network refused, a timeout, a
 * rate limit, a malformed response. The caller then gives the same honest
 * "I cannot help with that right now" it would give for an unknown question.
 * US 10.1 requires that an unsupported question cannot make the page fail, and
 * an outbound call that Monash hosting may well block is the likeliest way for
 * that to happen.
 */
interface AssistantClientInterface
{
    /**
     * Ask the model a question, grounded in context we supply.
     *
     * The question is passed separately from the system prompt and must be
     * treated by implementations as untrusted data, never as instructions.
     *
     * @param string $systemPrompt The rules the model answers under.
     * @param string $context The knowledge entries it may answer from.
     * @param string $question The visitor's question, untrusted.
     * @return string|null The answer, or null if no answer could be obtained.
     */
    public function ask(string $systemPrompt, string $context, string $question): ?string;

    /**
     * Whether this client is configured well enough to be worth calling.
     *
     * Lets the service skip the attempt entirely when there is no key, rather
     * than making a request it knows will fail.
     *
     * @return bool
     */
    public function isConfigured(): bool;
}
