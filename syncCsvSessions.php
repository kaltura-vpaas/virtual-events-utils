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
use Kaltura\Client\Enum\DVRStatus;
use Kaltura\Client\Enum\EntryType;
use Kaltura\Client\Enum\MediaType;
use Kaltura\Client\Enum\NullableBoolean;
use Kaltura\Client\Enum\RecordStatus;
use Kaltura\Client\Enum\SessionType;
use Kaltura\Client\ILogger;
use Kaltura\Client\Plugin\Metadata\MetadataPlugin;
use Kaltura\Client\Type\CategoryEntry;
use Kaltura\Client\Type\LiveEntryRecordingOptions;
use Kaltura\Client\Type\LiveStreamEntry;
use Kaltura\Client\Type\MediaEntry;

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
        $simuliveAcpId = KalturaImportUtilsConfig::KALTURA_ACCOUNTS[$pid]['simuliveACP'];
        $metadataPlugin = MetadataPlugin::get($this->client);

        $vodEntriesHiddenCategory = KalturaApiUtils::findCategory($this->client->getCategoryService(), KalturaImportUtilsConfig::SIMULIVE_VOD_SOURCE_HIDDEN_CATEGORY_NAME);
        $liveEntrySimuliveCategory = KalturaApiUtils::findCategory($this->client->getCategoryService(), KalturaImportUtilsConfig::SIMULIVE_LIVE_CATEGORY_NAME);

        $cloudTranscodeProfile = KalturaApiUtils::getLiveCloudTranscodeConversionProfile($this->client);

        //get the webcast configuration metadata profile
        $webcastConfigurationMetadataProfileId = KalturaApiUtils::getKalturaMetadataProfileId($metadataPlugin, KalturaApiUtils::WEBCAST_CONFIGURATION_METADATA);
        //get the webcast event metadata profile
        $webcastEventMetadataProfileId = KalturaApiUtils::getKalturaMetadataProfileId($metadataPlugin, KalturaApiUtils::WEBCAST_EVENT_METADATA);

        $vodEntryTemplate = new MediaEntry();
        $vodEntryTemplate->entitledUsersEdit = implode(',', KalturaImportUtilsConfig::ENTRIES_CO_EDITORS_USER_IDS);
        $vodEntryTemplate->adminTags = KalturaImportUtilsConfig::ENTRY_ADMINTAGS;
        $vodEntryTemplate->type = EntryType::MEDIA_CLIP;
        $vodEntryTemplate->mediaType = MediaType::VIDEO;

        $liveEntryTemplate = new LiveStreamEntry();
        $liveEntryTemplate->conversionProfileId = $cloudTranscodeProfile->id;
        $liveEntryTemplate->entitledUsersEdit = implode(',', KalturaImportUtilsConfig::ENTRIES_CO_EDITORS_USER_IDS);
        $liveEntryTemplate->entitledUsersEdit .= ',' . KalturaApiUtils::WEBCAST_ADMIN_USER_ID;
        $liveEntryTemplate->type = EntryType::LIVE_STREAM;
        $liveEntryTemplate->mediaType = MediaType::LIVE_STREAM_FLASH;
        $liveEntryTemplate->dvrStatus = DVRStatus::DISABLED;
        $liveEntryTemplate->recordStatus = RecordStatus::DISABLED;
        $liveEntryTemplate->dvrWindow = 0;
        $liveEntryTemplate->adminTags = KalturaImportUtilsConfig::ENTRY_ADMINTAGS . ',' . KalturaImportUtilsConfig::ENTRY_ADMINTAGS_SIMULIVE;;
        $liveEntryTemplate->explicitLive = NullableBoolean::FALSE_VALUE;
        $recordingOptions = new LiveEntryRecordingOptions();
        $recordingOptions->shouldCopyEntitlement = NullableBoolean::TRUE_VALUE;
        $recordingOptions->shouldMakeHidden = false;
        $liveEntryTemplate->recordingOptions = $recordingOptions;
        $liveEntryTemplate->accessControlId = $simuliveAcpId;

        $sessionsList = array_map('str_getcsv', file(KalturaImportUtilsConfig::SESSIONS_CSV_FILE));
        $headerLine = array_shift($sessionsList);
        echo 'total sessions to schedule: ' . count($sessionsList) . PHP_EOL;
        array_push($headerLine, 'Live Stream Entry ID', 'VOD Source Entry ID');
        $newSessions = array();
        $fp = fopen('./updated-sessions.csv', 'w');
        fputcsv($fp, $headerLine);
        /*
        CSV fields order - $session[X], X being: 
        0, INDEX	
        1, Session date
        2, Start time	
        3, End time
        4, Track
        5, Session code
        6, Session title
        */
        //sanitize the CSV input fields -
        $sessionsCount = 1;
        foreach ($sessionsList as $session) {
            for ($j = 0; $j < count($session); $j++) {
                $session[$j] = KalturaAPIUtils::cleanString($session[$j]);
                //if any of the input fields is empty, skip this line
                if ($session[$j] == '') {
                    break;
                }
            }

            $sessionCode = $session[5]; //'ED027R1'
            if (strlen($sessionCode) == 0) break;
            $sessionDate = $session[1]; //'6/3/2021'
            $sessionStartTime = $session[2]; //'12:00:00 PM'
            $sessionEndTime = $session[3]; //'12:45:00 PM'
            $sessionTracks = $session[4]; //'Track1,Track2...'
            $sessionTitle = $session[6]; //'How to session with sessions titles'

            $vodEntryTemplate->name = $sessionTitle;
            $vodEntryTemplate->referenceId = $sessionCode;
            $vodEntryTemplate->tags = $sessionTracks;

            $liveEntryTemplate->name = $sessionTitle;
            $liveEntryTemplate->referenceId = $sessionCode;
            $liveEntryTemplate->tags = $sessionTracks;

            //upsert the simulive session objects
            try {
                $updatedSimuliveSessionObjects = KalturaApiUtils::upsertSimuliveSession($this->client, $sessionCode, $sessionDate, $sessionStartTime, KalturaApiUtils::SCHEDULE_END_DATE_SAME_AS_START, KalturaApiUtils::SCHEDULE_END_TIME_VOD_DURATION, KalturaImportUtilsConfig::SIMULIVE_PRE_START_TIME, KalturaImportUtilsConfig::SIMULIVE_POST_END_TIME, $liveEntryTemplate, $vodEntryTemplate, $webcastConfigurationMetadataProfileId, $webcastEventMetadataProfileId, $simuliveAcpId, false, false, 2, null, KalturaImportUtilsConfig::REAPEAT_SESSION_CODE_EXTENSION, true, KalturaImportUtilsConfig::FORCE_CREATE_NEW_SCHEDULES, KalturaImportUtilsConfig::ONLY_UPDATE_METADATA);
            } catch (Exception $e) {
                if ($e->getCode() == 199) {
                    echo $sessionCode . ' - ' . $e->getMessage() . ' - using CSV provided end date and end time instead.' . PHP_EOL;
                    $updatedSimuliveSessionObjects = KalturaApiUtils::upsertSimuliveSession($this->client, $sessionCode, $sessionDate, $sessionStartTime, $sessionDate, $sessionEndTime, KalturaImportUtilsConfig::SIMULIVE_PRE_START_TIME, KalturaImportUtilsConfig::SIMULIVE_POST_END_TIME, $liveEntryTemplate, $vodEntryTemplate, $webcastConfigurationMetadataProfileId, $webcastEventMetadataProfileId, $simuliveAcpId, false, false, 2, null, KalturaImportUtilsConfig::REAPEAT_SESSION_CODE_EXTENSION, true, KalturaImportUtilsConfig::FORCE_CREATE_NEW_SCHEDULES, KalturaImportUtilsConfig::ONLY_UPDATE_METADATA);
                }
            }

            $simuliveSchedule = $updatedSimuliveSessionObjects[0];
            $simuliveRedirectSchedule = $updatedSimuliveSessionObjects[1];
            $simuliveLiveEntry = $updatedSimuliveSessionObjects[2];
            $simuliveVODEntry = $updatedSimuliveSessionObjects[3];
            $debugMessage = $updatedSimuliveSessionObjects[4];
            $createdNewVOD = $updatedSimuliveSessionObjects[5];
            $createdNewLive = $updatedSimuliveSessionObjects[6];

            if ($createdNewLive === true) {
                $liveCategoryEntry = new CategoryEntry();
                $liveCategoryEntry->categoryId = $liveEntrySimuliveCategory->id;
                $liveCategoryEntry->entryId = $simuliveLiveEntry->id;
                KalturaApiUtils::safeAddCategoryEntry($this->client, $liveCategoryEntry);
            }
            if ($createdNewVOD === true) {
                $vodCategoryEntry = new CategoryEntry();
                $vodCategoryEntry->categoryId = $vodEntriesHiddenCategory->id;
                $vodCategoryEntry->entryId = $simuliveVODEntry->id;
                KalturaApiUtils::safeAddCategoryEntry($this->client, $vodCategoryEntry);
            }

            // Update the Survey CTA Url
            $surveyCtaUrl = KalturaImportUtilsConfig::CTA_SURVEY_BASE_URL . $simuliveLiveEntry->referenceId;
            KalturaApiUtils::updateEntryCTAUrlAddInfo($metadataPlugin, $simuliveLiveEntry->id, $surveyCtaUrl);

            // Update the Webcast Moderators
            KalturaApiUtils::updateWebcastModeratorsAddInfo($metadataPlugin, $simuliveLiveEntry->id, KalturaImportUtilsConfig::ENTRIES_CO_EDITORS_USER_IDS);
            KalturaApiUtils::updateWebcastModeratorsAddInfo($metadataPlugin, $simuliveVODEntry->id, KalturaImportUtilsConfig::ENTRIES_CO_EDITORS_USER_IDS);

            //echo $debugMessage;
            echo "{$sessionsCount}) upserted {$simuliveLiveEntry->referenceId}; live:({$simuliveLiveEntry->id}, {$simuliveSchedule->id}), vod:({$simuliveVODEntry->id}" . ($simuliveRedirectSchedule != null ? ", {$simuliveRedirectSchedule->id}" : "") . ")\n";
            ++$sessionsCount;

            array_push($session, $simuliveLiveEntry->id);
            array_push($session, $simuliveVODEntry->id);
            array_push($newSessions, $session);
            fputcsv($fp, $session);
        }
        fclose($fp);
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
