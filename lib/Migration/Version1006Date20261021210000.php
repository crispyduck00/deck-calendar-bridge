<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Null means legacy row: the bridge has no proof it owns a matching
 * calendar category and must not remove it.
 */
final class Version1006Date20261021210000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if (!$schema->hasTable('dcb_event_links')) return null;
        $table=$schema->getTable('dcb_event_links');
        if ($table->hasColumn('managed_categories')) return null;
        $table->addColumn('managed_categories', Types::TEXT, ['notnull'=>false]);
        return $schema;
    }
}
