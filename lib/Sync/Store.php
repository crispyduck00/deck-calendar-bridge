<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;

use DomainException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** One canonical relation per lifecycle; old lifecycles remain identifiable. */
final class Store implements RelationStore {
    public function __construct(private IDBConnection $db) {}
    public function counts(): array {
        $q=$this->db->getQueryBuilder();
        $open=(int)$q->selectAlias($q->func()->count('id'),'n')->from('dcb_relations')
            ->where($q->expr()->neq('state',$q->createNamedParameter('deleted')))->executeQuery()->fetchOne();
        $q=$this->db->getQueryBuilder();
        $pending=(int)$q->selectAlias($q->func()->count('card_id'),'n')->from('dcb_sync_queue')->executeQuery()->fetchOne();
        return ['open_relations'=>$open,'pending_cards'=>$pending];
    }
    public function current(int $cardId): array {
        $q=$this->db->getQueryBuilder();
        $rows=$q->select('*')->from('dcb_relations')
            ->where($q->expr()->eq('card_id',$q->createNamedParameter($cardId,IQueryBuilder::PARAM_INT)))
            ->orderBy('generation','ASC')->executeQuery()->fetchAllAssociative();
        $out=[]; foreach ($rows as $row) { $r=$this->decode($row); $out[$r['rule_id']]=$r; }
        return $out;
    }
    public function findUid(string $uid): ?array {
        $q=$this->db->getQueryBuilder();
        $r=$q->select('*')->from('dcb_relations')->where($q->expr()->eq('uid',$q->createNamedParameter($uid)))
            ->executeQuery()->fetchAssociative();
        return $r===false?null:$this->decode($r);
    }
    public function insert(array $relation): array {
        $q=$this->db->getQueryBuilder(); $values=[];
        foreach ($this->encode($relation) as $k=>$v) $values[$k]=$q->createNamedParameter($v,is_int($v)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR);
        $q->insert('dcb_relations')->values($values)->executeStatement();
        return $this->findUid($relation['uid'])??throw new DomainException('relation_insert_not_confirmed');
    }
    public function save(array $relation): array {
        $q=$this->db->getQueryBuilder(); $q->update('dcb_relations');
        foreach ($this->encode($relation) as $k=>$v) {
            if ($k==='revision') $v++;
            $q->set($k,$q->createNamedParameter($v,is_int($v)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR));
        }
        $q->where($q->expr()->eq('id',$q->createNamedParameter($relation['id'],IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('revision',$q->createNamedParameter($relation['revision'],IQueryBuilder::PARAM_INT)));
        if ($q->executeStatement()!==1) throw new DomainException('relation_version_changed');
        $relation['revision']++; return $relation;
    }
    private function encode(array $r): array {
        $out=[];
        foreach (['card_id','rule_id','generation','uid','principal','calendar_uri','object_uri','board_id','tag_id','state','revision'] as $k) $out[$k]=$r[$k];
        $out['payload']=json_encode($r['data'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES); return $out;
    }
    private function decode(array $r): array {
        foreach (['id','card_id','generation','board_id','tag_id','revision'] as $k) $r[$k]=(int)$r[$k];
        $r['data']=json_decode($r['payload'],true,128,JSON_THROW_ON_ERROR); unset($r['payload']); return $r;
    }
    public function legacy(int $cardId): array {
        $q=$this->db->getQueryBuilder();
        return $q->select('*')->from('dcb_event_links')
            ->where($q->expr()->eq('source_type',$q->createNamedParameter('deck')))
            ->andWhere($q->expr()->eq('source_id',$q->createNamedParameter((string)$cardId)))
            ->executeQuery()->fetchAllAssociative();
    }
    /** Bounded numeric keyset scan includes archived/deleted sources and removed rules. */
    public function cardIdsAfter(int $cursor,int $limit=50): array {
        $q=$this->db->getQueryBuilder();
        $rows=$q->selectDistinct('card_id')->from('dcb_relations')
            ->where($q->expr()->gt('card_id',$q->createNamedParameter($cursor,IQueryBuilder::PARAM_INT)))
            ->orderBy('card_id','ASC')->setMaxResults($limit)->executeQuery()->fetchAllAssociative();
        return array_map(static fn(array $r):int=>(int)$r['card_id'],$rows);
    }
    public function legacyCardsAfter(int $cursor,int $limit=50): array {
        $q=$this->db->getQueryBuilder();
        return $q->select('id','source_id')->from('dcb_event_links')
            ->where($q->expr()->eq('source_type',$q->createNamedParameter('deck')))
            ->andWhere($q->expr()->gt('id',$q->createNamedParameter($cursor,IQueryBuilder::PARAM_INT)))
            ->orderBy('id','ASC')->setMaxResults($limit)->executeQuery()->fetchAllAssociative();
    }
    public function status(?int $cardId=null): array {
        $q=$this->db->getQueryBuilder(); $q->select('*')->from('dcb_relations');
        if ($cardId!==null) $q->where($q->expr()->eq('card_id',$q->createNamedParameter($cardId,IQueryBuilder::PARAM_INT)));
        $rows=$q->orderBy('id','DESC')->setMaxResults(200)->executeQuery()->fetchAllAssociative();
        return array_map(function(array $r):array {
            $r=$this->decode($r); $r['last_error']=$r['data']['error']??null;
            $r['last_action']=$r['data']['attempt']['kind']??($r['data']['last_action']??$r['data']['deleted_cause']??null);
            $r['legacy_id']=$r['data']['legacy_id']??null;
            $r['confirmed']=$r['data']['base']??null;
            $r['object_id']=$r['data']['object_id']??null;
            unset($r['data']); return $r;
        },$rows);
    }
}
