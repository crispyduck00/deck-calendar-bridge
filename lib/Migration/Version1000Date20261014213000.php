<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** NC35 schema API; no modifications to Deck or calendar tables. */
final class Version1000Date20261014213000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('dcb_event_links')) {
            return null;
        }
        $table = $schema->createTable('dcb_event_links');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true]);
        $table->addColumn('source_type', Types::STRING, ['length' => 64]);
        $table->addColumn('source_id', Types::STRING, ['length' => 128]);
        $table->addColumn('event_slot', Types::STRING, ['length' => 128]);
        $table->addColumn('event_uid', Types::STRING, ['length' => 100]);
        $table->addColumn('sync_state', Types::STRING, ['length' => 32]);
        $table->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $table->addColumn('principal', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('calendar_uri', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('object_uri', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('etag', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['source_type', 'source_id', 'event_slot'], 'dcb_source_slot_unique');
        $table->addUniqueIndex(['event_uid'], 'dcb_event_uid_unique');
        return $schema;
    }
}
