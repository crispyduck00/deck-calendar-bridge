<?php
declare(strict_types=1);

// Prints a unit only. Does not bootstrap Nextcloud, modify files or start jobs.
if (PHP_SAPI!=='cli') { http_response_code(404); exit(1); }
try {
    if (!function_exists('posix_getpwuid')) throw new RuntimeException('PHP CLI needs the POSIX extension');
    $root=realpath($argv[1]??'');
    $php=realpath($argv[2]??PHP_BINARY);
    if ($root===false || !is_file($root.'/config/config.php') || !is_file($root.'/occ')) {
        throw new RuntimeException('Pass the installed Nextcloud root, then optionally the absolute PHP CLI path');
    }
    if ($php===false || !is_executable($php)) throw new RuntimeException('PHP CLI path is not executable');
    if (preg_match('/[\x00-\x1f\x7f]/',$root.$php) || str_ends_with($root,' ') || str_ends_with($root,'\\')) {
        throw new RuntimeException('Paths must not contain control characters or end in whitespace/backslash');
    }
    $uid=fileowner($root.'/config/config.php'); $gid=filegroup($root.'/config/config.php');
    $user=posix_getpwuid($uid); $group=posix_getgrgid($gid);
    if ($uid===0 || $user===false || $group===false) {
        throw new RuntimeException('config/config.php must belong to a resolvable, non-root web account');
    }
    $quote=static function(string $value,bool $command=false): string {
        $value=str_replace('%','%%',$value);
        if ($command) $value=str_replace('$','$$',$value);
        return '"'.str_replace(['\\','"'],['\\\\','\\"'],$value).'"';
    };
    // Single-value path directives do not strip shell-style quotes. ExecStart
    // and Environment do, so only those use the word-quoting helper above.
    $condition=str_replace('%','%%',$root.'/custom_apps/deckcalendarbridge/appinfo/info.xml');
    $working=str_replace('%','%%',$root); $home=$quote('HOME='.$root);
    $command=$quote($php,true).' '.$quote($root.'/occ',true)
        .' background-job:worker --interval=1 --stop_after=15m '
        .$quote('OCA\DeckCalendarBridge\BackgroundJob\ReconcileCardJob',true).' '
        .$quote('OCA\DeckCalendarBridge\BackgroundJob\SyncScanJob',true);
    echo "[Unit]\nDescription=Nextcloud Deck Calendar Bridge worker\nAfter=network.target\nConditionPathExists=$condition\n\n"
        ."[Service]\nType=simple\nUser=".$user['name']."\nGroup=".$group['name']."\nWorkingDirectory=$working\nEnvironment=$home\n"
        ."ExecStart=$command\nRestart=always\nRestartSec=2\nTimeoutStopSec=120\n\n[Install]\nWantedBy=multi-user.target\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
