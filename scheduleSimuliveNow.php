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

class ScheduleSimuliveNow implements ILogger
{
    /**
     * The Kaltura API Client facade
     *
     * @var Client
     */
    public $client = null;

    const SESSION_TYPE = 'Simulive'; //Simulive, Live, or MeetingRoom

    public function run($pid, $secret)
    {
        $sessionCodeToSimuliveNow = 'ED020TESTKALT';
        $localTimeZone =  'Asia/Jerusalem';
        date_default_timezone_set($localTimeZone); //make sure to set the expected timezone
        $shouldCreateRedirect = false;

        // get preStart and postEnd. if none was given, values will be taken from the existing scheduleEvent or set to 0 if none exists
        $preStartSec = -1;
        if (isset($_GET['preStart'])) {
            $preStartSec = intval($_GET['preStart']); //5
            $preStartSec = ($preStartSec > 0) ? $preStartSec * 60 : $preStartSec;
        } else {
            $preStartSec = 0;
        }
        $postEndSec = -1;
        if (isset($_GET['postEnd'])) {
            $postEndSec = intval($_GET['postEnd']); //15
            $postEndSec = ($postEndSec > 0) ? $postEndSec * 60 : $postEndSec;
        } else {
            $postEndSec = 0;
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
        $privileges = 'all:*,list:*,disableentitlement';
        $this->ks = $this->client->session->start($secret, 'Admins', SessionType::ADMIN, $pid, KalturaImportUtilsConfig::KS_EXPIRY_TIME, $privileges);
        $this->client->setKs($this->ks);

        $metadataPlugin = MetadataPlugin::get($this->client);
        $schedulePlugin = SchedulePlugin::get($this->client);

        $webcastConfigurationMetadataProfileId = KalturaApiUtils::getKalturaMetadataProfileId($metadataPlugin, KalturaApiUtils::WEBCAST_CONFIGURATION_METADATA);
        $webcastEventMetadataProfileId = KalturaApiUtils::getKalturaMetadataProfileId($metadataPlugin, KalturaApiUtils::WEBCAST_EVENT_METADATA);

        $filter = new MediaEntryFilter();
        $filter->mediaTypeEqual = MediaType::VIDEO;
        $filter->statusIn = "7,2,0,1,4"; //default media.list doesn't include DRAFT entries
        $currentVodEntry = KalturaApiUtils::getKalturaObjectByRefernceId($this->client->getMediaService(), $sessionCodeToSimuliveNow, $filter);

        $filter = new LiveStreamEntryFilter();
        $filter->mediaTypeEqual = MediaType::LIVE_STREAM_FLASH;
        $currentLiveEntry = KalturaApiUtils::getKalturaObjectByRefernceId($this->client->getLiveStreamService(), $sessionCodeToSimuliveNow, $filter);

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

        if ($currentVodEntry === false || $currentLiveEntry === false) {
            print "Sorry, can't find simulive entries and scheduleEvent for session code: {$sessionCodeToSimuliveNow}<br />\n";
            die();
        }

        $redirectFoundMsg = '';
        if ($shouldCreateRedirect) {
            if ($currentRedirectScheduleEvent === false) {
                print "Couldn't find an existing RedirectScheduleEvent<br />\n";
            } else {
                $redirectFoundMsg = " (redirect: {$currentRedirectScheduleEvent->id})";
            }
        }

        $scheduleFoundMsg = '';
        if ($currentScheduleEvent === false) {
            print "Couldn't find an existing LiveStreamScheduleEvent<br />\n";
        } else {
            $scheduleFoundMsg = " on schedule: {$currentScheduleEvent->id}";
        }

        $videoDurationMinutes = intval($currentVodEntry->msDuration / 60000); //millis into minutes

        $eventStartDateTime = new DateTime(); //now
        $eventEndDateTime = clone $eventStartDateTime;
        $eventEndDateTime = $eventEndDateTime->modify('+' . $videoDurationMinutes . ' minutes');

        $redirectStartDateTime = clone $eventEndDateTime;
        $redirectStartDateTime = $redirectStartDateTime->modify("+1 second");
        $redirectEndDateTime = clone $redirectStartDateTime;
        $redirectEndDateTime = $redirectEndDateTime->modify("+23 months");

        $webcastConfigMetadata = KalturaApiUtils::createMetadataTemplateFromSchema($metadataPlugin, KalturaApiUtils::WEBCAST_CONFIGURATION_METADATA);
        $webcastConfigMetadata->IsKwebcastEntry = 1; // always set to 1 for webcast and simulive
        $webcastConfigMetadata->IsSelfServe = 0; // should we enable self-served broadcasting using webcam/audio without external encoder?
        $webcastConfigMetadataObj = KalturaApiUtils::upsertCustomMetadata($metadataPlugin, $webcastConfigurationMetadataProfileId, MetadataObjectType::ENTRY, $currentLiveEntry->id, $webcastConfigMetadata, KalturaApiUtils::WEBCAST_CONFIGURATION_METADATA);
        echo 'upserted Webcast Config metadata (' . $webcastConfigMetadataObj->id . ')' . '<br />' . PHP_EOL;

        $webcastEventMetadata = KalturaApiUtils::createMetadataTemplateFromSchema($metadataPlugin, KalturaApiUtils::WEBCAST_EVENT_METADATA);
        $webcastEventMetadata->Timezone = ($localTimeZone == 'Asia/Jerusalem') ? 'Asia/Jerusalem' : KalturaApiUtils::EVENT_WEBCAST_TIMEZONE_US_PACIFIC;
        $webcastEventMetadata->StartTime = $eventStartDateTime->format('U'); // indicate when the webcast will begin in unix time
        $webcastEventMetadata->EndTime = $eventEndDateTime->format('U'); // indicate when the webcast will end in unix time
        $webcastEventMetadataObj = KalturaApiUtils::upsertCustomMetadata($metadataPlugin, $webcastEventMetadataProfileId, MetadataObjectType::ENTRY, $currentLiveEntry->id, $webcastEventMetadata, KalturaApiUtils::WEBCAST_EVENT_METADATA);
        echo 'upserted Webcast Event metadata (' . $webcastEventMetadataObj->id . ')' . '<br />' . PHP_EOL;

        $scheduleEvent = new LiveStreamScheduleEvent();
        $scheduleEvent->sourceEntryId = $currentVodEntry->id;
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
            print "redirect: {$scheduleRedirectEvent->id}, simulive: {$scheduleEvent->id}<br />\n";
        }
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
        $instance = new ScheduleSimuliveNow();
        $instance->run($pid, $configs['adminSecret']);
        unset($instance);
    }
}
$executionTime->end();
echo $executionTime;
