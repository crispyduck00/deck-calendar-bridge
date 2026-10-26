<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Server;

/** Also usable before app:enable; allocates no cards, events or background jobs. */
final class EnvironmentCheck {
    public function __construct(private IConfig $config) {}
    public function report(): array {
        $uid=function_exists('posix_geteuid')?posix_geteuid():null;
        $owner=fileowner(\OC::$configDir.'config.php');
        $nextcloud=implode('.',\OCP\Util::getVersion());
        $deck=$this->config->getAppValue('deck','installed_version','');
        $home=getenv('HOME')?:'.';
        $data=(string)$this->config->getSystemValue('datadirectory','');
        $log=(string)$this->config->getSystemValue('logfile',$data.'/nextcloud.log');
        $checks=['php_version_supported'=>PHP_VERSION_ID>=80300,
            'posix_extension_available'=>function_exists('posix_geteuid'),
            'nextcloud_version_supported'=>str_starts_with($nextcloud,'35.'),
            'deck_version_supported'=>version_compare($deck,'1.19.0','>=')&&version_compare($deck,'1.20.0','<'),
            'deck_app_enabled'=>$this->config->getAppValue('deck','enabled','no')!=='no',
            'dav_app_enabled'=>$this->config->getAppValue('dav','enabled','no')!=='no',
            'config_owner_matches_process'=>$uid!==null&&$uid===$owner,
            'config_readable'=>is_readable(\OC::$configDir.'config.php'),
            'data_directory_writable'=>is_dir($data)&&is_writable($data),
            'app_code_readable'=>is_readable(dirname(__DIR__,2).'/appinfo/info.xml'),
            'home_directory_readable'=>is_dir($home)&&is_readable($home)];
        $baseUrl='';
        try { $baseUrl=Server::get(\OCP\IURLGenerator::class)->getAbsoluteURL('/'); }
        catch (\Throwable) { /* Report the failed check below. */ }
        $checks['calendar_link_base_url_https']=strlen($baseUrl)<=2048
            && filter_var($baseUrl,FILTER_VALIDATE_URL)!==false
            && parse_url($baseUrl,PHP_URL_SCHEME)==='https'
            && parse_url($baseUrl,PHP_URL_USER)===null && parse_url($baseUrl,PHP_URL_PASS)===null;
        if ($this->config->getSystemValue('log_type','file')==='file') {
            $checks['nextcloud_log_writable']=is_file($log)?is_writable($log):is_writable(dirname($log));
        }
        $idDiagnostics=[];
        try {
            $temp=Server::get(\OCP\ITempManager::class)->getTempBaseDir();
            $sequence=$temp.'/sfi_file_sequence_'.$this->config->getSystemValue('instanceid','');
            $idDiagnostics=['temp_directory'=>$temp,'snowflake_directory'=>$sequence];
            $bad=[];
            if (is_dir($sequence) && is_readable($sequence)) {
                foreach (glob($sequence.'/seq-*.lock')?:[] as $file) if (!is_writable($file)) $bad[]=basename($file);
            }
            $idDiagnostics['unwritable_sequence_files']=$bad;
            if (PHP_SAPI==='cli' && is_dir($sequence) && (!is_writable($sequence)||$bad!==[])) {
                throw new \RuntimeException('Nextcloud ID sequence directory/files are not writable by this process');
            }
            // The native worker allocates a core history ID before app code.
            Server::get(\OCP\Snowflake\ISnowflakeGenerator::class)->nextId();
            $checks['nextcloud_id_generation_works']=true;
        } catch (\Throwable $e) {
            $checks['nextcloud_id_generation_works']=false;
            $idDiagnostics['id_generation_error']=$e->getMessage();
        }
        if (isset($sequence)) $idDiagnostics['snowflake_owner_uid']=is_dir($sequence)?fileowner($sequence):null;
        $key='deckcalendarbridge/environment-probe/'.bin2hex(random_bytes(8)); $acquired=false; $locks=null;
        try {
            $locks=Server::get(ILockingProvider::class);
            $locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE); $acquired=true;
            $checks['locking_backend_works']=true;
        } catch (\Throwable) { $checks['locking_backend_works']=false;
        } finally { if ($acquired) $locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE); }
        return ['version'=>$this->config->getAppValue('deckcalendarbridge','installed_version',''),
            'nextcloud'=>$nextcloud,'deck'=>$deck,'php'=>PHP_VERSION,
            'process_uid'=>$uid,'process_user'=>$uid===null?'unavailable':(posix_getpwuid($uid)['name']??(string)$uid),
            'config_owner_uid'=>$owner,'home_directory_readable'=>$checks['home_directory_readable'],
            'calendar_link_base_url'=>$baseUrl,
            'locking_backend'=>$locks===null?'unavailable':get_class($locks),
            'checks'=>$checks,'id_generation'=>$idDiagnostics,'ok'=>!in_array(false,$checks,true)];
    }
}
