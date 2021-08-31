<?php
set_time_limit(0);
ini_set('memory_limit', '1024M');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
date_default_timezone_set('America/Chicago'); //make sure to set the expected timezone
require './vendor/autoload.php';
require './config.php';
require './kalturaApiUtils.php';
require './executionTime.php';

use Kaltura\Client\Configuration as KalturaConfiguration;
use Kaltura\Client\Client as KalturaClient;
use Kaltura\Client\Enum\SessionType;
use Kaltura\Client\ILogger;
use Kaltura\Client\Plugin\Schedule\Enum\ScheduleEventRecurrenceType;
use Kaltura\Client\Plugin\Schedule\SchedulePlugin;
use Kaltura\Client\Plugin\Schedule\Type\LiveRedirectScheduleEvent;

class SyncCsvSessions implements ILogger
{
    public $client = null;
    public function run($pid, $secret)
    {
        if (KalturaImportUtilsConfig::SHOULD_LOG) {
            $errline = "Here you'll find the log form the Kaltura Client library, in case issues occur you can use this file to investigate and report errors.";
            file_put_contents(KalturaImportUtilsConfig::ERROR_LOG_FILE, $errline);
        }
        //initialize kaltura
        $kConfig = new KalturaConfiguration($pid);
        $kConfig->setServiceUrl(KalturaImportUtilsConfig::KALTURA_ACCOUNTS[$pid]['serviceUrl']);
        $kConfig->setLogger($this);
        $this->client = new KalturaClient($kConfig);
        $privileges = 'all:*,list:*,disableentitlement';
        $this->ks = $this->client->session->start($secret, 'Admins', SessionType::ADMIN, $pid, KalturaImportUtilsConfig::KS_EXPIRY_TIME, $privileges);
        $this->client->setKs($this->ks);
        $schedulePlugin = SchedulePlugin::get($this->client);

        $sessionsList = json_decode(file_get_contents('sessions.json'));
        echo 'total sessions to schedule: ' . count($sessionsList) . PHP_EOL;
        $newSessions = array();

        // update existing events -
        $i = 1;
        foreach ($sessionsList as $session) {
            $sourceVODEntryId = $session->body;
            $templateLiveEntryId = $session->location;
            $eventId = $session->id;
            $summary = KalturaApiUtils::cleanString($session->title);
            $scheduleRedirectEvent = new LiveRedirectScheduleEvent();
            $scheduleRedirectEvent->recurrenceType = ScheduleEventRecurrenceType::NONE;
            $scheduleRedirectEvent->redirectEntryId = $sourceVODEntryId;
            $scheduleRedirectEvent->sourceEntryId = $sourceVODEntryId;
            $scheduleRedirectEvent->templateEntryId = $templateLiveEntryId;
            $scheduleRedirectEvent->startDate = 1622815200;
            $scheduleRedirectEvent->endDate = 1685800800;
            $scheduleRedirectEvent->preStartTime = 300;
            $scheduleRedirectEvent->postEndTime = 0;
            $scheduleRedirectEvent->summary = $summary;
            $currentRedirectScheduleEvent = $schedulePlugin->getScheduleEventService()->update($eventId, $scheduleRedirectEvent);
            echo "{$i}) Event: {$eventId}, Live: {$templateLiveEntryId}, VOD Source: {$sourceVODEntryId}, $summary \n";
            ++$i;
        }

        die();

        // create new - 
        $i = 1;
        foreach ($sessionsList as $session) {
            $sourceVODEntryId = $session->body;
            $templateLiveEntryId = $session->location;
            $summary = KalturaApiUtils::cleanString($session->title);
            $scheduleRedirectEvent = new LiveRedirectScheduleEvent();
            $scheduleRedirectEvent->recurrenceType = ScheduleEventRecurrenceType::NONE;
            $scheduleRedirectEvent->redirectEntryId = $sourceVODEntryId;
            $scheduleRedirectEvent->sourceEntryId = $sourceVODEntryId;
            $scheduleRedirectEvent->templateEntryId = $templateLiveEntryId;
            $scheduleRedirectEvent->startDate = 1622815200;
            $scheduleRedirectEvent->endDate = 1685800800;
            $scheduleRedirectEvent->preStartTime = 0;
            $scheduleRedirectEvent->postEndTime = 0;
            $scheduleRedirectEvent->summary = $summary;
            $currentRedirectScheduleEvent = $schedulePlugin->getScheduleEventService()->add($scheduleRedirectEvent);
            echo "{$i}) Event: {$currentRedirectScheduleEvent->id}, Live: {$templateLiveEntryId}, VOD Source: {$sourceVODEntryId}, $summary \n";
            ++$i;
        }
    }
    public function log($message)
    {
        if (KalturaImportUtilsConfig::SHOULD_LOG) {
            $errline = date('Y-m-d H:i:s') . ' ' .  $message . "\n";
            file_put_contents(KalturaImportUtilsConfig::ERROR_LOG_FILE, $errline, FILE_APPEND);
        }
    }
}
$executionTime = new ExecutionTime();
$executionTime->start();
foreach (KalturaImportUtilsConfig::KALTURA_ACCOUNTS as $pid => $configs) {
    if ($configs['environment'] == KalturaImportUtilsConfig::ENVIRONMENT_NAME) {
        $instance = new SyncCsvSessions();
        $instance->run($pid, $configs['adminSecret']);
        unset($instance);
    }
}
$executionTime->end();
echo $executionTime;
