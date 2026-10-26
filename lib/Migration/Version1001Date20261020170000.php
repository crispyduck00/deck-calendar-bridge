<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** One compact dirty row per card. Migration never touches foreign app tables. */
final class Version1001Date20261020170000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('dcb_dirty_cards')) return null;
        $table = $schema->createTable('dcb_dirty_cards');
        $table->addColumn('card_id', Types::BIGINT, ['unsigned' => true]);
        $table->addColumn('generation', Types::BIGINT, ['unsigned' => true, 'default' => 1]);
        $table->addColumn('changed_at', Types::BIGINT, ['unsigned' => true]);
        $table->addColumn('last_inspected', Types::BIGINT, ['unsigned' => true, 'default' => 0]);
        $table->setPrimaryKey(['card_id']);
        $table->addIndex(['changed_at'], 'dcb_dirty_order');
        return $schema;
    }
}
