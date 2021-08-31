<?php
set_time_limit(0);
ini_set('memory_limit', '1024M');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
require './vendor/autoload.php';
require './config.php';
require './kalturaApiUtils.php';
require './executionTime.php';

use Kaltura\Client\Configuration as KalturaImportUtilsConfiguration;
use Kaltura\Client\Client as KalturaClient;
use Kaltura\Client\Enum\SessionType;
use Kaltura\Client\ILogger;
use Kaltura\Client\Plugin\Metadata\MetadataPlugin;
use Kaltura\Client\Plugin\Schedule\SchedulePlugin;

class ScheduleSimulive implements ILogger
{
    public function run($pid, $secret)
    {
        //Reset the log file:
        if (KalturaImportUtilsConfig::SHOULD_LOG) {
            $errline = "Here you'll find the log form the Kaltura Client library, in case issues occur you can use this file to investigate and report errors.";
            file_put_contents(KalturaImportUtilsConfig::ERROR_LOG_FILE, $errline);
        }

        //initialize kaltura
        $kConfig = new KalturaImportUtilsConfiguration($pid);
        $kConfig->setServiceUrl(KalturaImportUtilsConfig::KALTURA_ACCOUNTS[$pid]['serviceUrl']);
        $kConfig->setLogger($this);
        $this->client = new KalturaClient($kConfig);
        $privileges = 'all:*,list:*,disableentitlement';
        $this->ks = $this->client->session->start($secret, 'Admins', SessionType::ADMIN, $pid, KalturaImportUtilsConfig::KS_EXPIRY_TIME, $privileges);
        $this->client->setKs($this->ks);

        $metadataPlugin = MetadataPlugin::get($this->client);
        $schedulePlugin = SchedulePlugin::get($this->client);

        $simuliveAcp = KalturaApiUtils::createSimuliveAccessControlProfile($this->client);
        print "Simulive ACP: {$simuliveAcp->id}; {$simuliveAcp->name}<br />\n";
    }
    public function log($message)
    {
        if (KalturaImportUtilsConfig::SHOULD_LOG) {
            $errline = date('Y-m-d H:i:s') . ' ' .  $message . "<br />\n";
            file_put_contents(KalturaImportUtilsConfig::ERROR_LOG_FILE, $errline, FILE_APPEND);
        }
    }
}
$executionTime = new ExecutionTime();
$executionTime->start();
foreach (KalturaImportUtilsConfig::KALTURA_ACCOUNTS as $pid => $configs) {
    if ($configs['environment'] == 'prod') {
        $instance = new ScheduleSimulive();
        $instance->run($pid, $configs['adminSecret']);
        unset($instance);
    }
}
$executionTime->end();
echo $executionTime;
