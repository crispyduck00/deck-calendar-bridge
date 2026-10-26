<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Durable origin for a dirty Deck card; not itself an authorization grant. */
final class Version1005Date20261021203000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('dcb_dirty_cards')) return null;
        $table = $schema->getTable('dcb_dirty_cards');
        if (!$table->hasColumn('actor_uid')) {
            $table->addColumn('actor_uid', Types::STRING, ['length'=>128,'notnull'=>false]);
        }
        return $schema;
    }
}
