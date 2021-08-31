<?php
set_time_limit(0);
ini_set('memory_limit', '1024M');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Jerusalem'); //make sure to set the expected timezone
require './vendor/autoload.php';
require './config.php';
require './kalturaApiUtils.php';
require './executionTime.php';

use Kaltura\Client\Configuration as KalturaImportUtilsConfiguration;
use Kaltura\Client\Client as KalturaClient;
use Kaltura\Client\Enum\MediaType;
use Kaltura\Client\Enum\SessionType;
use Kaltura\Client\ILogger;
use Kaltura\Client\Plugin\Attachment\AttachmentPlugin;
use Kaltura\Client\Plugin\Attachment\Type\AttachmentAssetFilter;
use Kaltura\Client\Plugin\Schedule\SchedulePlugin;
use Kaltura\Client\Plugin\Schedule\Type\LiveRedirectScheduleEventFilter;
use Kaltura\Client\Plugin\Schedule\Type\LiveStreamScheduleEventFilter;
use Kaltura\Client\Type\FilterPager;
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

    private $sessionCodeToSimuliveNow = 'ED020TESTKALT';
    private $playerScriptRegenerate = false;
    private $playerUiConfId = 46561983;
    private $uniqueUserId = 'tester';
    private $disableAtt = false;

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

    public function run($pid, $secret)
    {
        $key = '';
        $keys = array_merge(range(0, 9), range('a', 'z'));
        for ($i = 0; $i < 5; $i++) {
            $key .= $keys[array_rand($keys)];
        }
        $this->uniqueUserId = $this->uniqueUserId . '_' . $key;

        if (isset($_GET['uid'])) {
            $urlUserId = self::safeGetUrlParamString('uid');
            $this->uniqueUserId = $urlUserId;
        }

        if (isset($_GET['uiconf'])) {
            $uiConfId = self::safeGetUrlParamString('uiconf');
            $this->playerUiConfId = $uiConfId;
        }

        if (isset($_GET['regen'])) {
            $scriptRegenerate = self::safeGetUrlParamString('regen');
            $scriptRegenerate = intval($scriptRegenerate);
            $this->playerScriptRegenerate = ($scriptRegenerate > 0);
        }

        if (isset($_GET['refid'])) {
            $sessionCode = self::safeGetUrlParamString('refid');
            $this->sessionCodeToSimuliveNow = $sessionCode;
        }

        if (isset($_GET['disableatt'])) {
            $disableatt = self::safeGetUrlParamString('disableatt');
            $disableatt = intval($disableatt);
            $this->disableAtt = ($disableatt > 0);
        }

        $simuliveSummary = '';
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
        $this->ks = $this->client->session->start($secret, $this->uniqueUserId, SessionType::ADMIN, $pid, KalturaImportUtilsConfig::KS_EXPIRY_TIME, $privileges);
        $this->client->setKs($this->ks);
        $schedulePlugin = SchedulePlugin::get($this->client);
        $attachmentPlugin = AttachmentPlugin::get($this->client);

        $filter = new MediaEntryFilter();
        $filter->mediaTypeEqual = MediaType::VIDEO;
        $filter->statusIn = "7,2,0,1,4"; //default media.list doesn't include DRAFT entries
        //check if this is a repeat session (if it ends with $repeatCodeExtension), if yes, get the source vod (without the extesion)
        $vodSourceSessionCode = $this->sessionCodeToSimuliveNow;
        if (str_ends_with($vodSourceSessionCode, KalturaImportUtilsConfig::REAPEAT_SESSION_CODE_EXTENSION) === true) {
            $vodSourceSessionCode = substr($vodSourceSessionCode, 0, (-1 * strlen(KalturaImportUtilsConfig::REAPEAT_SESSION_CODE_EXTENSION)));
        }
        $currentVodEntry = KalturaApiUtils::getKalturaObjectByRefernceId($this->client->getMediaService(), $vodSourceSessionCode, $filter);

        $filter = new LiveStreamEntryFilter();
        $filter->mediaTypeEqual = MediaType::LIVE_STREAM_FLASH;
        $currentLiveEntry = KalturaApiUtils::getKalturaObjectByRefernceId($this->client->getLiveStreamService(), $this->sessionCodeToSimuliveNow, $filter);

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
            $filter = new LiveRedirectScheduleEventFilter();
            $filter->templateEntryIdEqual = $scheduleEventTemplateEntryId;
            $currentRedirectScheduleEvent = $schedulePlugin->scheduleEvent->listAction($filter);
            if ($currentRedirectScheduleEvent->totalCount > 0) {
                $currentRedirectScheduleEvent = $currentRedirectScheduleEvent->objects[0];
            } else {
                $currentRedirectScheduleEvent = false;
            }
        }

        if ($currentVodEntry === false || $currentLiveEntry === false || $currentScheduleEvent === false) {
            $simuliveSummary .= "Sorry, can't find simulive entries and scheduleEvent for session code: {$this->sessionCodeToSimuliveNow}<br />" . PHP_EOL;
        }

        $redirectFoundMsg = '';
        if ($currentRedirectScheduleEvent === false) {
            $simuliveSummary .= "Couldn't find an existing RedirectScheduleEvent<br />" . PHP_EOL;
        } else {
            $simuliveSummary .= " (redirect: {$currentRedirectScheduleEvent->id})";
        }
        $timestamp = $currentScheduleEvent->startDate + date("Z");
        $timestampEnd = $currentScheduleEvent->endDate + date("Z");
        $simuliveDateTimeStr = gmdate("F j, Y, g:i a", $timestamp) . ' - ' . gmdate("g:i a", $timestampEnd);

        $simuliveSummary .= "Scheduling simulive session for live: {$currentLiveEntry->id} (vod: {$currentVodEntry->id}), on schedule: {$simuliveDateTimeStr} ({$currentScheduleEvent->id}){$redirectFoundMsg}.<br />";

        $simuliveSummary = "user id used: {$this->uniqueUserId}\n<br />" . $simuliveSummary;

        //Get all attachment assets for the source VOD entry:
        $filter = new AttachmentAssetFilter();
        $filter->entryIdEqual = $currentScheduleEvent->sourceEntryId;
        $pager = new FilterPager();
        $attachmentAssetList = $attachmentPlugin->attachmentAsset->listAction($filter, $pager);
        if (count($attachmentAssetList->objects) > 0) {
            //Get a download url for each asset
            $assetsBlock = '<div>' . PHP_EOL;
            $assetsBlock .= '<p>Assets for session ' . $this->sessionCodeToSimuliveNow . ':</p>' . PHP_EOL;
            $assetsBlock .= '<ul>' . PHP_EOL;
            $storageId = 0;
            foreach ($attachmentAssetList->objects as $asset) {
                $attachmentAssetUrl = $attachmentPlugin->attachmentAsset->getUrl($asset->id, $storageId);
                $assetsBlock .= '<li><a href=' . $attachmentAssetUrl . '>' . $asset->filename . '</a></li>' . PHP_EOL;
            }
            $assetsBlock .= '</ul>' . PHP_EOL;
            $assetsBlock .= '</div>' . PHP_EOL;
        }

        //Create the embed code javascript source link
        $regen = '';
        if ($this->playerScriptRegenerate == true) {
            $regen = '/regenerate/true?r=' . time();
        }
        $kalturaPlayerJavaScriptSrc = KalturaImportUtilsConfig::KALTURA_ACCOUNTS[$pid]['serviceUrl'] . '/p/' . $pid . '/embedPlaykitJs/uiconf_id/' . $this->playerUiConfId . $regen;

        $viewParams = array(
            "kalturaPlayerJavaScriptSrc" => $kalturaPlayerJavaScriptSrc,
            "ks" => $this->client->getKs(),
            "playerScriptRegenerate" => $this->playerScriptRegenerate,
            "assetsBlock" => $assetsBlock,
            "simuliveSummary" => $simuliveSummary,
            "partnerId" => $pid,
            "uiconfId" => $this->playerUiConfId,
            "uniqueUserId" => $this->uniqueUserId,
            "entryId" => $currentLiveEntry->id,
            "serviceUrl" => KalturaImportUtilsConfig::KALTURA_ACCOUNTS[$pid]['serviceUrl'],
            "disableAtt" => $this->disableAtt
        );
        echo self::renderPhpFile('playback-test-template.php', $viewParams);
    }
    private static function renderPhpFile($filename, $vars = null)
    {
        if (is_array($vars) && !empty($vars)) {
            extract($vars);
        }
        ob_start();
        include $filename;
        return ob_get_clean();
    }
    public function log($message)
    {
        if (KalturaImportUtilsConfig::SHOULD_LOG) {
            $errline = date('Y-m-d H:i:s') . ' ' .  $message . "<br />" . PHP_EOL;
            file_put_contents(KalturaImportUtilsConfig::ERROR_LOG_FILE, $errline, FILE_APPEND);
        }
    }
}

foreach (KalturaImportUtilsConfig::KALTURA_ACCOUNTS as $pid => $configs) {
    if ($configs['environment'] == KalturaImportUtilsConfig::ENVIRONMENT_NAME) {
        $instance = new ScheduleSimuliveNow();
        $instance->run($pid, $configs['adminSecret']);
        unset($instance);
    }
}
