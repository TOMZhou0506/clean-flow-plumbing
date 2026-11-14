<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Assistant;

use App\Service\Assistant\Audience;
use App\Service\Assistant\KnowledgeBase;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which entry retrieval puts first.
 *
 * This barely mattered while the model was working: it received the top three
 * entries and blended them, so a mediocre ordering still produced a good
 * answer. It matters a great deal now. When the model cannot be reached the
 * assistant quotes the first entry and nothing else, so first place is the
 * whole answer.
 */
class KnowledgeBaseTest extends TestCase
{
    /**
     * Four entries that collide on purpose.
     *
     * 'where-to-go' and 'what-to-bring' both match a question about filling in
     * the form, and both used to score 2.0 for any multi-word hit, so the
     * winner was decided by which one was typed into the file first. That is
     * the bug these fixtures exist to pin down.
     *
     * @var list<array<string, mixed>>
     */
    private const ENTRIES = [
        [
            'id' => 'where-to-go',
            'audience' => ['public', 'customer'],
            'title' => 'Where to send a request',
            'keywords' => ['request service', 'request form', 'job request'],
            'answer' => 'Use the Request service button.',
        ],
        [
            'id' => 'what-to-bring',
            'audience' => ['public', 'customer'],
            'title' => 'What the form asks for',
            'keywords' => ['what do i need', 'what information', 'fill out'],
            'answer' => 'The form asks for the address and a description.',
        ],
        [
            'id' => 'services',
            'audience' => ['public', 'customer'],
            'title' => 'What we do',
            'keywords' => ['service', 'services', 'tap', 'leak'],
            'answer' => 'We handle pipe repairs and drainage.',
        ],
        [
            'id' => 'office-only',
            'audience' => ['admin', 'manager'],
            'title' => 'Internal',
            'keywords' => ['what do i need', 'service', 'margin'],
            'answer' => 'Office only: MARGIN-SECRET-42.',
        ],
    ];

    /**
     * @return \App\Service\Assistant\KnowledgeBase
     */
    private function knowledge(): KnowledgeBase
    {
        return new KnowledgeBase(self::ENTRIES);
    }

    /**
     * The two questions that sent a reader to the wrong paragraph in testing.
     *
     * The first is decided by word count: 'what do i need' is four words
     * against 'request service' at two. The second is a genuine tie on word
     * count, two each, and is decided by the longer phrase: 'what information'
     * over 'request form'.
     *
     * @param string $question What the visitor typed.
     * @param string $expected The entry that should come first.
     * @return void
     */
    #[DataProvider('rankingCases')]
    public function testTheMoreSpecificEntryWins(string $question, string $expected): void
    {
        $entries = $this->knowledge()->search($question, [Audience::PUBLIC]);

        $this->assertNotSame([], $entries, 'Expected a match for: ' . $question);
        $this->assertSame($expected, $entries[0]['id'], 'Wrong entry ranked first for: ' . $question);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rankingCases(): array
    {
        return [
            'asking what to prepare, not where to go' => [
                'what do i need to fill out the request service form',
                'what-to-bring',
            ],
            'same word count, longer phrase wins' => [
                'what information do i need to complete a service request form',
                'what-to-bring',
            ],
            'asking where to go really does get directions' => [
                'how do i send a job request',
                'where-to-go',
            ],
            'a single common word does not beat a phrase' => [
                'what do i need for the service',
                'what-to-bring',
            ],
            'an unrelated word still reaches the right entry' => [
                'my tap is leaking',
                'services',
            ],
        ];
    }

    /**
     * Ranking must never promote an entry the asker is not allowed to see.
     *
     * 'office-only' shares two keywords with 'what-to-bring' and would score
     * higher on some questions. The audience filter runs before scoring, so a
     * signed-out visitor should never see it at any position, whatever it
     * scores.
     *
     * @return void
     */
    public function testScoringNeverOutranksTheAudienceFilter(): void
    {
        $entries = $this->knowledge()->search('what do i need and what margin', [Audience::PUBLIC]);

        $ids = array_column($entries, 'id');
        $this->assertNotContains('office-only', $ids);

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('MARGIN-SECRET-42', (string)$entry['answer']);
        }
    }

    /**
     * An office account sees its own entry, which proves the test above is
     * checking the filter rather than a knowledge base that simply never
     * returns that entry.
     *
     * @return void
     */
    public function testTheOfficeEntryIsReachableByItsOwnAudience(): void
    {
        // 'manager' as a literal, not an Audience constant. The office
        // constants were removed when the assistant was restricted to the
        // public and customers, so no caller can ask for this audience any
        // more. The filter must still honour the tag, because the knowledge
        // file can carry it and a filter that ignored unknown audiences would
        // treat office entries as visible to everyone.
        $entries = $this->knowledge()->search('what margin do we add', ['manager']);

        $this->assertSame(['office-only'], array_column($entries, 'id'));
    }

    /**
     * A question matching nothing is refused rather than answered with the
     * least bad entry.
     *
     * @return void
     */
    public function testNoMatchReturnsNothing(): void
    {
        $this->assertSame([], $this->knowledge()->search('what is the mass of the sun', [Audience::PUBLIC]));
    }
}
