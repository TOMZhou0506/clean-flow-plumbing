<?php
declare(strict_types=1);

namespace App\Service\Assistant;

use Cake\Core\Configure;

/**
 * Finds the handful of knowledge entries worth putting in front of the model.
 *
 * Audience filtering happens before scoring, not after. That ordering is the
 * whole security story: an entry the asker is not allowed to see is never a
 * candidate, so it cannot be surfaced by a cleverly worded question, and it
 * never reaches the prompt where a model might repeat it. Filtering afterwards
 * would mean the text had already been sent to a third party.
 *
 * The scoring itself is deliberately boring. Keyword overlap with a small bonus
 * for longer phrase matches is enough to decide "is this question about job
 * requests or about pricing", which is all the model needs to be pointed at the
 * right paragraph. Anything cleverer would be harder to explain in a report and
 * no more accurate on a knowledge base of this size.
 */
class KnowledgeBase
{
    /**
     * Below this, we treat the question as unrelated to anything we know and
     * refuse rather than guessing. Tuned so a single incidental word ("the",
     * "job" on its own) is not enough to drag in an answer.
     *
     * @var float
     */
    private const MATCH_THRESHOLD = 1.0;

    /**
     * How many entries to hand the model. Enough to cover a question that
     * straddles two topics, few enough that the context stays short and cheap.
     *
     * @var int
     */
    private const MAX_ENTRIES = 3;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $entries;

    /**
     * @param array<int, array<string, mixed>>|null $entries Entries, for tests.
     */
    public function __construct(?array $entries = null)
    {
        $this->entries = $entries ?? self::load();
    }

    /**
     * Reads the knowledge file, once per request.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function load(): array
    {
        $configured = Configure::read('Assistant.knowledge');
        if (is_array($configured)) {
            return $configured;
        }

        $path = CONFIG . 'assistant_knowledge.php';
        if (!is_file($path)) {
            return [];
        }

        $entries = include $path;

        return is_array($entries) ? $entries : [];
    }

    /**
     * The best matching entries this audience may read.
     *
     * @param string $question The visitor's question, untrusted.
     * @param list<string> $audiences From Audience::forRequest().
     * @return array<int, array<string, mixed>> Possibly empty.
     */
    public function search(string $question, array $audiences): array
    {
        $needle = $this->normalise($question);
        if ($needle === '') {
            return [];
        }

        $scored = [];

        foreach ($this->entries as $entry) {
            // Access control first. See the note on the class.
            if (!$this->isVisibleTo($entry, $audiences)) {
                continue;
            }

            $result = $this->score($needle, $entry);
            if ($result['score'] >= self::MATCH_THRESHOLD) {
                $scored[] = [
                    'score' => $result['score'],
                    'specificity' => $result['specificity'],
                    'entry' => $entry,
                ];
            }
        }

        // Score first, then specificity as the tie-break.
        //
        // The tie-break is not a detail. "What do I need to fill out the
        // request service form" matches 'request service' on the where-to-go
        // entry and 'what do i need' on the what-to-bring entry. Both used to
        // score the same, so the winner was whichever appeared first in the
        // knowledge file, which is to say whichever somebody happened to type
        // first. The reader got directions when they asked what to prepare.
        //
        // Falling back to the longer matched phrase picks the more specific
        // one, which is almost always the one the question was actually about.
        usort(
            $scored,
            fn(array $a, array $b): int => [$b['score'], $b['specificity']]
                <=> [$a['score'], $a['specificity']],
        );

        return array_column(array_slice($scored, 0, self::MAX_ENTRIES), 'entry');
    }

    /**
     * @param array<string, mixed> $entry One knowledge entry.
     * @param list<string> $audiences Audiences the asker holds.
     * @return bool
     */
    private function isVisibleTo(array $entry, array $audiences): bool
    {
        $allowed = $entry['audience'] ?? [];
        if (!is_array($allowed) || $allowed === []) {
            // An entry with no audience is a mistake in the knowledge file.
            // Treat it as nobody's rather than everybody's, so the failure is a
            // missing answer rather than a leak.
            return false;
        }

        return array_intersect($allowed, $audiences) !== [];
    }

    /**
     * @param string $needle Normalised question.
     * @param array<string, mixed> $entry One knowledge entry.
     * @return array{score: float, specificity: int} The score, and the matched
     *     character count used only to break ties between equal scores.
     */
    private function score(string $needle, array $entry): array
    {
        $score = 0.0;
        $specificity = 0;
        $keywords = $entry['keywords'] ?? [];

        if (!is_array($keywords)) {
            return ['score' => 0.0, 'specificity' => 0];
        }

        foreach ($keywords as $keyword) {
            if (!is_string($keyword) || $keyword === '') {
                continue;
            }

            $keyword = $this->normalise($keyword);
            if ($keyword === '' || !str_contains($needle, $keyword)) {
                continue;
            }

            // One point per word in the matched phrase.
            //
            // This used to be a flat 2.0 for anything multi-word, which made
            // 'request service' and 'what do i need' worth exactly the same
            // despite one being twice as specific. Counting words says what we
            // actually mean: the more of the question a phrase accounts for,
            // the more it tells us about intent.
            //
            // A single common word stays at 1.0, so an entry stuffed with
            // short words still cannot outrank a precise match.
            $words = substr_count(trim($keyword), ' ') + 1;
            $score += (float)$words;

            // Characters matched, used only to break a tie between two entries
            // on the same score. Two phrases of equal word count are not
            // equally specific: 'what information' tells us more than
            // 'request form'.
            $specificity += mb_strlen(trim($keyword));
        }

        return ['score' => $score, 'specificity' => $specificity];
    }

    /**
     * Lowercase, strip punctuation, collapse whitespace, pad the ends.
     *
     * The padding lets a keyword be matched as a whole word with str_contains
     * rather than needing a regex, which keeps a visitor's text well away from
     * anything that compiles it.
     *
     * @param string $value Raw text.
     * @return string
     */
    private function normalise(string $value): string
    {
        $value = mb_strtolower($value);
        $value = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        $value = trim((string)preg_replace('/\s+/', ' ', $value));

        return $value === '' ? '' : ' ' . $value . ' ';
    }
}
