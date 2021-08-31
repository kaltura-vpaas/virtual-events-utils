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
use Kaltura\Client\Enum\MediaType;
use Kaltura\Client\Enum\SessionType;
use Kaltura\Client\ILogger;
use Kaltura\Client\Plugin\Metadata\Enum\MetadataObjectType;
use Kaltura\Client\Plugin\Metadata\MetadataPlugin;
use Kaltura\Client\Plugin\Schedule\Enum\ScheduleEventRecurrenceType;
use Kaltura\Client\Plugin\Schedule\SchedulePlugin;
use Kaltura\Client\Plugin\Schedule\Type\LiveRedirectScheduleEvent;
use Kaltura\Client\Plugin\Schedule\Type\LiveRedirectScheduleEventFilter;
use Kaltura\Client\Plugin\Schedule\Type\LiveStreamScheduleEvent;
use Kaltura\Client\Plugin\Schedule\Type\LiveStreamScheduleEventFilter;
use Kaltura\Client\Type\LiveStreamEntryFilter;
use Kaltura\Client\Type\MediaEntryFilter;

class ScheduleSimulive implements ILogger
{
    /**
     * The Kaltura API Client facade
     *
     * @var Client
     */
    public $client = null;

    const SESSION_TYPE = 'Live'; //Simulive, Live, or MeetingRoom
    const DATE_FORMAT = 'Y-m-d';
    const TIME_FORMAT = 'h:i A';
    const INVALID_SESSIONCODE_ERROR = 'Invalid session reference ID provided';
    const INVALID_TIMEZONE = 'Invalid timezone provided. use valid PHP timezone from: https://www.php.net/manual/en/timezones.php';
    const INVALID_SESSION_DATE = 'Invalid session date provided, please use format: ' . self::DATE_FORMAT;
    const INVALID_SESSION_START_TIME = 'Invalid session START time provided, please use format: ' . self::TIME_FORMAT;
    const INVALID_SESSION_END_TIME = 'Invalid session END time provided, please use format: ' . self::TIME_FORMAT;
    const INVALID_KALTURA_SESSION = 'Invalid Kaltura Session provided';
    const INVALID_PRESTART_TIME_MINUTES = 'Invalid preStart time in minutes was given. Please set an integer representing minutes';
    const INVALID_POSTEND_TIME_MINUTES = 'Invalid postEnd time in minutes was given. Please set an integer representing minutes';

    private static function safeGetUrlParamString($paramName, $errorMsg = null)
    {
        if (!isset($_GET[$paramName])) {
            print $errorMsg . ' param: ' . $paramName;
            die();
        }
        $str = $_GET[$paramName];
        $newstr = filter_var($str, FILTER_SANITIZE_STRING);
        if (!isset($newstr) || $newstr == '' || $newstr == null) {
            print $errorMsg;
            die();
        }
        $newstr = urldecode($newstr);
        return $newstr;
    }

    private static function safeGetUrlParamInteger($paramName, $errorMsg = null)
    {
        if (!isset($_GET[$paramName])) {
            print $errorMsg . ' param: ' . $paramName;
            die();
        }
        $str = $_GET[$paramName];
        $newstr = filter_var($str, FILTER_SANITIZE_STRING);
        if (!isset($newstr) || $newstr == '' || $newstr == null) {
            print $errorMsg;
            die();
        }
        $newstr = urldecode($newstr);
        $intCasted = intval($newstr);
        return $intCasted;
    }

    private static function safeGetUrlParamDate($paramName, $errorMsg = null)
    {
        if (!isset($_GET[$paramName])) {
            print $errorMsg . ' param: ' . $paramName;
            die();
        }
        $str = $_GET[$paramName];
        $newstr = filter_var($str, FILTER_SANITIZE_STRING);
        if (!isset($newstr) || $newstr == '' || $newstr == null) {
            print $errorMsg . ' param: ' . $paramName;
            die();
        }
        $newstr = urldecode($newstr);
        $dateFormatted = date(self::DATE_FORMAT, strtotime($newstr));
        return $dateFormatted;
    }

    private static function safeGetUrlParamTime($paramName, $errorMsg = null)
    {
        if (!isset($_GET[$paramName])) {
            print $errorMsg . ' param: ' . $paramName;
            die();
        }
        $str = $_GET[$paramName];
        $newstr = filter_var($str, FILTER_SANITIZE_STRING);
        if (!isset($newstr) || $newstr == '' || $newstr == null) {
            print $errorMsg . ' param: ' . $paramName;
            die();
        }
        $newstr = urldecode($newstr);
        $timeFormatted = date(self::TIME_FORMAT, strtotime($newstr));
        return $timeFormatted;
    }

    public function run($pid, $secret)
    {
        $dateFormatStr = self::DATE_FORMAT . ' ' . self::TIME_FORMAT;
        $sessionCodeToSimuliveNow = self::safeGetUrlParamString('referenceId', self::INVALID_SESSIONCODE_ERROR); //'1612579532182001VnEq_CLONE';
        $localTimeZone =  self::safeGetUrlParamString('timezone', self::INVALID_TIMEZONE); //'America/New_York';
        date_default_timezone_set($localTimeZone); //make sure to set the expected timezone
        $dateFormatted = self::safeGetUrlParamDate('date', self::INVALID_SESSION_DATE); //2021-04-09
        $dateEndFormatted = null;
        if (isset($_GET['dateEnd'])) {
            $dateEndFormatted = self::safeGetUrlParamDate('dateEnd', self::INVALID_SESSION_DATE); //2021-04-09
        }
        $startTimeFormatted = self::safeGetUrlParamTime('startTime', self::INVALID_SESSION_START_TIME); //1:42 PM
        $endTimeFormatted = self::safeGetUrlParamTime('endTime', self::INVALID_SESSION_END_TIME); //1:52 PM
        $shouldCreateRedirect = (isset($_GET['noRedirect']) == false);

        // get preStart and postEnd. if none was given, values will be taken from the existing scheduleEvent or set to 0 if none exists
        $preStartSec = -1;
        if (isset($_GET['preStart'])) {
            $preStartSec = self::safeGetUrlParamInteger('preStart', self::INVALID_PRESTART_TIME_MINUTES); //5
            $preStartSec = ($preStartSec > 0) ? $preStartSec * 60 : $preStartSec;
        }
        $postEndSec = -1;
        if (isset($_GET['postEnd'])) {
            $postEndSec = self::safeGetUrlParamInteger('postEnd', self::INVALID_POSTEND_TIME_MINUTES); //15
            $postEndSec = ($postEndSec > 0) ? $postEndSec * 60 : $postEndSec;
        }

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
        $this->ks = self::safeGetUrlParamString('ks', self::INVALID_KALTURA_SESSION);
        //$privileges = 'all:*,list:*,disableentitlement';
        //$this->ks = $this->client->session->start($secret, 'Admins', SessionType::ADMIN, $pid, KalturaImportUtilsConfig::KS_EXPIRY_TIME, $privileges);
        $this->client->setKs($this->ks);

        $metadataPlugin = MetadataPlugin::get($this->client);
        $schedulePlugin = SchedulePlugin::get($this->client);

        $simuliveAccessControlProfileId = KalturaImportUtilsConfig::KALTURA_ACCOUNTS[$pid]['simuliveACP'];
        $simuliveAcp = KalturaApiUtils::createSimuliveAccessControlProfile($this->client, $simuliveAccessControlProfileId);
        //print "Simulive ACP: {$simuliveAcp->id}; {$simuliveAcp->name}<br />\n";

        $webcastConfigurationMetadataProfileId = KalturaApiUtils::getKalturaMetadataProfileId($metadataPlugin, KalturaApiUtils::WEBCAST_CONFIGURATION_METADATA);
        $webcastEventMetadataProfileId = KalturaApiUtils::getKalturaMetadataProfileId($metadataPlugin, KalturaApiUtils::WEBCAST_EVENT_METADATA);

        $filter = new MediaEntryFilter();
        $filter->mediaTypeEqual = MediaType::VIDEO;
        $filter->statusIn = "7,2,0,1,4"; //default media.list doesn't include DRAFT entries
        $currentVodEntry = KalturaApiUtils::getKalturaObjectByRefernceId($this->client->getMediaService(), $sessionCodeToSimuliveNow, $filter);

        $filter = new LiveStreamEntryFilter();
        $filter->mediaTypeEqual = MediaType::LIVE_STREAM_FLASH;
        $currentLiveEntry = KalturaApiUtils::getKalturaObjectByRefernceId($this->client->getLiveStreamService(), $sessionCodeToSimuliveNow, $filter);

        $currentRedirectScheduleEvent = false;
        if (isset($currentLiveEntry->id)) {
            $scheduleEventTemplateEntryId = $currentLiveEntry->id;
            $filter = new LiveStreamScheduleEventFilter();
            $filter->templateEntryIdEqual = $scheduleEventTemplateEntryId;
            $currentScheduleEvent = $schedulePlugin->scheduleEvent->listAction($filter);
            if ($currentScheduleEvent->totalCount > 0) {
                $currentScheduleEvent = $currentScheduleEvent->objects[0];
            } else {
                $currentScheduleEvent = false;
            }
            if (self::SESSION_TYPE == 'Simulive') {
                $filter = new LiveRedirectScheduleEventFilter();
                $filter->templateEntryIdEqual = $scheduleEventTemplateEntryId;
                $currentRedirectScheduleEvent = $schedulePlugin->scheduleEvent->listAction($filter);
                if ($currentRedirectScheduleEvent->totalCount > 0) {
                    $currentRedirectScheduleEvent = $currentRedirectScheduleEvent->objects[0];
                } else {
                    $currentRedirectScheduleEvent = false;
                }
            }
        }
        if ($currentScheduleEvent !== false) {
            if ($preStartSec === -1) {
                $preStartSec = $currentScheduleEvent->preStartTime;
            }
            if ($postEndSec === -1) {
                $postEndSec = $currentScheduleEvent->postEndTime;
            }
        }

        if (($currentVodEntry === false && self::SESSION_TYPE == "Simulive") || $currentLiveEntry === false) {
            print "Sorry, can't find " . self::SESSION_TYPE . " entries and scheduleEvent for session code: {$sessionCodeToSimuliveNow}<br />\n";
            die();
        }

        $redirectFoundMsg = '';
        if ($currentRedirectScheduleEvent === false) {
            print "Couldn't find an existing RedirectScheduleEvent<br />\n";
        } else {
            $redirectFoundMsg = " (redirect: {$currentRedirectScheduleEvent->id})";
        }
        $scheduleFoundMsg = '';
        if ($currentScheduleEvent === false) {
            print "Couldn't find an existing LiveStreamScheduleEvent<br />\n";
        } else {
            $scheduleFoundMsg = " on schedule: {$currentScheduleEvent->id}";
        }
        $msgtxt = "Scheduling " . self::SESSION_TYPE . " session for live: {$currentLiveEntry->id}";
        if (self::SESSION_TYPE == 'Simulive') {
            $msgtxt .= " (vod: {$currentVodEntry->id})";
        }
        $msgtxt .= ",{$scheduleFoundMsg}{$redirectFoundMsg}, on timezone: {$localTimeZone}.<br />\n";
        print $msgtxt;

        $sessionStartDateTimeFormatted = "{$dateFormatted} {$startTimeFormatted}";
        if ($dateEndFormatted != null) {
            $sessionEndDateTimeFormatted = "{$dateEndFormatted} {$endTimeFormatted}";
        } else {
            $sessionEndDateTimeFormatted = "{$dateFormatted} {$endTimeFormatted}";
        }

        print "Session times: {$sessionStartDateTimeFormatted} to {$sessionEndDateTimeFormatted}<br />\n";

        $eventStartDateTime = DateTime::createFromFormat($dateFormatStr, $sessionStartDateTimeFormatted, new DateTimeZone($localTimeZone));
        $eventEndDateTime = DateTime::createFromFormat($dateFormatStr, $sessionEndDateTimeFormatted, new DateTimeZone($localTimeZone));

        $redirectStartDateTime = clone $eventEndDateTime;
        $redirectStartDateTime = $redirectStartDateTime->modify("+1 minutes");
        $redirectEndDateTime = clone $redirectStartDateTime;
        $redirectEndDateTime = $redirectEndDateTime->modify("+23 months");

        $webcastConfigMetadata = KalturaApiUtils::createMetadataTemplateFromSchema($metadataPlugin, KalturaApiUtils::WEBCAST_CONFIGURATION_METADATA);
        $webcastConfigMetadata->IsKwebcastEntry = 1; // always set to 1 for webcast and simulive
        $webcastConfigMetadata->IsSelfServe = 0; // should we enable self-served broadcasting using webcam/audio without external encoder?
        $webcastConfigMetadataObj = KalturaApiUtils::upsertCustomMetadata($metadataPlugin, $webcastConfigurationMetadataProfileId, MetadataObjectType::ENTRY, $currentLiveEntry->id, $webcastConfigMetadata, KalturaApiUtils::WEBCAST_CONFIGURATION_METADATA);
        echo 'upserted Webcast Config metadata (' . $webcastConfigMetadataObj->id . ')' . '<br />' . PHP_EOL;

        $webcastEventMetadata = KalturaApiUtils::createMetadataTemplateFromSchema($metadataPlugin, KalturaApiUtils::WEBCAST_EVENT_METADATA);
        $webcastEventMetadata->Timezone = ($localTimeZone == 'Asia/Jerusalem') ? 'Asia/Jerusalem' : KalturaApiUtils::EVENT_WEBCAST_TIMEZONE_US_EASTERN;
        $webcastEventMetadata->StartTime = $eventStartDateTime->format('U'); // indicate when the webcast will begin in unix time
        $webcastEventMetadata->EndTime = $eventEndDateTime->format('U'); // indicate when the webcast will end in unix time
        $webcastEventMetadataObj = KalturaApiUtils::upsertCustomMetadata($metadataPlugin, $webcastEventMetadataProfileId, MetadataObjectType::ENTRY, $currentLiveEntry->id, $webcastEventMetadata, KalturaApiUtils::WEBCAST_EVENT_METADATA);
        echo 'upserted Webcast Event metadata (' . $webcastEventMetadataObj->id . ')' . '<br />' . PHP_EOL;

        $scheduleEvent = new LiveStreamScheduleEvent();
        if (self::SESSION_TYPE == 'Simulive') {
            $scheduleEvent->sourceEntryId = $currentVodEntry->id;
        }
        $scheduleEvent->startDate = $eventStartDateTime->format('U');
        $scheduleEvent->endDate = $eventEndDateTime->format('U');
        $scheduleEvent->recurrenceType = ScheduleEventRecurrenceType::NONE;
        $scheduleEvent->templateEntryId = $currentLiveEntry->id;
        $scheduleEvent->preStartTime = $preStartSec;
        $scheduleEvent->postEndTime = $postEndSec;
        $scheduleEvent->templateEntryId = $currentLiveEntry->id;
        if ($currentScheduleEvent === false) {
            $scheduleEvent->summary = $currentLiveEntry->referenceId;
            $scheduleEvent = $schedulePlugin->getScheduleEventService()->add($scheduleEvent);
        } else {
            $scheduleEvent = $schedulePlugin->getScheduleEventService()->update($currentScheduleEvent->id, $scheduleEvent);
        }

        if (self::SESSION_TYPE == 'Simulive' && $shouldCreateRedirect == true) {
            $scheduleRedirectEvent = new LiveRedirectScheduleEvent();
            $scheduleRedirectEvent->recurrenceType = ScheduleEventRecurrenceType::NONE;
            $scheduleRedirectEvent->redirectEntryId = $scheduleEvent->sourceEntryId;
            $scheduleRedirectEvent->sourceEntryId = $scheduleEvent->sourceEntryId;
            $scheduleRedirectEvent->templateEntryId = $currentLiveEntry->id;
            $scheduleRedirectEvent->startDate = $redirectStartDateTime->format('U');
            $scheduleRedirectEvent->endDate = $redirectEndDateTime->format('U');
            $scheduleRedirectEvent->preStartTime = $scheduleEvent->preStartTime;
            $scheduleRedirectEvent->postEndTime = $scheduleEvent->postEndTime;
            $scheduleRedirectEvent->summary = $scheduleEvent->summary;
            if ($currentRedirectScheduleEvent === false) {
                $scheduleRedirectEvent = $schedulePlugin->getScheduleEventService()->add($scheduleRedirectEvent);
            } else {
                $scheduleRedirectEvent = $schedulePlugin->getScheduleEventService()->update($currentRedirectScheduleEvent->id, $scheduleRedirectEvent);
            }
            print "redirect: {$scheduleRedirectEvent->id}, " . self::SESSION_TYPE . ": {$scheduleEvent->id}<br />\n";
        }

        print "{$sessionCodeToSimuliveNow} secheduled to start at {$sessionStartDateTimeFormatted} and end on {$sessionEndDateTimeFormatted}<br />\n";
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
    if ($configs['environment'] == $_GET['env']) {
        $instance = new ScheduleSimulive();
        $instance->run($pid, $configs['adminSecret']);
        unset($instance);
    }
}
$executionTime->end();
echo $executionTime;
