<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * Every value ever saved for a piece of public text, so any of them can be
 * put back. Rows are only inserted, never changed or removed.
 *
 * @extends \Cake\ORM\Table<array{}, \Cake\ORM\Entity>
 * @property \App\Model\Table\AdminsTable&\Cake\ORM\Association\BelongsTo $Editors
 */
class SiteContentVersionsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('site_content_versions');
        $this->setPrimaryKey('id');
        $this->setDisplayField('content_key');
        $this->belongsTo('Editors', [
            'className' => 'Admins',
            'foreignKey' => 'created_by',
            'bindingKey' => 'admin_id',
        ]);
    }

    /**
     * One key's versions, newest first.
     *
     * @return list<\Cake\Datasource\EntityInterface>
     */
    public function history(string $key, int $limit = 25): array
    {
        return $this->find()
            ->contain(['Editors' => ['fields' => ['Editors.admin_id', 'Editors.admin_username']]])
            ->where(['SiteContentVersions.content_key' => $key])
            ->orderBy(['SiteContentVersions.created' => 'DESC', 'SiteContentVersions.id' => 'DESC'])
            ->limit($limit)
            ->all()
            ->toList();
    }
}
