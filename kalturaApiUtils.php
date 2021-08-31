<?php

use Kaltura\Client\Client as KalturaClient;
use Kaltura\Client\Enum\ConversionProfileType;
use Kaltura\Client\Enum\EntryStatus;
use Kaltura\Client\Enum\MediaType;
use Kaltura\Client\Enum\NullableBoolean;
use Kaltura\Client\Enum\SourceType;
use Kaltura\Client\Plugin\Metadata\Enum\MetadataObjectType;
use Kaltura\Client\Plugin\Metadata\MetadataPlugin;
use Kaltura\Client\Plugin\Metadata\Type\MetadataFilter;
use Kaltura\Client\Plugin\Metadata\Type\MetadataProfileFilter;
use Kaltura\Client\Plugin\Schedule\Enum\ScheduleEventRecurrenceType;
use Kaltura\Client\Plugin\Schedule\SchedulePlugin;
use Kaltura\Client\Plugin\Schedule\Type\LiveRedirectScheduleEvent;
use Kaltura\Client\Plugin\Schedule\Type\LiveRedirectScheduleEventFilter;
use Kaltura\Client\Plugin\Schedule\Type\LiveStreamScheduleEvent;
use Kaltura\Client\Plugin\Schedule\Type\LiveStreamScheduleEventFilter;
use Kaltura\Client\Type\AccessControlLimitDeliveryProfilesAction;
use Kaltura\Client\Type\AccessControlProfile;
use Kaltura\Client\Type\CategoryEntryFilter;
use Kaltura\Client\Type\CategoryFilter;
use Kaltura\Client\Type\ConversionProfileFilter;
use Kaltura\Client\Type\LiveStreamEntry;
use Kaltura\Client\Type\LiveStreamEntryFilter;
use Kaltura\Client\Type\MediaEntry;
use Kaltura\Client\Type\MediaEntryFilter;
use Kaltura\Client\Type\Rule;
use Kaltura\Client\Type\UrlResource;
use Kaltura\Client\Type\User;
use Kaltura\Client\Type\UserFilter;

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return '' === $needle || false !== strpos($haystack, $needle);
    }
}

class KalturaApiUtils
{
    const ENTRY_ADDITIONAL_INFO_PROFILE_SYSTEM_NAME = 'EntryAdditionalInfo';
    const ENTRY_ADDITIONAL_INFO_DETAILS_ELEMENT = 'Detail';

    const ENTRY_CALL_TO_ACTION_ADDITIONAL_INFO_KEY = 'entrycalltoaction';

    const MEDIASPACE_USERROLE_SCHEMA_SYSTEM_NAME_BASE = 'KMS_USERSCHEMA1_'; //to this value you need to concat the privacyContext of the KMS category
    const MEDIASPACE_USERROLE_VIEWER_ONLY = 'viewerRole';
    const USER_STATUS_PRE_REGISTERED = 'Pre-registered';
    const USER_STATUS_REGISTERED = 'Registered';
    const USER_STATUS_UN_REGISTERED = 'Un-registered';
    const USER_STATUS_ATTENDED = 'Attended';

    const WEBCAST_ADMIN_USER_ID = 'WebcastingAdmin';
    const LIVENG_ADMIN_TAG = 'liveng';
    const WEBCAST_CONFIGURATION_METADATA = 'KMS_KWEBCAST2';
    const WEBCAST_EVENT_METADATA = 'KMS_EVENTS3';
    const EVENT_WEBCAST_TIMEZONE_US_PACIFIC = 'US/Pacific';
    const EVENT_WEBCAST_TIMEZONE_US_CENTRAL = 'US/Central';
    const EVENT_WEBCAST_TIMEZONE_US_EASTERN = 'US/Eastern';
    const WEBCAST_MODERATORS_ADDITIONAL_INFO_KEY = 'kwebcast_moderators_array';
    const PRESENTERS_ADDITIONAL_INFO_KEY = 'presenters';
    const PRESENTERS_ADDITIONAL_INFO_VALUE_PREFIX = 'presenter_';
    const WEBCAST_TYPE_ADDITIONAL_INFO_KEY = 'webcastType';
    const WEBCAST_TYPE_MANUAL = 'manual';
    const WEBCAST_TYPE_SIMULIVE = 'simulive';
    const WEBCAST_TYPE_KALTURALIVE = 'kalturalive';

    const MEETING_ROOM_TIMEZONE_US_PACIFIC = 'US\/Pacific';
    const MEETING_ROOM_TIMEZONE_US_CENTRAL = 'US\/Central';
    const MEETING_ROOM_TIMEZONE_US_EASTERN = 'US\/Eastern';
    const MEETING_ROOM_MODE_INFO_KEY = 'meetingEntryMode';
    const MEETING_ROOM_TIMEZONE_INFO_KEY = 'meetingEntryTimeZone';
    const MEETING_ROOM_MODE_VALUE_DEFAULT = 'default';
    const MEETING_ROOM_MODE_VALUE_WEBINAR = 'webinar';
    const MEETING_ROOM_MODE_VALUE_CLASSROOM = 'virtual_classroom';
    const MEETING_ROOM_ENTRY_ADMIN_TAG = "__meeting_room";
    const MEETING_ROOM_GENERAL_SUB_DRAFT_ADMIN_TAG = "__sub_draft_entry_type";

    const UPDATE_THUMBNAIL_URL_BASE = 'https://cfvod.kaltura.com/';

    const SIMULIVE_DELIVERY_PROFILE_IDS = array(22082, 15282);

    const SCHEDULE_INPUT_DATE_FORMAT = 'm/d/Y';
    const SCHEDULE_INPUT_TIME_FORMAT = 'h:i:s A';
    const SCHEDULE_END_TIME_VOD_DURATION = 'SCHEDULE_END_TIME_VOD_DURATION';
    const SCHEDULE_END_DATE_SAME_AS_START = 'SCHEDULE_END_DATE_SAME_AS_START';
    const SCHEDULE_DEFAULT_TIMEZONE = 'America/Chicago'; //https://www.php.net/manual/en/timezones.php
    const INVALID_SESSIONCODE_ERROR = 'Invalid session code provided (should match the referenceId field of KalturaBaseEntry)';
    const INVALID_SESSION_DATE = 'Invalid session date provided, please use format: ';
    const INVALID_SESSION_TIME = 'Invalid session time provided, please use format: ';

    /**
     * Creates or updates a Simulive session (this will update all relevant objects including the live and vod entries, access control, metadata, schedule events, etc.)
     *
     * @param KalturaClient $client the KalturaClient instance to use
     * @param string $sessionCode the code of the session to upsert, it is the referenceId of the entries that will be created in Kaltura.
     * @param string $startDateText the start date in text format (SCHEDULE_INPUT_DATE_FORMAT)
     * @param string $startTimeText the start time in text format (SCHEDULE_INPUT_TIME_FORMAT)
     * @param string $endDateText the end date in text format (SCHEDULE_INPUT_DATE_FORMAT), alternatively set to SCHEDULE_END_DATE_SAME_AS_START to use the same date as the input provided in $startDateText
     * @param string $endTimeText the end time in text format (SCHEDULE_INPUT_TIME_FORMAT), alternatively set to SCHEDULE_END_TIME_VOD_DURATION to use the startTime + VOD duration. If VOD entry doesn't have duration (doesn't exist yet or doesn't have a video uploaded yet, the function will throw an error)
     * @param int $preStartSec how long is the stitched simulive vod source pre roll is in seconds
     * @param int $postEndSec how long is the stitched simulive vod source post roll is in seconds
     * @param LiveStreamEntry $liveEntry any base fields to use as the session details (title, description, tags, etc.)
     * @param MediaEntry $vodEntry any base fields to use as the session details for the VOD Source entry
     * @param int $webcastConfigurationMetadataProfileId the id of the webcast metadata profile to update webcasting configuration on
     * @param int $webcastEventMetadataProfileId the id of the webcast metadata profile to update webcasting event details on
     * @param int $simuliveAccessControlProfileId the id of the simulive access control profile to use (if null, a new one will be created)
     * @param bool $validateAcp if true, the access control profile will be validated to ensure it is properly setup
     * @param bool $redirectRecordingPostSimulive if true, a redirect schedule event will be created to redirect the live to the recording VOD
     * @param int $redirectEndYears the number of years to end the redirect of the live to VOD recording entry
     * @param string $eventTimeZone an old PHP timezone to state where the event is held. if null, SCHEDULE_DEFAULT_TIMEZONE will be used
     * @param string $repeatCodeExtension a sub-string to look for at the end of the sessionCode that will indicate this is a repeat session, and as such its VOD Source should be found in the original sessionCode (without the extension)
     * @param bool $updateEntries if true, $liveEntry and $vodEntry will be used to update the base metadata fields of the simulive vod and live entries (if $liveEntry or $vodEntry are null this will be ignored respectively)
     * @param bool $forceCreateNewSchedules if true, this will ignore existing schedule event objects, and create new ones (e.g. to be used when creating a testing schedule pre-event)
     * @param bool $onlyUpdateMetadata if true, will only update metadata, and not update schedule events
     * @return array an array contiaining the session objects, first element is the live entry, second is the vod entry, third is the scheduleEvent, forth is the redirect scheduleEvent, sixth is the AccessControlProfile used
     */
    public static function upsertSimuliveSession(KalturaClient $client, string $sessionCode, string $startDateText, string $startTimeText, string $endDateText, string $endTimeText, int $preStartSec = -1, int $postEndSec = -1, LiveStreamEntry $liveEntry = null, MediaEntry $vodEntry = null, int $webcastConfigurationMetadataProfileId = null, int $webcastEventMetadataProfileId = null, int $simuliveAccessControlProfileId = null, bool $validateAcp = true, bool $redirectRecordingPostSimulive = false, int $redirectEndYears = 2, string $eventTimeZone = null, string $repeatCodeExtension = 'R1', bool $updateEntries = true, $forceCreateNewSchedules = false, $onlyUpdateMetadata = false): array
    {
        $createdNewVOD = false;
        $createdNewLive = false;
        $metadataPlugin = MetadataPlugin::get($client);
        $schedulePlugin = SchedulePlugin::get($client);
        //validate access control profile is properly setup
        if ($validateAcp) {
            $simuliveAcp = self::createSimuliveAccessControlProfile($client, $simuliveAccessControlProfileId, $validateAcp);
        }
        //unless provided, get the webcast configuration metadata profile
        if ($webcastConfigurationMetadataProfileId === null) $webcastConfigurationMetadataProfileId = self::getKalturaMetadataProfileId($metadataPlugin, self::WEBCAST_CONFIGURATION_METADATA);
        //unless provided, get the webcast event metadata profile
        if ($webcastEventMetadataProfileId === null) $webcastEventMetadataProfileId = self::getKalturaMetadataProfileId($metadataPlugin, self::WEBCAST_EVENT_METADATA);
        //get the source vod entry
        $filter = new MediaEntryFilter();
        $filter->mediaTypeEqual = MediaType::VIDEO;
        $filter->statusIn = '7,2,0,1,4'; //include DRAFT entries
        //check if this is a repeat session (if it ends with $repeatCodeExtension), if yes, get the source vod (without the extesion)
        $vodSourceSessionCode = $sessionCode;
        if (str_ends_with($sessionCode, $repeatCodeExtension) === true) {
            $vodSourceSessionCode = substr($sessionCode, 0, (-1 * strlen($repeatCodeExtension)));
        }
        $vodEntry->referenceId = $vodSourceSessionCode;
        $currentVodEntry = self::getKalturaObjectByRefernceId($client->getMediaService(), $vodSourceSessionCode, $filter);
        if ($currentVodEntry === false) {
            if ($vodEntry === null) {
                throw new Exception('Source VOD could not be found for this Simulive session [' . $vodSourceSessionCode . '], and no new VOD Source entry template was provided in $vodEntry');
            } else {
                //if no VOD Source entry was found, create a new one
                $currentVodEntry = $client->getMediaService()->add($vodEntry);
                $createdNewVOD = true;
            }
        }
        //update the VOD Source entry base metadata based on provided $vodEntry
        if ($updateEntries == true && $vodEntry !== null && $createdNewVOD == false) {
            $currentVodEntry = $client->getMediaService()->update($currentVodEntry->id, $vodEntry);
        }
        //get the simulive target live stream entry
        $filter = new LiveStreamEntryFilter();
        $filter->mediaTypeEqual = MediaType::LIVE_STREAM_FLASH;
        $currentLiveEntry = self::getKalturaObjectByRefernceId($client->getLiveStreamService(), $sessionCode, $filter);
        if ($currentLiveEntry === false) {
            if ($liveEntry === null) {
                throw new Exception('Live Stream entry could not be found for this Simulive session [' . $sessionCode . '], and no new Live Stream entry template was provided in $liveEntry');
            } else {
                //if no Live Stream entry was found, create a new one
                $liveEntry->accessControlId = $simuliveAccessControlProfileId;
                $currentLiveEntry = $client->getLiveStreamService()->add($liveEntry, SourceType::LIVE_STREAM);
                $createdNewLive = true;
            }
        }
        //make sure that the Live Stream entry access control profile is set to the simulive acp
        if ($simuliveAccessControlProfileId !== null && $currentLiveEntry->accessControlId != $simuliveAccessControlProfileId) {
            $liveStreamEntryTemp = new LiveStreamEntry();
            if ($liveEntry !== null) {
                $liveStreamEntryTemp = $liveStreamEntryTemp;
            }
            $liveStreamEntryTemp->accessControlId = $simuliveAccessControlProfileId;
            $currentLiveEntry = $client->getLiveStreamService()->update($currentLiveEntry->id, $liveStreamEntryTemp);
        }
        //update the Live Stream entry base metadata based on provided $liveEntry
        if ($updateEntries == true && $liveEntry !== null && $createdNewLive == false) {
            $currentLiveEntry = $client->getLiveStreamService()->update($currentLiveEntry->id, $liveEntry);
        }

        if (isset($currentLiveEntry->id)) {
            //get the simulive live schedule event
            $scheduleEventTemplateEntryId = $currentLiveEntry->id;
            $filter = new LiveStreamScheduleEventFilter(); // get the simulive streaming/agenda schedule
            $filter->templateEntryIdEqual = $scheduleEventTemplateEntryId;
            $currentScheduleEvent = $schedulePlugin->scheduleEvent->listAction($filter);
            if ($forceCreateNewSchedules == false && $currentScheduleEvent->totalCount > 0) {
                $currentScheduleEvent = $currentScheduleEvent->objects[0];
            } else {
                $currentScheduleEvent = false;
            }
            //get the recording redirect schedule event
            $currentRedirectScheduleEvent = null;
            $filter = new LiveRedirectScheduleEventFilter(); // get the post simulive redirect to vod schedule
            $filter->templateEntryIdEqual = $scheduleEventTemplateEntryId;
            $currentRedirectScheduleEvent = $schedulePlugin->scheduleEvent->listAction($filter);
            if ($forceCreateNewSchedules == false && $currentRedirectScheduleEvent->totalCount > 0) {
                $currentRedirectScheduleEvent = $currentRedirectScheduleEvent->objects[0];
            } else {
                $currentRedirectScheduleEvent = false;
            }
        }
        if ($currentScheduleEvent !== false) {
            $preStartSec = ($preStartSec < 0) ? $currentScheduleEvent->preStartTime : $preStartSec;
            $postEndSec = ($postEndSec < 0) ? $currentScheduleEvent->postEndTime : $postEndSec;
        }
        //reset to zero if provided preStart or postEnd are negative numbers
        if ($preStartSec < 0) $preStartSec = 0;
        if ($postEndSec < 0) $postEndSec = 0;

        //parse the string dates and times into datetime objects
        $sessionStartDateTimeFormatted = $startDateText . ' ' . $startTimeText;
        if ($endDateText == self::SCHEDULE_END_DATE_SAME_AS_START) $endDateText = $startDateText;
        $sessionEndDateTimeFormatted = $endDateText . ' ' . $endTimeText;
        $dateFormatStr = self::SCHEDULE_INPUT_DATE_FORMAT . ' ' . self::SCHEDULE_INPUT_TIME_FORMAT;
        $eventTimeZone = ($eventTimeZone === null) ? self::SCHEDULE_DEFAULT_TIMEZONE : $eventTimeZone;
        $eventStartDateTime = DateTime::createFromFormat($dateFormatStr, $sessionStartDateTimeFormatted, new DateTimeZone($eventTimeZone));
        $eventEndDateTime = null;
        if ($endTimeText == self::SCHEDULE_END_TIME_VOD_DURATION) {
            if ($currentVodEntry == null || $currentVodEntry->msDuration <= 0 || $currentVodEntry->status != EntryStatus::READY) {
                throw new Exception(self::SCHEDULE_END_TIME_VOD_DURATION . ' requested, but provided VOD Source entry (' . $currentVodEntry->id . ') is not yet ready or does not have valid msDuration.', 199);
            }
            $eventEndDateTime = clone $eventStartDateTime;
            $vodMsDuration = $currentVodEntry->msDuration - (($preStartSec + $postEndSec) * 1000); //duration of the simulive must take into account the prestart and postend durations
            $eventEndDateTime = $eventEndDateTime->modify('+' . $vodMsDuration . ' msec');
        } else {
            $eventEndDateTime = DateTime::createFromFormat($dateFormatStr, $sessionEndDateTimeFormatted, new DateTimeZone($eventTimeZone));
        }
        //create the redirect datetime objects
        if ($redirectRecordingPostSimulive != false) {
            $redirectStartDateTime = clone $eventEndDateTime;
            $startAfterEndTime = $postEndSec + 1;
            $redirectStartDateTime = $redirectStartDateTime->modify('+' . $startAfterEndTime . ' seconds');
            $redirectEndDateTime = clone $redirectStartDateTime;
            $redirectEndDateTime = $redirectEndDateTime->modify('+' . $redirectEndYears . ' years');
        }

        //DEBUG DATES - 
        $debugMessage = 'FORMAT: "' . $dateFormatStr . '" -- INPUT START: ' . $sessionStartDateTimeFormatted . PHP_EOL;
        $debugMessage .= $eventStartDateTime->format('U') . ' -- ' . $eventStartDateTime->format($dateFormatStr) . PHP_EOL;
        $debugMessage .= $eventEndDateTime->format('U') . ' -- ' . $eventEndDateTime->format($dateFormatStr) . PHP_EOL;
        if ($redirectRecordingPostSimulive != false) {
            $debugMessage .= $redirectStartDateTime->format('U') . ' -- ' . $redirectStartDateTime->format($dateFormatStr) . PHP_EOL;
            $debugMessage .= $redirectEndDateTime->format('U') . ' -- ' . $redirectEndDateTime->format($dateFormatStr) . PHP_EOL;
        }

        //update the live entry metadata for webcast config
        $webcastConfigMetadata = KalturaApiUtils::createMetadataTemplateFromSchema($metadataPlugin, KalturaApiUtils::WEBCAST_CONFIGURATION_METADATA);
        $webcastConfigMetadata->IsKwebcastEntry = 1; // always set to 1 for webcast and simulive
        $webcastConfigMetadata->IsSelfServe = 0; // always 0 for simulive
        $webcastConfigMetadataObj = KalturaApiUtils::upsertCustomMetadata($metadataPlugin, $webcastConfigurationMetadataProfileId, MetadataObjectType::ENTRY, $currentLiveEntry->id, $webcastConfigMetadata, KalturaApiUtils::WEBCAST_CONFIGURATION_METADATA);
        //echo 'upserted Webcast Config metadata (' . $webcastConfigMetadataObj->id . ')' . PHP_EOL;

        //update the live entry metadata for webcast event scheduling details
        $webcastEventMetadata = KalturaApiUtils::createMetadataTemplateFromSchema($metadataPlugin, KalturaApiUtils::WEBCAST_EVENT_METADATA);
        $webcastEventMetadata->Timezone = self::EVENT_WEBCAST_TIMEZONE_US_CENTRAL;
        $webcastEventMetadata->StartTime = $eventStartDateTime->format('U'); // indicate when the webcast will begin in unix time
        $webcastEventMetadata->EndTime = $eventEndDateTime->format('U'); // indicate when the webcast will end in unix time
        $webcastEventMetadataObj = KalturaApiUtils::upsertCustomMetadata($metadataPlugin, $webcastEventMetadataProfileId, MetadataObjectType::ENTRY, $currentLiveEntry->id, $webcastEventMetadata, KalturaApiUtils::WEBCAST_EVENT_METADATA);
        //echo 'upserted Webcast Event metadata (' . $webcastEventMetadataObj->id . ')' . PHP_EOL;

        //configure the live stream (simulive) schedule event
        if ($onlyUpdateMetadata == false) {
            $scheduleEvent = new LiveStreamScheduleEvent();
            $scheduleEvent->sourceEntryId = $currentVodEntry->id;
            $scheduleEvent->startDate = $eventStartDateTime->format('U');
            $scheduleEvent->endDate = $eventEndDateTime->format('U');
            $scheduleEvent->recurrenceType = ScheduleEventRecurrenceType::NONE;
            $scheduleEvent->templateEntryId = $currentLiveEntry->id;
            $scheduleEvent->preStartTime = $preStartSec;
            $scheduleEvent->postEndTime = $postEndSec;
            $scheduleEvent->templateEntryId = $currentLiveEntry->id;
            $scheduleEvent->summary = $currentLiveEntry->referenceId;
            if ($currentScheduleEvent !== false) {
                $schedulePlugin->scheduleEvent->cancel($currentScheduleEvent->id);
                $schedulePlugin->scheduleEvent->delete($currentScheduleEvent->id);
            }
            $currentScheduleEvent = $schedulePlugin->getScheduleEventService()->add($scheduleEvent);
        }
        //if recording redirect should happen, configure the redirect event to connect the vod recording to the live entry post the simulive event
        if ($onlyUpdateMetadata == false && $redirectRecordingPostSimulive != false) {
            $scheduleRedirectEvent = new LiveRedirectScheduleEvent();
            $scheduleRedirectEvent->recurrenceType = ScheduleEventRecurrenceType::NONE;
            $scheduleRedirectEvent->redirectEntryId = $currentScheduleEvent->sourceEntryId;
            $scheduleRedirectEvent->sourceEntryId = $currentScheduleEvent->sourceEntryId;
            $scheduleRedirectEvent->templateEntryId = $currentLiveEntry->id;
            $scheduleRedirectEvent->startDate = $redirectStartDateTime->format('U');
            $scheduleRedirectEvent->endDate = $redirectEndDateTime->format('U');
            $scheduleRedirectEvent->preStartTime = $currentScheduleEvent->preStartTime;
            $scheduleRedirectEvent->postEndTime = $currentScheduleEvent->postEndTime;
            $scheduleRedirectEvent->summary = $currentScheduleEvent->summary;
            if ($currentRedirectScheduleEvent !== false) {
                $schedulePlugin->scheduleEvent->cancel($currentRedirectScheduleEvent->id);
                $schedulePlugin->scheduleEvent->delete($currentRedirectScheduleEvent->id);
            }
            $currentRedirectScheduleEvent = $schedulePlugin->getScheduleEventService()->add($scheduleRedirectEvent);
        }

        //set the session type metadata indicating this is a simulive session (for mediaspace)
        KalturaApiUtils::updateWebcastTypeAddInfo($metadataPlugin, $currentLiveEntry->id, KalturaApiUtils::WEBCAST_TYPE_SIMULIVE);

        $returnArray = array($currentScheduleEvent, $currentRedirectScheduleEvent, $currentLiveEntry, $currentVodEntry, $debugMessage, $createdNewVOD, $createdNewLive);
        return $returnArray;
    }

    /**
     * Find API MultiRequest response item by a sub-result object.
     *
     * Usage example:
     *
     *     $client->startMultiRequest();
     *
     *     // ... other API calls
     *     $subResult = $client->someService()->someAction($params);
     *     // ... other API calls
     *
     *     $results = $client->doMultiRequest();
     *
     *     $singleApiCallResult = KalturaApiUtils::getMultiRequestResponseBySubResult($results, $subResult);
     *
     * @param object[]|null $multiResponseResults
     * @param MultiRequestSubResult|null $subResult
     * @return object|null
     */
    public static function getMultiRequestResponseBySubResult($multiResponseResults, $subResult)
    {
        if (!$multiResponseResults || !$subResult) {
            return null;
        }
        $index = (int)$subResult->value - 1;
        return $multiResponseResults[$index];
    }

    public static function textToDateTime(String $dateTextFormatted, String $timeTextFormatted, String $timeZoneText = null)
    {
        if (self::validateDate($dateTextFormatted) == false) {
            throw new Exception(self::INVALID_SESSION_DATE . self::SCHEDULE_INPUT_DATE_FORMAT);
        }
        if (self::validateTime($timeTextFormatted) == false) {
            throw new Exception(self::INVALID_SESSION_TIME . self::SCHEDULE_INPUT_TIME_FORMAT);
        }
        if ($timeZoneText === null) {
            $timeZoneText = self::SCHEDULE_DEFAULT_TIMEZONE;
        }
        $dateFormatStr = self::SCHEDULE_INPUT_DATE_FORMAT . ' ' . self::SCHEDULE_INPUT_TIME_FORMAT;
        $dateTimeFormatted = "{$dateTextFormatted} {$timeTextFormatted}";
        $eventStartDateTime = DateTime::createFromFormat($dateFormatStr, $dateTimeFormatted, new DateTimeZone($timeZoneText));
        return $eventStartDateTime;
    }
    protected static function validateTime(String $timeText)
    {
        $d = DateTime::createFromFormat(self::SCHEDULE_INPUT_TIME_FORMAT, $timeText);
        return $d && $d->format(self::SCHEDULE_INPUT_TIME_FORMAT) === $timeText;
    }
    protected static function validateDate(String $dateText)
    {
        $d = DateTime::createFromFormat(self::SCHEDULE_INPUT_DATE_FORMAT, $dateText);
        return $d && $d->format(self::SCHEDULE_INPUT_DATE_FORMAT) === $dateText;
    }

    public static function createHiddenTag($tag)
    {
        $pattern = array('/^/', '/[^[:alnum:]]/u');
        $replacement = array('__', '_');
        return preg_replace($pattern, $replacement, json_decode(json_encode($tag), true));
    }
    public static function cleanString($str, $clearHtmlChars = false)
    {
        if ($clearHtmlChars) {
            $cleanstr = preg_replace('/[[:^print:]]/', ' ', htmlspecialchars($str, ENT_COMPAT, 'ISO-8859-1', true));
        } else {
            $cleanstr = preg_replace('/[[:^print:]]/', ' ', $str);
        }
        return trim($cleanstr);
    }
    public static function getLiveCloudTranscodeConversionProfile($client)
    {
        $filter = new ConversionProfileFilter();
        $filter->typeEqual = ConversionProfileType::LIVE_STREAM;
        $liveConversionProfiles = $client->conversionProfile->listAction($filter);
        if (isset($liveConversionProfiles->objects)) {
            foreach ($liveConversionProfiles->objects as $liveConvProfile) {
                if ($liveConvProfile->systemName == 'Default_Live') {
                    return $liveConvProfile;
                }
                //Default_Live == Cloud Transcode
                //Passthrough_Live == Passthrough (no transcoding)
            }
        }
        return false;
    }
    public static function createSimuliveAccessControlProfile(KalturaClient $client, string $simuliveAccessControlProfileId = null, bool $validateAcp = false)
    {
        $currentAccessControlProfile = null;
        $accessControlProfile = new AccessControlProfile();
        if ($simuliveAccessControlProfileId !== null) {
            if ($validateAcp) {
                $acpList = $client->getAccessControlProfileService()->listAction();
                foreach ($acpList->objects as $cAccessControlProfile) {
                    if ($cAccessControlProfile->id == $simuliveAccessControlProfileId) {
                        $currentAccessControlProfile = $cAccessControlProfile;
                    }
                    if (isset($cAccessControlProfile->rules) && is_array($cAccessControlProfile->rules)) {
                        foreach ($cAccessControlProfile->rules as $acpRule) {
                            foreach ($acpRule->actions as $acpAction) {
                                if (get_class($acpAction) == 'Kaltura\Client\Type\AccessControlLimitDeliveryProfilesAction') {
                                    if (isset($acpAction->deliveryProfileIds) && $acpAction->deliveryProfileIds != null && $acpAction->deliveryProfileIds != '') {
                                        $hasSimuliveDeliveryProfiles = true;
                                        foreach (self::SIMULIVE_DELIVERY_PROFILE_IDS as $deliveryProfileId) {
                                            if (str_contains($acpAction->deliveryProfileIds, strval($deliveryProfileId)) == false) {
                                                $hasSimuliveDeliveryProfiles = false;
                                                break;
                                            }
                                        }
                                        if ($hasSimuliveDeliveryProfiles) {
                                            // if we've found an existing ACP with simulive delivery profiles, just return it
                                            return $cAccessControlProfile;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                $cAccessControlProfile = $client->getAccessControlProfileService()->get($simuliveAccessControlProfileId);
                return $cAccessControlProfile;
            }
        }
        if ($currentAccessControlProfile != null) {
            //updating an existing ACP
            $accessControlProfile->rules = $currentAccessControlProfile->rules;
        } else {
            //create new ACP
            $accessControlProfile->rules = array();
            $accessControlProfile->description = "Simulive Access Control Profile";
            $accessControlProfile->isDefault = NullableBoolean::FALSE_VALUE;
            $accessControlProfile->name = "ACP for Simulive 2021";
            $accessControlProfile->systemName = "simuliveacp";
        }
        $simuliveACPRule = new Rule();
        $simuliveACPRule->code = "simuliveProfile";
        $simuliveACPRule->forceAdminValidation = NullableBoolean::TRUE_VALUE;
        $simuliveACPRule->actions = array();
        $simuliveACPRule->actions[0] = new AccessControlLimitDeliveryProfilesAction();
        $simuliveACPRule->actions[0]->deliveryProfileIds = implode(',', self::SIMULIVE_DELIVERY_PROFILE_IDS);
        $simuliveACPRule->actions[0]->isBlockedList = false;
        array_push($accessControlProfile->rules, $simuliveACPRule);
        if ($currentAccessControlProfile != null) {
            $accessControlProfile = $client->getAccessControlProfileService()->update($currentAccessControlProfile->id, $accessControlProfile);
        } else {
            $accessControlProfile = $client->getAccessControlProfileService()->add($accessControlProfile);
        }
        return $accessControlProfile;
    }
    public static function updateUserThumbnail($client, $user, $rewrite = false)
    {
        // if the user doesn't have a thumbnailUrl configured, return false
        if (isset($user->thumbnailUrl) == false || $user->thumbnailUrl == null || $user->thumbnailUrl == '') {
            return false;
        }
        if (str_contains($user->thumbnailUrl, self::UPDATE_THUMBNAIL_URL_BASE)) {
            // this user already has a proper thumbnail
            return true;
        }
        //check if this user profile thumbnail is already in KaltÎura
        $filter = new MediaEntryFilter();
        $filter->mediaTypeEqual = MediaType::IMAGE;
        $filter->referenceIdEqual = $user->id;
        $profileImageEntryList = $client->getMediaService()->listAction($filter);
        if (count($profileImageEntryList->objects) > 0) {
            if ($rewrite == false) {
                return true;
            }
            // get the current user profile Image entry
            $entry = $profileImageEntryList->objects[0];
            $resource = new UrlResource();
            $resource->url = $user->thumbnailUrl;
            $entry = $client->media->updateContent($entry->id, $resource);
        } else {
            //create a new Image entry with the thumbnailUrl as source
            $entry = new MediaEntry();
            $entry->mediaType = MediaType::IMAGE;
            $entry->name = "User Profile Image - " . $user->fullName;
            $entry->referenceId = $user->id;
            $entry->adminTags = 'profileimage';
            $entry = $client->getMediaService()->add($entry);
            $resource = new UrlResource();
            $resource->url = $user->thumbnailUrl;
            $entry = $client->media->addContent($entry->id, $resource);
        }
        // update the thumbnailUrl of the user profile
        $userUpdate = new User();
        $date = new DateTime();
        $userUpdate->thumbnailUrl = self::UPDATE_THUMBNAIL_URL_BASE . 'p/' . $entry->partnerId . '/sp/' . $entry->partnerId . '00/thumbnail/entry_id/' . $entry->id . '/gentime/' . $date->getTimestamp();
        $user = $client->getUserService()->update($user->id, $userUpdate);
        return $user;
    }
    public static function upsertUser($client, $user, $printStatus = true)
    {
        $filter = new UserFilter();
        $filter->idEqual = $user->id;
        $userList = $client->getUserService()->listAction($filter);
        $newUser = null;
        if ($userList->totalCount > 0) {
            $newUser = $client->getUserService()->update($user->id, $user);
            if ($printStatus) echo 'updated user, ID: ' . $user->id . PHP_EOL;
        } else {
            $newUser = $client->getUserService()->add($user);
            if ($printStatus) echo 'created new user, ID: ' . $user->id . PHP_EOL;
        }
        return $newUser;
    }
    public static function updatedKmsUserRole(MetadataPlugin $metadataPlugin, $kmsPrivacyContext, $userId)
    {
        $schemaSystemName = self::MEDIASPACE_USERROLE_SCHEMA_SYSTEM_NAME_BASE . $kmsPrivacyContext;
        $profileId = self::getKalturaMetadataProfileId($metadataPlugin, $schemaSystemName);
        $metadataXml = new SimpleXMLElement('<metadata/>');
        $metadataXml->addChild('role', self::MEDIASPACE_USERROLE_VIEWER_ONLY);
        $filter = new MetadataFilter();
        $filter->objectIdEqual = $userId;
        $filter->metadataObjectTypeEqual = MetadataObjectType::USER;
        $filter->metadataProfileIdEqual = $profileId;
        $usersMetadataList = $metadataPlugin->metadata->listAction($filter);
        if ($usersMetadataList->totalCount > 0) {
            $metadataId = $usersMetadataList->objects[0]->id;
            $metadata = $metadataPlugin->metadata->update($metadataId, $metadataXml);
            return 'updated';
        } else {
            $metadata = $metadataPlugin->metadata->add($profileId, MetadataObjectType::USER, $userId, $metadataXml->asXML());
            return 'added';
        }
        return false;
    }
    public static function safeAddCategoryEntry($client, $categoryEntry)
    {
        $filter = new CategoryEntryFilter();
        $filter->entryIdEqual = $categoryEntry->entryId;
        $filter->categoryIdEqual = $categoryEntry->categoryId;
        $categoryEntryList = $client->getCategoryEntryService()->listAction($filter);
        if ($categoryEntryList->totalCount == 0) {
            $client->getCategoryEntryService()->add($categoryEntry);
        }
    }
    public static function getKalturaObjectByRefernceId($service, $referenceId, $filter)
    {
        $filter->referenceIdEqual = $referenceId;
        $objList = $service->listAction($filter);
        if ($objList->totalCount > 0)
            return $objList->objects[0];
        else
            return false;
    }
    public static function getEntryCTAUrl($metadataPlugin, $entryId)
    {
        // getting cta link using general metadata helper
        return self::getEntryAdditionalInfoValue($metadataPlugin, $entryId, self::ENTRY_CALL_TO_ACTION_ADDITIONAL_INFO_KEY);
    }
    public static function updatedScheduledTimeZone($metadataPlugin, $entryId, $timeZone)
    {
        $metadataXml = self::createAddInfoStringFieldMetadataXml(self::MEETING_ROOM_TIMEZONE_INFO_KEY, trim($timeZone), false);
        self::updateEntryAdditionalInfo($metadataPlugin, $entryId, $metadataXml);
    }
    public static function updateMeetingRoomMode($metadataPlugin, $entryId, $roomMode)
    {
        $metadataXml = self::createAddInfoStringFieldMetadataXml(self::MEETING_ROOM_MODE_INFO_KEY, trim($roomMode));
        self::updateEntryAdditionalInfo($metadataPlugin, $entryId, $metadataXml);
    }
    public static function updateWebcastTypeAddInfo($metadataPlugin, $entryId, $webcastType)
    {
        $metadataXml = self::createAddInfoStringFieldMetadataXml(self::WEBCAST_TYPE_ADDITIONAL_INFO_KEY, trim($webcastType));
        self::updateEntryAdditionalInfo($metadataPlugin, $entryId, $metadataXml);
    }
    public static function updateEntryCTAUrlAddInfo($metadataPlugin, $entryId, $ctaUrl)
    {
        $metadataXml = self::createAddInfoStringFieldMetadataXml(self::ENTRY_CALL_TO_ACTION_ADDITIONAL_INFO_KEY, trim($ctaUrl));
        self::updateEntryAdditionalInfo($metadataPlugin, $entryId, $metadataXml);
    }
    public static function updateWebcastModeratorsAddInfo($metadataPlugin, $entryId, $userIds)
    {
        $metadataXml = self::createAddInfoArrayFieldMetadataXml(self::WEBCAST_MODERATORS_ADDITIONAL_INFO_KEY, $userIds);
        self::updateEntryAdditionalInfo($metadataPlugin, $entryId, $metadataXml);
    }
    public static function updatePresentersAddInfo($metadataPlugin, $entryId, $userIds)
    {
        $metadataXml = self::createAddInfoArrayFieldMetadataXml(self::PRESENTERS_ADDITIONAL_INFO_KEY, $userIds);
        self::updateEntryAdditionalInfo($metadataPlugin, $entryId, $metadataXml);
    }
    private static function createAddInfoStringFieldMetadataXml($key, $strValue, $encode = true)
    {
        $metadataXml = new SimpleXMLElement('<metadata/>');
        $details = $metadataXml->addChild(self::ENTRY_ADDITIONAL_INFO_DETAILS_ELEMENT);
        $details->addChild('Key', $key);
        $value = $encode ? json_encode(urlencode($strValue)) : '"' . $strValue . '"';
        $details->addChild('Value', $value);
        return $metadataXml;
    }
    private static function createAddInfoArrayFieldMetadataXml($key, $arrayValue, $quotes = true)
    {
        $metadataXml = new SimpleXMLElement('<metadata/>');
        $details = $metadataXml->addChild(self::ENTRY_ADDITIONAL_INFO_DETAILS_ELEMENT);
        $details->addChild('Key', $key);
        $valuesEncoded = '[';
        if (is_array($arrayValue)) {
            foreach ($arrayValue as $val) {
                $val2add = ($quotes == true) ? '"' . trim($val) . '"' : trim($val);
                $valuesEncoded .= ($valuesEncoded != '[') ? ',' . $val2add : $val2add;
            }
        } else {
            $valuesEncoded .= trim($arrayValue);
        }
        $valuesEncoded .= ']';
        $details->addChild('Value', $valuesEncoded);
        return $metadataXml;
    }
    public static function createMetadataTemplateFromSchema($metadataPlugin, $schemaSystemName)
    {
        $schema = self::getCustomMetadataSchema($metadataPlugin, $schemaSystemName);
        $metadataXml = self::generateMetadataObjFromSchema($schema);
        return $metadataXml;
    }
    private static function getCustomMetadataSchema($metadataPlugin, $schemaSystemName)
    {
        $schema = './' . $schemaSystemName . '.xsd';
        if (!file_exists($schema)) { //if we don't have a cached xsd, fetch the schema from the API
            $metadataProfileId = self::getKalturaMetadataProfileId($metadataPlugin, $schemaSystemName);
            $schemaUrl = $metadataPlugin->getMetadataProfileService()->serve($metadataProfileId);
            file_put_contents($schema, file_get_contents($schemaUrl));
        }
        return $schema;
    }
    public static function implodeMultiObjects($glue, $arrayOfObjects, $fields)
    {
        $implodedStrs = array();
        foreach ($arrayOfObjects as $obj) {
            $imploded = '';
            foreach ($fields as $field) {
                if (isset($obj->{$field}) && $field != null && $field != '') {
                    $str = $obj->{$field};
                    if ($str != '' && $str != null) {
                        if ($imploded != '') {
                            $imploded .= $glue . $str;
                        } else {
                            $imploded .= $str;
                        }
                    }
                }
            }
            array_push($implodedStrs, $imploded);
        }
        return $implodedStrs;
    }
    /**
     * removes all empty nodes from a given SimpleXMLElement
     *
     * @param SimpleXMLElement $simpleXml
     * @return SimpleXMLElement the cleaned SimpleXMLElement object
     */
    public static function removeEmptyNodes($simpleXml)
    {
        foreach ($simpleXml as $node) {
            if ($node == '') {
                $tagName = $node->getName();
                unset($simpleXml->{$tagName}[0]);
            }
        }
        return $simpleXml;
    }
    /**
     * Adds a child to SimpleXMLElement, supports breaking array into children and string into tag value
     * If given $varToXml is neither a string nor array, this function will return false
     * On success, the manipulated SimpleXMLElement will be returned
     * If $simpleXml already includes a $childTag, but it's empty (removing template tags)
     * If the values includ a comma, the comma will be replaced by ampersand sign to comply with Kaltura supported metadata value chars
     * 
     * @param SimpleXMLElement $simpleXml The SimpleXMLElement object to add a child value to 
     * @param String $childTag The key of the tag to add the child to
     * @param stdClass|String $varToXml The value to add to the $childTag
     * @param Boolean $overrideExistingValue if true, the existing value in the specified childTag will be overridden by the given varToXml
     * @return SimpleXMLElement|false
     */
    public static function addValueToSimpleXml($simpleXml, $childTag, $varToXml, $overrideExistingValue = false)
    {
        $dom = new DomDocument();
        $dom->loadXML($simpleXml->asXML());
        $nodes = $dom->getElementsByTagName($childTag);
        $lastNode = $nodes->item($nodes->count() - 1);
        if ($overrideExistingValue == true) {
            // reset last node, and delete all others
            $lastNode->nodeValue = null;
            while ($nodes->count() > 1) {
                $lastNode->parentNode->removeChild($nodes->item(0));
            }
        }
        if (is_array($varToXml)) {
            foreach ($varToXml as $val) {
                $val = trim($val);
                if ($lastNode->nodeValue == '' || $lastNode->nodeValue == null) {
                    $lastNode->textContent = $val;
                } else {
                    $element = $dom->createElement($childTag, htmlspecialchars($val));
                    $lastNode->parentNode->insertBefore($element, $lastNode);
                }
            }
        } else {
            if ($varToXml != null) {
                $val = trim($varToXml);
                if ($lastNode->nodeValue == '' || $lastNode->nodeValue == null) {
                    $lastNode->textContent = $val;
                } else {
                    $element = $dom->createElement($childTag, htmlspecialchars($val));
                    $lastNode->parentNode->insertBefore($element, $lastNode);
                }
            }
        }
        $simpleXml = simplexml_import_dom($dom);
        return $simpleXml;
    }
    public static function findCategory($categoryService, $categoryName)
    {
        $filter = new CategoryFilter();
        $filter->freeText = '"' . $categoryName . '"';
        $result = $categoryService->listAction($filter);
        if (count($result->objects) > 0) {
            return $result->objects[0];
        } else {
            return false;
        }
    }
    public static function prettyPrintSimpleXml($simpleXml)
    {
        $dom = new DOMDocument("1.0");
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($simpleXml->asXML());
        return $dom->saveXML();
    }
    /**
     * Update (if metadata record already exists) or create a new metadata record (if not yet exists)
     * This function also validates the metadata XML against the schema XSD, and if validation fails it will echo the errors
     * 
     * @param int $metadataProfileId
     * @param string $objectType
     * @param int $objectId
     * @param Boolean $bypassValidation
     * @param SimpleXMLElement $metadataSimpleXml
     * @return Metadata
     */
    public static function upsertCustomMetadata($metadataPlugin, $metadataProfileId, $objectType, $objectId, $metadataSimpleXml, $profileSystemName, $bypassValidation = false)
    {
        $schema = self::getCustomMetadataSchema($metadataPlugin, $profileSystemName); //in case xsd doesn't exist locally, this will create it
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadXML($metadataSimpleXml->asXML()); // load xml
        if ($bypassValidation == false) {
            $is_valid_xml = $doc->schemaValidate('./' . $profileSystemName . '.xsd'); // path to xsd file
            if (!$is_valid_xml) {
                $errors = libxml_get_errors();
                $erroStr = 'Invalid XML: XSD validation failed!' . PHP_EOL;
                foreach ($errors as $error) {
                    switch ($error->level) {
                        case LIBXML_ERR_WARNING:
                            $erroStr .= "Warning $error->code: " . PHP_EOL;
                            break;
                        case LIBXML_ERR_ERROR:
                            $erroStr .= "Error $error->code: " . PHP_EOL;
                            break;
                        case LIBXML_ERR_FATAL:
                            $erroStr .= "Fatal Error $error->code: " . PHP_EOL;
                            break;
                    }
                    $erroStr .= trim($error->message) . PHP_EOL;
                    if ($error->file) {
                        $erroStr .= " in $error->file" . PHP_EOL;
                    }
                    $erroStr .= " on line $error->line" . PHP_EOL;
                }
                libxml_clear_errors();
                return $erroStr;
            }
        }
        $filter = new MetadataFilter();
        $filter->objectIdEqual = $objectId;
        $filter->metadataProfileIdEqual = $metadataProfileId;
        $filter->metadataObjectTypeEqual = $objectType;
        $existingRecords = $metadataPlugin->getMetadataService()->listAction($filter)->objects;
        $savedMetadata = null;
        $metadataXmlString = $metadataSimpleXml->asXML();
        if (count($existingRecords) == 0) {
            //add new record
            $savedMetadata = $metadataPlugin->getMetadataService()->add($metadataProfileId, $objectType, $objectId, $metadataXmlString);
        } else {
            //update existing record
            $metadataRecordId = $existingRecords[0]->id;
            $savedMetadata = $metadataPlugin->getMetadataService()->update($metadataRecordId, $metadataXmlString);
        }
        return $savedMetadata;
    }
    /**
     * Retrieve a SimpleXMLElement template of the profile schema by the profile XSD file path
     * 
     * @param int $schemaFilePath 
     * @return SimpleXMLElement
     */
    private static function generateMetadataObjFromSchema($schemaFilePath)
    {
        $schemaXSDFile = file_get_contents($schemaFilePath);
        //Build a <metadata> template:
        $schema = new DOMDocument();
        $schemaXSDFile = mb_convert_encoding($schemaXSDFile, 'utf-8', mb_detect_encoding($schemaXSDFile));
        $schema->loadXML($schemaXSDFile); //load and parse the XSD as an XML
        $xpath = new DOMXPath($schema);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $metadataTemplaeStr = '';
        $elementDefs = $xpath->evaluate("/xs:schema/xs:element");
        foreach ($elementDefs as $elementDef) {
            $metadataTemplaeStr .= self::iterateElements($metadataTemplaeStr, $elementDef, $schema, $xpath);
        }
        $metadataTemplaeStr .= '</metadata>';
        $metadataTemplaeSimpleXml = simplexml_load_string($metadataTemplaeStr);
        return $metadataTemplaeSimpleXml;
    }
    /**
     * Helper function to build a template xml from xsd schema (used by getMetadataSimpleTemplate)
     */
    private static function iterateElements($xmlStr, $elementDef, $doc, $xpath)
    {
        $key = trim($elementDef->getAttribute('name'));
        $xmlStr = '<' . $key . '>';
        $elementDefs = $xpath->evaluate("xs:complexType/xs:sequence/xs:element", $elementDef);
        foreach ($elementDefs as $elementDef) {
            $xmlStr .= self::iterateElements($xmlStr, $elementDef, $doc, $xpath);
            $key = trim($elementDef->getAttribute('name'));
            $xmlStr .= '</' . $key . '>';
        }
        return $xmlStr;
    }
    public static function getKeyedArrayAttributeValue($obj, $attributeId, $attributeKey, $valueField, $commaReplace = ' &')
    {
        $attributes = array();
        foreach ($obj as $childObj) {
            if (isset($childObj->{$attributeId}) && $childObj->{$attributeId} == $attributeKey) {
                $value = trim($childObj->{$valueField});
                $value = str_replace(',', $commaReplace, $value); //replace commas to comply with Kaltura custom metadata List type
                array_push($attributes, $value);
            }
        }
        return $attributes;
    }
    public static function presistantApiRequest($logger, $service, $actionName, $paramsArray, $numOfAttempts)
    {
        $attempts = 0;
        $lastError = null;
        do {
            try {
                $response = call_user_func_array(
                    array(
                        $service,
                        $actionName
                    ),
                    $paramsArray
                );
                if ($response === false) {
                    $logger->log("Error Processing API Action: " . $actionName);
                    throw new Exception("Error Processing API Action: " . $actionName, 1);
                }
            } catch (Exception $e) {
                $lastError = $e;
                ++$attempts;
                sleep(10);
                continue;
            }
            break;
        } while ($attempts < $numOfAttempts);
        if ($attempts >= $numOfAttempts) {
            $logger->log('======= API BREAKE =======' . PHP_EOL);
            $logger->log('Message: ' . $lastError->getMessage() . PHP_EOL);
            $logger->log('Last Kaltura client headers:' . PHP_EOL);
            $logger->log(print_r($logger->client->getResponseHeaders()));
            $logger->log('===============================');
        }
        return $response;
    }
    /**
     * Retrieves the id of the metadata profile by its system name, assuming profile with that system name exists
     * 
     * @param int $profileSystemName 
     * @return int
     */
    public static function getKalturaMetadataProfileId($metadataPlugin, $profileSystemName)
    {
        $filter = new MetadataProfileFilter();
        $filter->systemNameEqual = $profileSystemName;
        $id = $metadataPlugin->metadataProfile->listAction($filter)->objects[0]->id;
        return $id;
    }
    /**
     * get the entry additional info metadata from the api
     * @param $entryId
     */
    public static function getEntryAdditionalInfo($metadataPlugin, $entryId)
    {
        $metadata = array();
        $profileId = self::getKalturaMetadataProfileId($metadataPlugin, self::ENTRY_ADDITIONAL_INFO_PROFILE_SYSTEM_NAME);
        $filter = new MetadataFilter();
        $filter->objectIdIn = $entryId;
        $filter->metadataProfileIdEqual = $profileId;
        $filter->metadataObjectTypeEqual = MetadataObjectType::ENTRY;
        $metadata = $metadataPlugin->metadata->listAction($filter);
        return $metadata;
    }
    /**
     * get the entry additional info metadata from the api
     * @param string $entryId
     * @throws Kaltura_Client_Exception
     * @return mixed $value
     */
    public static function getEntryAdditionalInfoValue($metadataPlugin, $entryId, $key)
    {
        $metadata = self::getEntryAdditionalInfo($metadataPlugin, $entryId);
        if (!isset($metadata->objects[0]->xml)) {
            // entryAdditionalInfo metadata xml returned empty 
            return null;
        }
        $metadataXml = new SimpleXMLElement($metadata->objects[0]->xml);
        //dealing with 'downloadmedia' module metadata which was saved directly under <metadata/> element
        if (!empty($metadataXml->Key) && (string)$metadataXml->Key == $key) {
            return empty($metadataXml->Value) ? '' : json_decode((string)$metadataXml->Value);
        } else {
            //dealing with all other modules metadata which was saved under each 'Detail' child element
            $detailsElements = $metadataXml->xpath(self::ENTRY_ADDITIONAL_INFO_DETAILS_ELEMENT);
            foreach ($detailsElements as $details) {
                if (!empty($details->Key) && (string)$details->Key == $key) {
                    return empty($details->Value) ? '' : json_decode((string)$details->Value);
                }
            }
        }
        //value wasn't found
        return null;
    }
    /**
     * add/update entryAdditionalInfo metadata with new content - save to API.
     * @param $entryId
     * @param SimpleXMLElement $customDataXML
     * @param array $more list of SimpleXMLElement to add/update in entryAdditionalInfo
     * @throws Kaltura_Client_ClientException
     * @throws Kaltura_Client_Exception
     */
    public static function updateEntryAdditionalInfo($metadataPlugin, $entryId, SimpleXMLElement $customDataXML, $more = array())
    {
        $metadata = self::getEntryAdditionalInfo($metadataPlugin, $entryId);
        //if there is a metadata object saved for this entry update its' content
        if (!empty($metadata->objects)) {
            $updatedMetadata = self::updateEntryAdditionalInfoMetadata($metadata->objects[0]->xml, $customDataXML);
            $updatedMetadata = self::validateEntryAdditionalInfoSchema(new SimpleXMLElement($updatedMetadata))->asXML();
            foreach ($more as $moreCustomDataXML) {
                $updatedMetadata = self::updateEntryAdditionalInfoMetadata($updatedMetadata, $moreCustomDataXML);
                $updatedMetadata = self::validateEntryAdditionalInfoSchema(new SimpleXMLElement($updatedMetadata))->asXML();
            }
            try {
                $metadataId = $metadata->objects[0]->id;
                $metadata = $metadataPlugin->metadata->update($metadataId, $updatedMetadata);
            } catch (Exception $e) {
                // Failed updating entryAdditionalInfo for entry Id
                throw new Exception($e->getMessage(), $e->getCode());
            }
        } else {
            //if there is no metadata - add new one
            try {
                $updatedMetadata = self::validateEntryAdditionalInfoSchema($customDataXML)->asXML();
                foreach ($more as $moreCustomDataXML) {
                    $updatedMetadata = self::updateEntryAdditionalInfoMetadata($updatedMetadata, $moreCustomDataXML);
                    $updatedMetadata = self::validateEntryAdditionalInfoSchema(new SimpleXMLElement($updatedMetadata))->asXML();
                }
                $profileId = self::getKalturaMetadataProfileId($metadataPlugin, self::ENTRY_ADDITIONAL_INFO_PROFILE_SYSTEM_NAME);
                $metadata = $metadataPlugin->metadata->add($profileId, MetadataObjectType::ENTRY, $entryId, $updatedMetadata);
            } catch (Exception $e) {
                // Failed adding additional info metadata for entry 
                throw new Exception($e->getMessage(), $e->getCode());
            }
        }
    }
    /**
     * update existing metadata xml object with new data - no save to API, only manipulate the XML string
     * @param $metadataXmlString
     * @param $newMetadataXml
     * @return string xml of updated metadata
     */
    private static function updateEntryAdditionalInfoMetadata($metadataXmlString, $newMetadataXml)
    {
        $existingMetadataXml = new SimpleXMLElement($metadataXmlString);
        if (!empty($newMetadataXml)) {
            //checking for Key/Value elements under root element for 'downloadmedia' module support
            //(the module was already in customers when we realized that our solution was #@!$%)
            if (!empty($newMetadataXml->Key)) {
                //since there is only one pair of Key/Value under the root element update the metadata xml with new
                //values without the need of checking other conditions
                $existingMetadataXml->Key = $newMetadataXml->Key;
                $existingMetadataXml->Value = $newMetadataXml->Value;
                //if the Key/value elements under the root element was updated it means that these are the only updates
                //that were made (only the 'downloadmedia' module has this corrupted impl. and the module won't use both
                //corrupted and new impl. - key/value pairs under Detail element)
                return $existingMetadataXml->asXML();
            } else {
                //in new implementations, modules entryAdditionalInfo metadata is being saved as key/value pairs under
                //a Detail element.
                $additionalDetailsElements = array();
                $existingDetailsElms = $existingMetadataXml->xpath(self::ENTRY_ADDITIONAL_INFO_DETAILS_ELEMENT);
                $newMetadataDetailsElms = $newMetadataXml->xpath(self::ENTRY_ADDITIONAL_INFO_DETAILS_ELEMENT);
                foreach ($newMetadataDetailsElms as $newDetails) {
                    $found = false;
                    foreach ($existingDetailsElms as $existingDetails) {
                        if ((string)$newDetails->Key == (string)$existingDetails->Key) {
                            $existingDetails->Value = $newDetails->Value;
                            $found = true;
                            break;
                        }
                    }
                    //if these are new key/value pairs we need to add them to the existing metadata xml when iteration
                    //will end
                    if (!$found) {
                        $additionalDetailsElements[] = $newDetails;
                    }
                }
                //adding additional details elements to existing metadata xml
                foreach ($additionalDetailsElements as $additionalDetail) {
                    $details = $existingMetadataXml->addChild(self::ENTRY_ADDITIONAL_INFO_DETAILS_ELEMENT);
                    $details->addChild('Key', $additionalDetail->Key);
                    $details->addChild('Value', $additionalDetail->Value);
                }
                return $existingMetadataXml->asXML();
            }
        }
        //if no update was needed
        return $existingMetadataXml->asXML();
    }
    /**
     * Since we need to deal with 'downloadmedia' old and corrupted solution, a schema validation is needed
     * in order to make sure the Key/Value under to root element exists (even if they are empty)
     * *** pay attention - this validation need to be updated if the schema was updated ***
     * @param SimpleXMLElement $metadata
     * @return SimpleXMLElement validated metadata xml
     */
    private static function validateEntryAdditionalInfoSchema(SimpleXMLElement $metadata)
    {
        $validatedMetadata = new SimpleXMLElement('<metadata/>');
        $metadataKey = $metadata->Key ?? '';
        $metadataValue = $metadata->Value ?? '';
        $validatedMetadata->addChild('Key', $metadataKey);
        $validatedMetadata->addChild('Value', $metadataValue);
        $newMetadataDetailsElms = $metadata->xpath(self::ENTRY_ADDITIONAL_INFO_DETAILS_ELEMENT);
        //adding additional details elements to existing metadata xml
        foreach ($newMetadataDetailsElms as $newDetail) {
            $details = $validatedMetadata->addChild(self::ENTRY_ADDITIONAL_INFO_DETAILS_ELEMENT);
            $details->addChild('Key', $newDetail->Key);
            $details->addChild('Value', $newDetail->Value);
        }
        return $validatedMetadata;
    }
}
