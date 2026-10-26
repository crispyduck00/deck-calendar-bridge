<?php
const OC_CONSOLE=1;
$root=$argv[1]??'';
if (!is_file(dirname($root).'/.dcb-disposable')) throw new RuntimeException('Disposable test marker required');
require $root.'/lib/base.php';
\OC_App::loadApps();
$users=\OCP\Server::get(\OCP\IUserManager::class);
foreach (['bridge-editor','bridge-owner','bridge-other'] as $uid) {
    $u=$users->get($uid)?:$users->createUser($uid,'bridge-disposable-test-42');
    $u->setEMailAddress($uid.'@example.invalid');
}
