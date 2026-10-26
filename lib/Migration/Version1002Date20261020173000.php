<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version1002Date20261020173000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if (!$schema->hasTable('dcb_event_links')) return null;
        $table=$schema->getTable('dcb_event_links');
        if (!$table->hasColumn('managed_hash')) {
            $table->addColumn('managed_hash', Types::STRING, ['length'=>64, 'notnull'=>false]);
            return $schema;
        }
        return null;
    }
}
