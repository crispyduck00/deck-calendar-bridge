<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit(1); }
if (PHP_VERSION_ID<80300 || !function_exists('posix_geteuid')) {
    fwrite(STDERR,"Use PHP CLI 8.3 or later with the POSIX extension, matching the supported Nextcloud installation\n"); exit(1);
}
$root=realpath($argv[1]??'');
if ($root===false || !is_file($root.'/config/config.php') || !is_file($root.'/lib/base.php')) {
    fwrite(STDERR,"Pass the installed Nextcloud root directory\n"); exit(1);
}
if (posix_geteuid()!==fileowner($root.'/config/config.php')) {
    fwrite(STDERR,"Run as the owner of Nextcloud config/config.php (normally sudo -u www-data)\n"); exit(1);
}
try {
    chdir($root);
    define('OC_CONSOLE',1);
    require $root.'/lib/base.php';
    require_once dirname(__DIR__).'/lib/Sync/EnvironmentCheck.php';
    $result=(new \OCA\DeckCalendarBridge\Sync\EnvironmentCheck(\OCP\Server::get(\OCP\IConfig::class)))->report();
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit($result['ok']?0:1);
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
