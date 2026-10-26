<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Reverse calendar intake is durable; never apply Deck changes inside DAV callbacks. */
final class Version1003Date20261021170000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('dcb_reverse_outbox')) return null;
        $table = $schema->createTable('dcb_reverse_outbox');
        $table->addColumn('id', Types::BIGINT, ['autoincrement'=>true, 'unsigned'=>true]);
        $table->addColumn('card_id', Types::BIGINT, ['unsigned'=>true]);
        $table->addColumn('event_slot', Types::STRING, ['length'=>128]);
        $table->addColumn('actor_uid', Types::STRING, ['length'=>128]);
        $table->addColumn('link_revision', Types::BIGINT, ['unsigned'=>true]);
        $table->addColumn('before_etag', Types::STRING, ['length'=>255]);
        $table->addColumn('after_etag', Types::STRING, ['length'=>255, 'notnull'=>false]);
        $table->addColumn('before_ics', Types::TEXT);
        $table->addColumn('after_ics', Types::TEXT, ['notnull'=>false]);
        $table->addColumn('state', Types::STRING, ['length'=>16, 'default'=>'pending']);
        $table->addColumn('reason', Types::STRING, ['length'=>255, 'notnull'=>false]);
        $table->addColumn('created_at', Types::BIGINT, ['unsigned'=>true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['state','created_at'], 'dcb_reverse_ready');
        $table->addIndex(['card_id','event_slot'], 'dcb_reverse_card');
        return $schema;
    }
}
