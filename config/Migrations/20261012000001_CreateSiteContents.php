<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Office-editable public text.
 *
 * site_contents holds what is shown now, one row per content key that has
 * been edited. A key with no row shows its default from ContentCatalogue.
 *
 * site_content_versions keeps every value ever saved, newest last, so any
 * earlier wording can be put back. Rows there are only ever inserted.
 * restored_from is the version a restore copied, or 0 for the original wording.
 */
class CreateSiteContents extends BaseMigration
{
    /** Create the current-content and version tables. */
    public function change(): void
    {
        $this->table('site_contents')
            ->addColumn('content_key', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('value', 'text', ['null' => false])
            ->addColumn('modified_by', 'integer', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['content_key'], ['unique' => true, 'name' => 'uq_site_contents_key'])
            ->create();

        $this->table('site_content_versions')
            ->addColumn('content_key', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('value', 'text', ['null' => false])
            ->addColumn('created_by', 'integer', ['null' => true, 'default' => null])
            ->addColumn('restored_from', 'integer', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['content_key', 'created'], ['name' => 'ix_site_content_versions_key'])
            ->create();
    }
}
