<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Grace-period observation prevents transient missing DAV objects from
 * becoming destructive Deck label changes after server/client outages. */
final class Version1008Date20261024103000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options):?ISchemaWrapper {
        $schema=$schemaClosure();
        if (!$schema->hasTable('dcb_event_links')) return null;
        $table=$schema->getTable('dcb_event_links');
        if (!$table->hasColumn('missing_since')) {
            $table->addColumn('missing_since',Types::BIGINT,['unsigned'=>true,'notnull'=>false]);
        }
        return $schema;
    }
}
