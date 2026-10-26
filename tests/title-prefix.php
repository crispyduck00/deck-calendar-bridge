<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/Service/EventTitlePolicy.php';
require_once __DIR__.'/../lib/Service/RuleValidator.php';
use OCA\DeckCalendarBridge\Service\EventTitlePolicy;
use OCA\DeckCalendarBridge\Service\RuleValidator;
$p = new EventTitlePolicy();
function verifyTitle(bool $v, string $m): void {if (!$v) throw new RuntimeException($m); echo "PASS $m\n";}
function refusesTitle(callable $action,string $message): void {
    try { $action(); } catch (InvalidArgumentException) { verifyTitle(true,$message); return; }
    throw new RuntimeException($message);
}
verifyTitle($p->render('Collect item','')==='Collect item','empty legacy prefix is identity');
verifyTitle($p->render('Collect item','⬇ Collection · ')==='⬇ Collection · Collect item','legacy prefix is exact');
verifyTitle($p->reverse('⬇ Collection · Deliver item','⬇ Collection · ')==='Deliver item','legacy reverse strips exact prefix');
verifyTitle($p->reverse('Deliver item','⬇ Collection · ')===null,'missing legacy prefix is conflict');
verifyTitle($p->reverse('⬆ Delivery · Item','⬇ Collection · ')===null,'other legacy prefix is conflict');
verifyTitle($p->reverse('⬇ Collection · ','⬇ Collection · ')===null,'empty resulting title refused');
refusesTitle(fn()=>$p->validate("prefix\n"),'prefix control characters refused');

$r=['id'=>'delivery','enabled'=>true,'source'=>['type'=>'deck','boardIds'=>[1],'labelIds'=>[2]],'target'=>['mode'=>'fixed','principal'=>'principals/users/usera','calendarUri'=>'personal'],'presentation'=>['titlePrefix'=>'⬇ Collection · ','categories'=>['Collection','Item']]];
$validator=new RuleValidator(); $n=$validator->validate([$r]);
verifyTitle($n[0]['presentation']['titlePrefix']==='⬇ Collection · '
    &&$n[0]['presentation']['titlePrefixMode']==='custom'&&$n[0]['presentation']['titleSeparator']==='',
    'legacy prefix normalizes without adding punctuation or spaces');
verifyTitle($p->renderForRule('Collect item',$n[0]['presentation'])==='⬇ Collection · Collect item','normalized legacy output is unchanged');
verifyTitle($validator->validate($n)===$n,'normalized title settings are idempotent');
verifyTitle($n[0]['presentation']['categories']===['Collection','Item'],'rule categories normalized');
unset($r['presentation']);
$empty=$validator->validate([$r])[0]['presentation'];
verifyTitle($empty['titlePrefixMode']==='none'&&$empty['titlePrefix']===''&&$empty['titleSeparator']===''&&$empty['categories']===[],
    'old rules without presentation keep plain titles');

$render=fn(array $settings,?string $list=null)=>$p->renderForRule('Collect item',$settings,$list);
verifyTitle($render(['titlePrefixMode'=>'none','titlePrefix'=>'Unused','titleSeparator'=>' - '],'Ready')==='Collect item','none ignores stored text and separator');
verifyTitle($render(['titlePrefixMode'=>'custom','titlePrefix'=>'Collection'])==='Collection - Collect item','new explicit custom mode defaults to a spaced dash');
verifyTitle($render(['titlePrefixMode'=>'custom','titlePrefix'=>'Collection','titleSeparator'=>': '])==='Collection: Collect item','custom separator is literal');
verifyTitle($render(['titlePrefixMode'=>'custom','titlePrefix'=>'Collection','titleSeparator'=>''])==='CollectionCollect item','empty separator joins directly');
verifyTitle($render(['titlePrefixMode'=>'custom','titlePrefix'=>'','titleSeparator'=>' - '])==='Collect item','empty custom text does not leave a separator');
verifyTitle($render(['titlePrefixMode'=>'list','titlePrefix'=>'Unused','titleSeparator'=>' - '],'Ready')==='Ready - Collect item','list mode uses current column instead of stored custom text');
verifyTitle($render(['titlePrefixMode'=>'list','titleSeparator'=>' · '],'Prüfung')==='Prüfung · Collect item','Unicode list names and separators retained');
verifyTitle($render(['titlePrefixMode'=>'list','titleSeparator'=>' - '],'  ')==='Collect item','empty list name leaves no dangling separator');
verifyTitle($render(['titlePrefixMode'=>'list','titleSeparator'=>''],str_repeat('L',121))===str_repeat('L',121).'Collect item','list names are not limited by the custom-text limit');
refusesTitle(fn()=>$render(['titlePrefixMode'=>'list']),'missing list identity refuses a guessed title');
refusesTitle(fn()=>$render(['titlePrefixMode'=>'list'],"List\nInjected"),'list control characters refused');
refusesTitle(fn()=>$render(['titlePrefixMode'=>'list'],"\xff"),'invalid Unicode list name refused');
refusesTitle(fn()=>$p->renderForRule(str_repeat('T',1020),['titlePrefixMode'=>'custom','titlePrefix'=>'Long','titleSeparator'=>' - ']),'combined title length limit enforced');

foreach ([['titlePrefixMode'=>'board'],['titlePrefixMode'=>null],['titlePrefixMode'=>[]],
    ['titleSeparator'=>"\n"],['titleSeparator'=>str_repeat('x',33)],['titleSeparator'=>"\xff"],['titleSeparator'=>null],['titlePrefix'=>str_repeat('x',121)]] as $invalid) {
    $bad=$r; $bad['presentation']=$invalid;
    refusesTitle(fn()=>$validator->validate([$bad]),'invalid title setting refused: '.json_encode(array_keys($invalid)));
}
$r['presentation']=['titlePrefixMode'=>'list','titlePrefix'=>'Retained','titleSeparator'=>'  /  '];
$normalized=$validator->validate([$r])[0]['presentation'];
verifyTitle($normalized['titleSeparator']==='  /  '&&$render($normalized,'Ready')==='Ready  /  Collect item','save normalization preserves exact separator spaces');
