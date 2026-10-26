<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add an actual processing lease timestamp without editing an applied migration. */
final class Version1004Date20261021190000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('dcb_reverse_outbox')) return null;
        $table = $schema->getTable('dcb_reverse_outbox');
        if (!$table->hasColumn('claimed_at')) {
            $table->addColumn('claimed_at', Types::BIGINT, ['unsigned'=>true, 'notnull'=>false]);
        }
        return $schema;
    }
}
