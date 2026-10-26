<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Additive: retains every 0.3.x link and calendar UID for verified adoption. */
final class Version1009Date20261024190000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if (!$schema->hasTable('dcb_relations')) {
            $t=$schema->createTable('dcb_relations');
            $t->addColumn('id',Types::BIGINT,['autoincrement'=>true,'unsigned'=>true,'notnull'=>true]);
            foreach (['card_id','generation','board_id','tag_id','revision'] as $name) {
                $t->addColumn($name,Types::BIGINT,['unsigned'=>true,'notnull'=>true]);
            }
            foreach (['rule_id'=>64,'uid'=>128,'principal'=>255,'calendar_uri'=>255,'object_uri'=>255,'state'=>20] as $name=>$length) {
                $t->addColumn($name,Types::STRING,['length'=>$length,'notnull'=>true]);
            }
            $t->addColumn('payload',Types::TEXT,['length'=>4194304,'notnull'=>true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['uid'],'dcb_v2_uid');
            $t->addUniqueIndex(['card_id','rule_id','generation'],'dcb_v2_lifecycle');
            $t->addIndex(['card_id'],'dcb_v2_card');
        }
        if (!$schema->hasTable('dcb_sync_queue')) {
            $t=$schema->createTable('dcb_sync_queue');
            $t->addColumn('card_id',Types::BIGINT,['unsigned'=>true,'notnull'=>true]);
            $t->addColumn('generation',Types::BIGINT,['unsigned'=>true,'notnull'=>true]);
            $t->addColumn('retry_after',Types::BIGINT,['notnull'=>true,'default'=>0]);
            $t->setPrimaryKey(['card_id']);
        }
        return $schema;
    }
}
