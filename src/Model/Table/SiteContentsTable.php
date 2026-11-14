<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Service\Content\ContentCatalogue;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Throwable;

/**
 * The public text the office has edited, and the rules for changing it.
 *
 * Reading never fails: a missing row, or a table that cannot be reached, gives
 * the catalogue default, so a public page never breaks over its wording.
 *
 * Writing goes through saveChanges() and restore() only. Both check every value
 * against ContentCatalogue, keep a version of each change, and record it in the
 * audit trail, all inside one transaction.
 *
 * @extends \Cake\ORM\Table<array{Timestamp: \Cake\ORM\Behavior\TimestampBehavior}, \Cake\ORM\Entity>
 * @property \App\Model\Table\SiteContentVersionsTable&\Cake\ORM\Association\HasMany $SiteContentVersions
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class SiteContentsTable extends Table
{
    /**
     * Values loaded for this request, so a page with forty items runs one query.
     *
     * @var array<string, string>|null
     */
    private ?array $loaded = null;

    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('site_contents');
        $this->setPrimaryKey('id');
        $this->setDisplayField('content_key');
        $this->addBehavior('Timestamp');
        $this->hasMany('SiteContentVersions', [
            'foreignKey' => 'content_key',
            'bindingKey' => 'content_key',
        ]);
    }

    /**
     * What a key shows now: the saved value, or the catalogue default.
     */
    public function current(string $key): string
    {
        $saved = $this->savedValues();

        return $saved[$key] ?? (ContentCatalogue::item($key)['default'] ?? '');
    }

    /**
     * Every saved value, keyed by content key.
     *
     * @return array<string, string>
     */
    public function savedValues(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        try {
            $rows = $this->find()->select(['content_key', 'value'])->all();
        } catch (Throwable $exception) {
            // The defaults are the safe answer for a page a customer is reading.
            return [];
        }

        $values = [];
        foreach ($rows as $row) {
            $values[(string)$row->content_key] = (string)$row->value;
        }

        return $this->loaded = $values;
    }

    /**
     * When and by whom each edited key was last changed.
     *
     * @return array<string, array{at: \Cake\I18n\DateTime|null, by: int|null}>
     */
    public function lastChanges(): array
    {
        $changes = [];
        foreach ($this->find()->select(['content_key', 'modified', 'modified_by'])->all() as $row) {
            $changes[(string)$row->content_key] = [
                'at' => $row->modified,
                'by' => $row->modified_by === null ? null : (int)$row->modified_by,
            ];
        }

        return $changes;
    }

    /**
     * Tidy one submitted value the same way whether it is checked or saved.
     */
    public static function normalise(string $kind, mixed $value): string
    {
        $text = is_scalar($value) ? (string)$value : '';
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if ($kind !== ContentCatalogue::KIND_TEXT) {
            $text = str_replace("\n", ' ', $text);
        }
        // Tidy spaces at the ends of lines and runs of blank lines.
        $text = (string)preg_replace('/[ \t]+\n/', "\n", $text);
        $text = (string)preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * Check a group of submitted values against the catalogue.
     *
     * @param array<string, mixed> $posted Content key => submitted value.
     * @return array<string, string> Content key => problem, empty when all is well.
     */
    public function validate(array $posted): array
    {
        $errors = [];
        foreach ($posted as $key => $raw) {
            $item = ContentCatalogue::item((string)$key);
            if ($item === null) {
                continue;
            }
            $value = self::normalise($item['kind'], $raw);
            $optional = $item['group'] === 'faq';

            if ($value === '' && !$optional) {
                $errors[$key] = 'This cannot be empty.';
            } elseif (mb_strlen($value) > $item['max']) {
                $errors[$key] = sprintf('Keep this to %d characters or fewer.', $item['max']);
            } elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
                $errors[$key] = 'This contains characters that cannot be shown.';
            } elseif (
                $item['kind'] === ContentCatalogue::KIND_PHONE
                && (preg_match('/^[0-9 ()+-]+$/', $value) !== 1
                    || !in_array(strlen((string)preg_replace('/\D/', '', $value)), [10, 11], true))
            ) {
                $errors[$key] = 'Enter a phone number such as (03) 9000 0000.';
            }
        }

        // An FAQ question needs its answer, and an answer needs its question.
        for ($number = 1; $number <= ContentCatalogue::FAQ_SLOTS; $number++) {
            $questionKey = "faq.$number.question";
            $answerKey = "faq.$number.answer";
            if (!array_key_exists($questionKey, $posted) || !array_key_exists($answerKey, $posted)) {
                continue;
            }
            $question = self::normalise(ContentCatalogue::KIND_LINE, $posted[$questionKey]);
            $answer = self::normalise(ContentCatalogue::KIND_TEXT, $posted[$answerKey]);
            if ($question !== '' && $answer === '' && !isset($errors[$answerKey])) {
                $errors[$answerKey] = 'Add an answer, or clear the question to hide it.';
            } elseif ($question === '' && $answer !== '' && !isset($errors[$questionKey])) {
                $errors[$questionKey] = 'Add the question this answers, or clear the answer.';
            }
        }

        return $errors;
    }

    /**
     * Save whichever of the submitted values differ from what is shown now.
     *
     * Call validate() first. Each changed key gets its row updated, a version
     * added and an audit entry written, all or nothing.
     *
     * @param array<string, mixed> $posted Content key => submitted value.
     * @param int|null $adminId Who is saving.
     * @param string $ip Their address, for the audit trail.
     * @return list<string> The keys that changed.
     */
    public function saveChanges(array $posted, ?int $adminId, string $ip): array
    {
        $changes = [];
        foreach ($posted as $key => $raw) {
            $item = ContentCatalogue::item((string)$key);
            if ($item === null) {
                // Only the agreed items can ever be written.
                continue;
            }
            $value = self::normalise($item['kind'], $raw);
            if ($value !== $this->current((string)$key)) {
                $changes[(string)$key] = $value;
            }
        }

        if ($changes === []) {
            return [];
        }

        $this->getConnection()->transactional(function () use ($changes, $adminId, $ip): void {
            foreach ($changes as $key => $value) {
                $this->write($key, $value, $adminId, $ip, 'content.updated', null);
            }
        });
        $this->loaded = null;

        return array_keys($changes);
    }

    /**
     * Put an earlier version back, or the original wording when $versionId is null.
     *
     * Restoring is itself a change: it is saved as a new version and audited,
     * so the history only ever grows and the restore can be undone too.
     *
     * @return bool False when the key or version is not one that can be restored.
     */
    public function restore(string $key, ?int $versionId, ?int $adminId, string $ip): bool
    {
        $item = ContentCatalogue::item($key);
        if ($item === null) {
            return false;
        }

        if ($versionId === null) {
            $value = $item['default'];
        } else {
            $version = $this->SiteContentVersions->find()
                ->where(['id' => $versionId, 'content_key' => $key])
                ->first();
            if ($version === null) {
                return false;
            }
            $value = (string)$version->value;
        }

        if ($value === $this->current($key)) {
            return true;
        }

        // restored_from 0 marks "the original wording", which has no version row.
        $this->getConnection()->transactional(function () use ($key, $value, $adminId, $ip, $versionId): void {
            $this->write($key, $value, $adminId, $ip, 'content.restored', $versionId ?? 0);
        });
        $this->loaded = null;

        return true;
    }

    /**
     * One change: the current row, a new version, and the audit entry.
     */
    private function write(
        string $key,
        string $value,
        ?int $adminId,
        string $ip,
        string $action,
        ?int $restoredFrom,
    ): void {
        $previous = $this->current($key);
        $now = DateTime::now();

        $row = $this->find()->where(['content_key' => $key])->first() ?? $this->newEmptyEntity();
        $row->set('content_key', $key);
        $row->set('value', $value);
        $row->set('modified_by', $adminId);
        $this->saveOrFail($row);

        $versions = $this->SiteContentVersions;
        $version = $versions->newEmptyEntity();
        $version->set('content_key', $key);
        $version->set('value', $value);
        $version->set('created_by', $adminId);
        $version->set('restored_from', $restoredFrom);
        $version->set('created', $now);
        $versions->saveOrFail($version);

        TableRegistry::getTableLocator()->get('AuditLogs')->record(
            $action,
            $adminId,
            'site_content',
            $key,
            [
                'version_id' => (int)$version->id,
                'previous_length' => mb_strlen($previous),
                'new_length' => mb_strlen($value),
            ],
            $ip,
        );
    }
}
