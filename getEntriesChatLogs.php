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
use Kaltura\Client\Enum\NullableBoolean;
use Kaltura\Client\Enum\SessionType;
use Kaltura\Client\ILogger;
use Kaltura\Client\Plugin\Annotation\Enum\AnnotationOrderBy;
use Kaltura\Client\Plugin\Annotation\Type\AnnotationFilter;
use Kaltura\Client\Plugin\CuePoint\CuePointPlugin;
use Kaltura\Client\Plugin\CuePoint\Enum\CuePointType;
use Kaltura\Client\Type\FilterPager;

class SyncCsvSessions implements ILogger
{
    public $client = null;
    const EVENT_START_UNIX_TIME = 1622332800; //unix time for when the event started
    const EVENT_END_UNIX_TIME = -1; //unix time for when the event endded

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
        echo 'total sessions to get chat for: ' . count($sessionsList) . PHP_EOL;
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
            //echo "{$i}) Event: {$eventId}, Live: {$templateLiveEntryId}, VOD Source: {$sourceVODEntryId}, $summary \n";
            ++$i;
            $entryIds .= $templateLiveEntryId . ',';
        }

        $filter = new AnnotationFilter();
        $filter->cuePointTypeEqual = CuePointType::ANNOTATION;
        $filter->tagsLike = "qna";
        $filter->entryIdIn = $entryIds;
        $filter->orderBy = AnnotationOrderBy::UPDATED_AT_ASC;
        $filter->isPublicEqual = NullableBoolean::TRUE_VALUE;
        if (self::EVENT_START_UNIX_TIME > -1) $filter->updatedAtGreaterThanOrEqual = self::EVENT_START_UNIX_TIME;
        if (self::EVENT_END_UNIX_TIME > -1) $filter->updatedAtLessThanOrEqual = self::EVENT_END_UNIX_TIME;
        $pager = new FilterPager();
        $pager->pageIndex = 1;
        $pager->pageSize = 500;
        $result = $cuePointPlugin->cuePoint->listAction($filter, $pager);
        $chatAnnotations = array();
        while (count($result->objects) > 0) {
            $chatAnnotations = array_merge($chatAnnotations, $result->objects);
            $pager->pageIndex += 1;
            $result = $cuePointPlugin->cuePoint->listAction($filter, $pager);
        }

        $fp = fopen('chat_messages.csv', 'w');
        fputcsv($fp, array('Entry ID', 'Session Code', 'Message Sent Time', 'Message Sent Time Unixtime', 'User ID', 'Message Text'));
        foreach ($chatAnnotations as $chatAnnotation) {
            $title = $sessionsArr[$chatAnnotation->entryId]['title'];
            $code = $sessionsArr[$chatAnnotation->entryId]['code'];
            $chatMsgDateTime = DateTime::createFromFormat('U', $chatAnnotation->updatedAt);
            $chatMsgDateTime->setTimezone(new DateTimeZone(KalturaApiUtils::SCHEDULE_DEFAULT_TIMEZONE));
            $chatMsg = trim(preg_replace('/\s+/', ' ', $chatAnnotation->text));
            $csvLineArray = array($chatAnnotation->entryId, $code, $chatMsgDateTime->format('d, F, Y H:m:s T'), $chatAnnotation->updatedAt, $chatAnnotation->userId, $chatMsg);
            fputcsv($fp, $csvLineArray);
            $csvLine = implode(',', $csvLineArray);
            echo $csvLine . PHP_EOL;
        }
        fclose($fp);
    }
    private static function convertTimestamp2Excel($input)
    {
        $output = 25569 + (($input + date('Z', $input)) / 86400);
        return $output;
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
