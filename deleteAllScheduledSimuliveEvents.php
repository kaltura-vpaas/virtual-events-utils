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
use Kaltura\Client\Plugin\Schedule\Enum\LiveStreamScheduleEventOrderBy;
use Kaltura\Client\Plugin\Schedule\Enum\ScheduleEventOrderBy;
use Kaltura\Client\Plugin\Schedule\Enum\ScheduleEventStatus;
use Kaltura\Client\Plugin\Schedule\SchedulePlugin;
use Kaltura\Client\Plugin\Schedule\Type\LiveStreamScheduleEventFilter;
use Kaltura\Client\Plugin\Schedule\Type\ScheduleEventFilter;
use Kaltura\Client\Type\FilterPager;

class DeleteAllScheduledSimuliveEvents implements ILogger
{
    const START_DATE_TO_DELETE_FROM = 1622282400;
    const START_DATE_TO_DELETE_UNTIL = 1622433600;

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
        $filter = new LiveStreamScheduleEventFilter();
        $filter->orderBy = LiveStreamScheduleEventOrderBy::START_DATE_DESC;
        $filter->endDateGreaterThanOrEqual = self::START_DATE_TO_DELETE_FROM;
        $filter->endDateLessThanOrEqual = self::START_DATE_TO_DELETE_UNTIL;
        $pager = new FilterPager();
        $pager->pageIndex = 1;
        $pager->pageSize = 500;
        $scheduledEvents = $schedulePlugin->scheduleEvent->listAction($filter, $pager);
        echo $scheduledEvents->totalCount . PHP_EOL;
        foreach ($scheduledEvents->objects as $scheduledEvent) {
            $startDate = $this->convertDateTime($scheduledEvent->startDate);
            $endDate = $this->convertDateTime($scheduledEvent->endDate);
            //DELETE THE SCHEDULED EVENT:
            if ($scheduledEvent->templateEntryId == '1_6lfonjqi') {
                echo 'skipping the test simulive event' . PHP_EOL;
            } else {
                $schedulePlugin->scheduleEvent->cancel($scheduledEvent->id);
                $schedulePlugin->scheduleEvent->delete($scheduledEvent->id);
                $type = $scheduledEvent->getKalturaObjectType();
                $sourceId = isset($scheduledEvent->sourceEntryId) ? $scheduledEvent->sourceEntryId : 'no source';
                echo "{$type}: {$scheduledEvent->id}, {$scheduledEvent->templateEntryId}, {$sourceId}, {$startDate}, {$endDate}" . PHP_EOL;
            }
        }
    }
    private function convertDateTime($unixTime, $localTimeZone = 'America/Chicago')
    {
        $dt = new DateTime('@' . $unixTime);
        $dt->setTimezone(new DateTimeZone($localTimeZone));
        return $dt->format('Y-m-d H:i:s, e');
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
        $instance = new DeleteAllScheduledSimuliveEvents();
        $instance->run($pid, $configs['adminSecret']);
        unset($instance);
    }
}
$executionTime->end();
echo $executionTime;
