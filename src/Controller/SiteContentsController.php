<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Content\ContentCatalogue;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;

/**
 * The website content editor: the office changes the public site's text
 * without a developer or a deployment.
 *
 * Only the agreed items in ContentCatalogue can be edited, only by the roles
 * in Cms.editorRoles, and every change is versioned and audited by
 * SiteContentsTable. Saved text is live on the next page load.
 *
 * @property \App\Model\Table\SiteContentsTable $SiteContents
 */
class SiteContentsController extends AppController
{
    /**
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->requireContentEditor();
    }

    /**
     * The four groups, with how much of each has been changed from the original.
     *
     * @return void
     */
    public function index(): void
    {
        $this->request->allowMethod(['get']);
        $saved = $this->SiteContents->savedValues();
        $lastChanges = $this->SiteContents->lastChanges();

        $groups = [];
        foreach (ContentCatalogue::GROUPS as $name => $group) {
            $items = ContentCatalogue::group($name);
            $edited = 0;
            $latest = null;
            foreach ($items as $key => $item) {
                if (isset($saved[$key]) && $saved[$key] !== $item['default']) {
                    $edited++;
                }
                $at = $lastChanges[$key]['at'] ?? null;
                if ($at !== null && ($latest === null || $at > $latest)) {
                    $latest = $at;
                }
            }
            $groups[$name] = $group + ['count' => count($items), 'edited' => $edited, 'latest' => $latest];
        }

        $this->set(compact('groups'));
    }

    /**
     * One group's form. Nothing is saved unless every value passes.
     *
     * @param string|null $group A key of ContentCatalogue::GROUPS.
     * @return \Cake\Http\Response|null
     */
    public function edit(?string $group = null): ?Response
    {
        if ($group === null || !isset(ContentCatalogue::GROUPS[$group])) {
            throw new NotFoundException('That content group does not exist.');
        }
        $items = ContentCatalogue::group($group);
        $errors = [];
        $values = [];
        foreach ($items as $key => $item) {
            $values[$key] = $this->SiteContents->current($key);
        }

        if ($this->request->is(['post', 'put'])) {
            // Field names cannot hold dots, so the form posts content[home__hero__heading].
            $posted = [];
            $submitted = (array)$this->request->getData('content', []);
            foreach ($items as $key => $item) {
                $field = self::fieldName($key);
                if (array_key_exists($field, $submitted)) {
                    $posted[$key] = $submitted[$field];
                }
            }

            $errors = $this->SiteContents->validate($posted);
            if ($errors === []) {
                $changed = $this->SiteContents->saveChanges(
                    $posted,
                    $this->currentAdminId(),
                    (string)$this->request->clientIp(),
                );
                $this->Flash->success($changed === []
                    ? __('Nothing had changed, so nothing was saved.')
                    : __n(
                        'Saved 1 change. It is live on the site now.',
                        'Saved {0} changes. They are live on the site now.',
                        count($changed),
                        count($changed),
                    ));

                return $this->redirect(['action' => 'edit', $group]);
            }

            foreach ($posted as $key => $value) {
                $values[$key] = is_scalar($value) ? (string)$value : '';
            }
            $this->Flash->error(__('Nothing was saved. Fix the highlighted items and try again.'));
        }

        $lastChanges = $this->SiteContents->lastChanges();
        $editors = $this->editorNames(array_filter(array_column($lastChanges, 'by')));
        $this->set(compact('group', 'items', 'values', 'errors', 'lastChanges', 'editors'));
        $this->set('groupInfo', ContentCatalogue::GROUPS[$group]);

        return null;
    }

    /**
     * Every saved version of one item, with a way to put any of them back.
     *
     * @return void
     */
    public function history(): void
    {
        $this->request->allowMethod(['get']);
        $key = (string)$this->request->getQuery('key');
        $item = ContentCatalogue::item($key);
        if ($item === null) {
            throw new NotFoundException('That content item does not exist.');
        }

        $versions = $this->fetchTable('SiteContentVersions')->history($key);
        $current = $this->SiteContents->current($key);
        $this->set(compact('key', 'item', 'versions', 'current'));
    }

    /**
     * Put an earlier version (or the original wording) back.
     *
     * @return \Cake\Http\Response
     */
    public function restore(): Response
    {
        $this->request->allowMethod(['post']);
        $key = (string)$this->request->getData('key');
        $version = $this->request->getData('version');
        $versionId = $version === 'default' ? null : filter_var($version, FILTER_VALIDATE_INT);

        if ($versionId === false || ContentCatalogue::item($key) === null) {
            throw new NotFoundException('That version does not exist.');
        }

        $restored = $this->SiteContents->restore(
            $key,
            $versionId,
            $this->currentAdminId(),
            (string)$this->request->clientIp(),
        );
        if (!$restored) {
            throw new NotFoundException('That version does not exist.');
        }

        $this->Flash->success(__('Restored. The site shows that version now.'));

        return $this->redirect(['action' => 'history', '?' => ['key' => $key]]);
    }

    /**
     * The form field for a content key: dots are not allowed in field names.
     */
    public static function fieldName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /**
     * Usernames for the admins who made changes, for "changed by" lines.
     *
     * @param array<int|null> $ids Admin ids.
     * @return array<int, string>
     */
    private function editorNames(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        return $this->fetchTable('Admins')
            ->find('list', keyField: 'admin_id', valueField: 'admin_username')
            ->where(['admin_id IN' => $ids])
            ->toArray();
    }
}
