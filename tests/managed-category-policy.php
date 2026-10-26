<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/Service/ManagedCategoryPolicy.php';
use OCA\DeckCalendarBridge\Service\ManagedCategoryPolicy;
$p=new ManagedCategoryPolicy();
function catCheck(bool $ok,string $name):void {
    if (!$ok) throw new RuntimeException($name);
    echo "PASS $name\n";
}
$r=$p->reconcile(['Anlieferung','Privat'],['Reparatur'],['Anlieferung']);
catCheck($r['categories']===['Privat','Reparatur'] && $r['owned']===['Reparatur'],
    'old bridge category removed, user category preserved');
$r=$p->reconcile(['Manuell'],['Manuell','Bridge'],null);
catCheck($r['categories']===['Manuell','Bridge'] && $r['owned']===['Bridge'],
    'legacy event does not steal ownership of existing label');
$r=$p->reconcile(['Bridge','User'],['Bridge'],['Bridge']);
catCheck($r['categories']===['Bridge','User'] && $r['owned']===['Bridge'],
    'unchanged managed category retained');
$r=$p->reconcile(['Unrelated','Unrelated'],[],[]);
catCheck($r['categories']===['Unrelated','Unrelated'],
    'calendar-owned duplicates are preserved');
try {
    $p->reconcile(['Owned','Owned'],[],['Owned']);
    throw new RuntimeException('ambiguous duplicate deleted');
} catch (DomainException $e) {
    catCheck(true,'duplicate managed label requires review');
}
try {
    $p->reconcile(['Safe'],['Bad'.chr(10).'Header'],[]);
    throw new RuntimeException('malformed category accepted');
} catch (DomainException $e) {
    catCheck(true,'unsafe category rejected');
}
echo "Managed category ownership checks passed.\n";
