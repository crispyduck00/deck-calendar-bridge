<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Extend existing relations; never touch Nextcloud's core DAV/Deck tables.
 * Legacy links intentionally remain unbound until their rule, label,
 * organizer and current Deck card can be independently verified. */
final class Version1007Date20261024100000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if (!$schema->hasTable('dcb_event_links')) return null;
        $table=$schema->getTable('dcb_event_links');
        if (!$table->hasColumn('source_board_id'))
            $table->addColumn('source_board_id',Types::BIGINT,['unsigned'=>true,'notnull'=>false]);
        if (!$table->hasColumn('source_label_id'))
            $table->addColumn('source_label_id',Types::BIGINT,['unsigned'=>true,'notnull'=>false]);
        if (!$table->hasColumn('calendar_object_id'))
            $table->addColumn('calendar_object_id',Types::BIGINT,['unsigned'=>true,'notnull'=>false]);
        if (!$table->hasIndex('dcb_card_label_lookup'))
            $table->addIndex(['source_type','source_id','source_label_id'],'dcb_card_label_lookup');
        return $schema;
    }
}
