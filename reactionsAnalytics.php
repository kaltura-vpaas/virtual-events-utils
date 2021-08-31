<?php
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
require './vendor/autoload.php';
require './config.php';
require './kalturaApiUtils.php';
require './executionTime.php';

use Kaltura\Client\Configuration as KalturaImportUtilsConfiguration;
use Kaltura\Client\Client as KalturaClient;
use Kaltura\Client\Enum\ReportInterval;
use Kaltura\Client\Enum\ReportType;
use Kaltura\Client\Enum\SessionType;
use Kaltura\Client\ILogger;
use Kaltura\Client\Type\EndUserReportInputFilter;
use Kaltura\Client\Type\FilterPager;
use Kaltura\Client\Type\ReportResponseOptions;

class ScheduleSimuliveNow implements ILogger
{
    public $client = null;

    public function run($pid, $secret)
    {
        $localTimeZoneStr =  'Asia/Jerusalem';
        date_default_timezone_set($localTimeZoneStr); //make sure to set the expected timezone
        $dateTimeFormat = 'd/m/Y H:i:s O';

        //Reset the log file:
        if (KalturaImportUtilsConfig::SHOULD_LOG) {
            $errline = "Here you'll find the log form the Kaltura Client library, in case issues occur you can use this file to investigate and report errors.";
            file_put_contents(KalturaImportUtilsConfig::ERROR_LOG_FILE, $errline);
        }

        $intervalToLoop = '30 seconds';
        $entryId = 'yourvideoID'; //https://kmc.kaltura.com/index.php/kmcng/
        $startTimeStr = '12/04/2021 16:29:00 +0200';
        $endTimeStr = '12/04/2021 20:20:00 +0200';

        $kConfig = new KalturaImportUtilsConfiguration($pid);
        $kConfig->setServiceUrl(KalturaImportUtilsConfig::KALTURA_ACCOUNTS[$pid]['serviceUrl']);
        $kConfig->setLogger($this);
        $this->client = new KalturaClient($kConfig);
        $privileges = 'all:*,list:*,disableentitlement';
        $this->ks = $this->client->session->start($secret, 'Admins', SessionType::ADMIN, $pid, KalturaImportUtilsConfig::KS_EXPIRY_TIME, $privileges);
        $this->client->setKs($this->ks);

        $reportType = ReportType::REACTIONS_BREAKDOWN_WEBCAST;
        $reportInputFilter = new EndUserReportInputFilter();
        $reportInputFilter->searchInTags = true;
        $reportInputFilter->searchInAdminTags = false;
        $reportInputFilter->timeZoneOffset = -120;
        $reportInputFilter->interval = ReportInterval::TEN_SECONDS;
        $reportInputFilter->entryIdIn = $entryId;
        $pager = new FilterPager();
        $pager->pageIndex = 1;
        $pager->pageSize = 25;
        $order = "-date_id";
        $responseOptions = new ReportResponseOptions();
        $responseOptions->delimiter = "|";
        $responseOptions->skipEmptyDates = false;

        $sessionStart = DateTime::createFromFormat($dateTimeFormat, $startTimeStr);
        $sessionEnd = DateTime::createFromFormat($dateTimeFormat, $endTimeStr);
        $interval = DateInterval::createFromDateString($intervalToLoop);
        $period = new DatePeriod($sessionStart, $interval, $sessionEnd, DatePeriod::EXCLUDE_START_DATE);

        $mask = "%-15s %-15s \n";
        $a = 0;
        foreach ($period as $dt) {
            $a++;
            $reportInputFilter->fromDate = $sessionStart->format('U');
            $startDt = clone $dt;
            $startDt->modify('-' . $intervalToLoop);
            $reportInputFilter->fromDate = $startDt->format('U');
            $reportInputFilter->toDate = $dt->format('U');
            $reportResult = $this->client->getReportService()->getTable($reportType, $reportInputFilter, $pager, $order, $entryId, $responseOptions);

            $reactionsData = explode(';', $reportResult->data);
            if (count($reactionsData) > 1) {
                echo $a . ') ' . $startDt->format('M d, Y H:i:s e') . ' to ' . $dt->format('M d, Y H:i:s e') . PHP_EOL;
                printf($mask, "\033[32mReaction", "Clicks\033[39m");
                foreach ($reactionsData as $reactionData) {
                    $reaction = explode('|', $reactionData);
                    if (trim($reaction[0]) != '') {
                        printf($mask, trim($reaction[0]), trim($reaction[1]));
                    }
                }
            } else {
                echo '.' . PHP_EOL;
            }
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
    if ($configs['environment'] == 'prod') {
        $instance = new ScheduleSimuliveNow();
        $instance->run($pid, $configs['adminSecret']);
        unset($instance);
    }
}
$executionTime->end();
//echo $executionTime;
