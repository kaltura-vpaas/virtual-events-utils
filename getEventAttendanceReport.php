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

use Box\Spout\Writer\Common\Creator\Style\StyleBuilder;
use Box\Spout\Writer\Common\Creator\WriterEntityFactory;
use Kaltura\Client\Configuration as KalturaConfiguration;
use Kaltura\Client\Client as KalturaClient;
use Kaltura\Client\Enum\ReportInterval;
use Kaltura\Client\Enum\SessionType;
use Kaltura\Client\ILogger;
use Kaltura\Client\Plugin\CuePoint\CuePointPlugin;
use Kaltura\Client\Plugin\Schedule\Enum\LiveStreamScheduleEventOrderBy;
use Kaltura\Client\Plugin\Schedule\Enum\ScheduleEventStatus;
use Kaltura\Client\Plugin\Schedule\SchedulePlugin;
use Kaltura\Client\Plugin\Schedule\Type\LiveStreamScheduleEventFilter;
use Kaltura\Client\Type\EndUserReportInputFilter;
use Kaltura\Client\Type\FilterPager;
use Kaltura\Client\Type\ReportResponseOptions;

class SyncCsvSessions implements ILogger
{
    public $client = null;
    const EVENT_START_UNIX_TIME = 1622332800; //unix time for when the event started
    const EVENT_END_UNIX_TIME = 1622840400; //unix time for when the event endded
    const EVENT_REPORT_FILE_NAME = 'liveEntriesEngagementReport.xlsx';
    private $excelColumnHeaderFormats = array(
        'entryId' => ['prettyName' => 'Entry ID', 'defaultVal' => '', 'fieldType' => ''],
        'sessionCode' => ['prettyName' => 'Session Code', 'defaultVal' => '', 'fieldType' => ''],
        'userId' => ['prettyName' => 'User ID', 'defaultVal' => '', 'fieldType' => ''],
        'plays' => ['prettyName' => 'Plays', 'defaultVal' => '', 'fieldType' => ''],
        'minViewed' => ['prettyName' => 'Minutes Viewed', 'defaultVal' => '', 'fieldType' => ''],
        'bufferRatio' => ['prettyName' => 'Buffering Ratio', 'defaultVal' => '', 'fieldType' => ''],
        'engagementRatio' => ['prettyName' => 'Engaged Viewing Ratio', 'defaultVal' => '', 'fieldType' => '']
    );

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
        $cuePointPlugin = CuePointPlugin::get($this->client);

        $sessionsList = json_decode(file_get_contents('sessions.json'));
        echo 'getting engagement report for ' . count($sessionsList) . ' live entries.' . PHP_EOL;
        $sessionsArr = array();

        $i = 1;
        $entryIds = '';
        foreach ($sessionsList as $session) {
            $sourceVODEntryId = $session->body;
            $templateLiveEntryId = $session->location;
            $eventId = $session->id;
            $summary = KalturaApiUtils::cleanString($session->title);
            $sessionCode = trim(explode(' - ', $summary)[0]);
            if (!isset($sessionsArr[$templateLiveEntryId])) {
                $sessionsArr[$templateLiveEntryId] = array();
            }
            $sessionsArr[$templateLiveEntryId]['vod'] = $sourceVODEntryId;
            $sessionsArr[$templateLiveEntryId]['title'] = $summary;
            $sessionsArr[$templateLiveEntryId]['code'] = $sessionCode;
            $sessionsArr[$templateLiveEntryId]['eventId'] = $eventId;
            //echo "{$i}) Event: {$eventId}, Live: {$templateLiveEntryId}, VOD Source: {$sourceVODEntryId}, $summary \n";
            ++$i;
            $entryIds .= $templateLiveEntryId . ',';
        }

        $reportType = 40009;
        $reportInputFilter = new EndUserReportInputFilter();
        $reportInputFilter->searchInAdminTags = false;
        $reportInputFilter->searchInTags = false;
        $reportInputFilter->interval = ReportInterval::DAYS;
        $reportInputFilter->fromDate = self::EVENT_START_UNIX_TIME;
        if (self::EVENT_END_UNIX_TIME > -1) $reportInputFilter->toDate = self::EVENT_END_UNIX_TIME;
        $reportInputFilter->playbackTypeIn = "live";
        //$reportInputFilter->categoriesAncestorIdIn = "175766822"; //specific category
        //$reportInputFilter->userIds = $userIdsIn; // specific list of user IDs
        $order = "-count_plays";
        $objectIds = "";
        $responseOptions = new ReportResponseOptions();
        $responseOptions->skipEmptyDates = false;
        $responseOptions->delimiter = "|";
        $pager = new FilterPager();
        $pager->pageSize = 500;

        $schedulePlugin = SchedulePlugin::get($this->client);
        $sfilter = new LiveStreamScheduleEventFilter();
        $sfilter->orderBy = LiveStreamScheduleEventOrderBy::START_DATE_ASC;
        $sfilter->startDateGreaterThanOrEqual = self::EVENT_START_UNIX_TIME;
        $sfilter->endDateLessThanOrEqual = self::EVENT_END_UNIX_TIME;
        $sfilter->statusEqual = ScheduleEventStatus::ACTIVE;
        $spager = new FilterPager();
        $spager->pageIndex = 1;
        $spager->pageSize = 1;

        $allEntriesReports = array();
        $currentEntryCount = 0;
        foreach ($sessionsArr as $liveEntryId => $sessionData) {
            ++$currentEntryCount;
            if ($liveEntryId == null || $liveEntryId == '') continue;
            $sfilter->templateEntryIdEqual = $liveEntryId;
            $scheduledEvents = $schedulePlugin->scheduleEvent->listAction($sfilter, $spager);
            if (!isset($scheduledEvents->objects[0])) {
                echo 'this live entry was not simulive broadcasted' . PHP_EOL;
                die();
            }
            $scheduleEvent = $scheduledEvents->objects[0];
            $entryDuration = $scheduleEvent->endDate - $scheduleEvent->startDate;
            $entryDuration = bcdiv($entryDuration / 60, 1, 2);
            $sessionCode = trim($sessionData['code']);
            echo $currentEntryCount . ') entry: ' . $liveEntryId . '[' . $scheduleEvent->id . ']' . ', session: ' . $sessionCode . ', duration: ' . $entryDuration . ' minutes' . PHP_EOL;

            $reportInputFilter->entryIdIn = $liveEntryId;
            $pager->pageIndex = 1;
            $report = KalturaApiUtils::presistantApiRequest($this, $this->client->getReportService(), 'getTable', array($reportType, $reportInputFilter, $pager, $order, $objectIds, $responseOptions), 5);

            while ($report->data != null) {
                $reportdata = explode(";", $report->data);
                foreach ($reportdata as $line) {
                    $line = trim($line);
                    if ($line == '') continue;
                    $tempLineArr = explode('|', $line);
                    $userId = $tempLineArr[0];
                    $totalPlays = intval($tempLineArr[4]);
                    $minutesViewed = floatval($tempLineArr[6]);
                    $minutesViewed = ($entryDuration < $minutesViewed) ? $entryDuration : $minutesViewed;
                    $bufferRatio = floatval($tempLineArr[7]);
                    $engagedRatio = floatval($tempLineArr[9]);
                    array_push($allEntriesReports, array($liveEntryId, $sessionCode, $userId, $totalPlays, $minutesViewed, $bufferRatio, $engagedRatio));
                }
                $pager->pageIndex += 1;
                $report = KalturaApiUtils::presistantApiRequest($this, $this->client->getReportService(), 'getTable', array($reportType, $reportInputFilter, $pager, $order, $objectIds, $responseOptions), 5);
            }
        }

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile(self::EVENT_REPORT_FILE_NAME);
        //header:
        $style = (new StyleBuilder())
            ->setFontBold()
            ->build();
        $headerArr = array();
        foreach ($this->excelColumnHeaderFormats as $columnName => $columnSettings) {
            $headerCell = WriterEntityFactory::createCell($columnSettings['prettyName']);
            array_push($headerArr, $headerCell);
        }
        $headerRow = WriterEntityFactory::createRow($headerArr, $style);
        $writer->addRow($headerRow);

        //data:
        foreach ($allEntriesReports as $entryReport) {
            $rowArr = array();
            foreach ($entryReport as $userprofile_value) {
                $rowArr[] = WriterEntityFactory::createCell($userprofile_value);
            }
            $bodyRow = WriterEntityFactory::createRow($rowArr);
            $writer->addRow($bodyRow);
        }

        $writer->close();
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
