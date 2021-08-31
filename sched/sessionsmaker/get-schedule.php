<?php
set_time_limit(0);
ini_set('memory_limit', '1024M');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
date_default_timezone_set('America/Chicago'); //make sure to set the expected timezone
require '../../vendor/autoload.php';
require '../../config.php';
require '../../kalturaApiUtils.php';
require '../../executionTime.php';

use Kaltura\Client\Configuration as KalturaConfiguration;
use Kaltura\Client\Client as KalturaClient;
use Kaltura\Client\ILogger;
use Kaltura\Client\Enum\{EntryStatus, SessionType, PlaybackProtocol};
use Kaltura\Client\Plugin\Schedule\Enum\LiveStreamScheduleEventOrderBy;
use Kaltura\Client\Plugin\Schedule\Enum\ScheduleEventStatus;
use Kaltura\Client\Plugin\Schedule\SchedulePlugin;
use Kaltura\Client\Plugin\Schedule\Type\LiveStreamScheduleEventFilter;
use Kaltura\Client\Type\{FilterPager, LiveStreamConfiguration, LiveStreamEntry, LiveStreamEntryFilter};

class GetLiveSchedules implements ILogger
{
	const OUTPUT_FILE = './sessions.js';
	const DEBUG_PRINTS = true;
	const CYCLE_SIZES = 500;

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
		$this->ks = $this->client->session->start($secret, 'CreateSessionsJsonForCalendar', SessionType::ADMIN, $pid, KalturaImportUtilsConfig::KS_EXPIRY_TIME, $privileges);
		$this->client->setKs($this->ks);
		$simuliveAcpId = KalturaImportUtilsConfig::KALTURA_ACCOUNTS[$pid]['simuliveACP'];

		//get the list of all scheduledEvents
		$schedulePlugin = SchedulePlugin::get($this->client);
		$sefilter = new LiveStreamScheduleEventFilter();
		$sefilter->orderBy = LiveStreamScheduleEventOrderBy::START_DATE_ASC;
		$sefilter->statusEqual = ScheduleEventStatus::ACTIVE;
		$pager = new FilterPager();
		$pager->pageSize = GetLiveSchedules::CYCLE_SIZES;
		$pager->pageIndex = 1;
		$livepager = new FilterPager();
		$livepager->pageSize = 1;
		$livepager->pageIndex = 1;
		$schedules = array();
		$entriesSchedulesTemp = null;
		$livefilter = new LiveStreamEntryFilter();
		$livefilter->statusNotEqual = EntryStatus::DELETED;
		$livefilter->categoryAncestorIdIn = '209848453';
		$this->log('get shcedules...', true);
		$entriesSchedulesTemp = $this->presistantApiRequest($schedulePlugin->scheduleEvent, 'listAction', array($sefilter, $pager), 5);
		$sessionsCount = 1;
		while (isset($entriesSchedulesTemp->objects) && count($entriesSchedulesTemp->objects) > 0) {
			foreach ($entriesSchedulesTemp->objects as $scheduledEvent) {
				if (!isset($scheduledEvent->templateEntryId) || $scheduledEvent->templateEntryId == null)
					continue;
				$livefilter->idEqual = $scheduledEvent->templateEntryId;
				$liveEntryList = $this->presistantApiRequest($this->client->getLiveStreamService(), 'listAction', array($livefilter, $livepager), 5);
				if (count($liveEntryList->objects) == 0) continue;
				$liveEntry = $liveEntryList->objects[0];
				$this->log($sessionsCount . ') live entry: ' . $liveEntry->id . ', vod source: ' . $scheduledEvent->sourceEntryId . ' scheduledEvent [' . $scheduledEvent->getKalturaObjectType() . ']: ' . $scheduledEvent->id, true);
				++$sessionsCount;
				$specialTags = $this->getSpecialTags($liveEntry->tags);
				$streamType = $this->getSteramType($liveEntry->adminTags);
				$streamTypeColor = '#000';
				if ($streamType == 'live')
					$streamTypeColor = '#E71A43';
				if ($streamType == 'simulive')
					$streamTypeColor = '#9BC8F9';
				if (count(array_intersect(array('vod_2_webcast', 'lobby_only', 'loby_only', 'no_schedule'), $specialTags)) > 0) continue; // skip non Kaltura related sessions
				$iskeynote = (count(array_intersect(array('keynotes'), $specialTags)) > 0);
				$entryDesc = '';
				$isSimulive = false;
				if (isset($scheduledEvent->sourceEntryId) && $scheduledEvent->sourceEntryId != null) {
					$entryDesc = '<strong>Simulive Source ID:</strong> ' . $scheduledEvent->sourceEntryId . '<br />' . PHP_EOL;
					$isSimulive = true;
				}
				$entryDesc .= $liveEntry->description;
				$entrCats = $liveEntry->categories;
				if ($entryDesc == null || $entryDesc == '')
					$entryDesc = '<span class="schedulebodypop"></span>';
				$streamHls = $this->getStreamHls($liveEntry);
				$scheduleObj = array(
					'id' => "{$scheduledEvent->id}",
					'calendarId' => '1',
					'title' => $liveEntry->referenceId . ' - ' . $liveEntry->name, // . '[' . $entrCats . ']',
					'body' => $entryDesc,
					'category' => 'time',
					'dueDateClass' => '',
					'location' => $liveEntry->id,
					'start' => $this->timestamp2datestr($scheduledEvent->startDate),
					'end' => $this->timestamp2datestr($scheduledEvent->endDate),
					'isReadOnly' => true,
					'bgColor' => '#ededed', //$this->getTagColor($specialTags),
					'isVisible' => true,
					'primaryHls' => $streamHls['url'],
					'backupHls' => $streamHls['backupUrl'],
					'sessionId' => $liveEntry->referenceId,
					'preStart' => $scheduledEvent->preStartTime,
					'postEnd' => $scheduledEvent->postEndTime,
					'isSimulive' => $isSimulive,
					'isFocused' => $iskeynote,
					'borderColor' => $streamTypeColor
				);
				array_push($schedules, $scheduleObj);
			}
			++$pager->pageIndex;
			$entriesSchedulesTemp = $this->presistantApiRequest($schedulePlugin->scheduleEvent, 'listAction', array($sefilter, $pager), 5);
		}
		file_put_contents(GetLiveSchedules::OUTPUT_FILE, 'var ScheduleList = ' . json_encode($schedules, JSON_PRETTY_PRINT) . ';');
		$this->log('completed generating sessions file', true);
	}
	private function getStreamHls($liveEntry)
	{
		foreach ($liveEntry->liveStreamConfigurations as $streamConfig) {
			if ($streamConfig->protocol == PlaybackProtocol::APPLE_HTTP) {
				return array(
					'url' => $streamConfig->url,
					'backupUrl' => $streamConfig->backupUrl
				);
			}
		}
		return false;
	}
	private function getSteramType($adminTags)
	{
		$tagsArr = explode(',', $adminTags);
		$adminTagsArr = array();
		foreach ($tagsArr as $tag) {
			$adminTagsArr[] = trim($tag);
		}
		if (in_array('simulive', $adminTagsArr)) return 'simulive';
		if (in_array('live', $adminTagsArr)) return 'live';
		if (in_array('manual', $adminTagsArr))
			return 'live';
		else
			return 'na';
	}
	private function getTagColor($specialTags)
	{
		$sponsorContent = array('play_sponsored', 'sponsored_breakouts', 'sponsor', 'sponsored', 'sponsored_content');
		//$sponsorsNames = array('splunk', 'appdynamics', 'cognizant', 'sumo_logic', 'vmware', 'apptio', 'lumen', 'logz_io', 'blockchain', 'f5', 'divvycloud_by_rapid7', 'confluent', 'mcafee', 'crowdstrike', '2nd_watch', 'commvault', 'matillion', 'ibm', 'rackspace_technology', 'teradata', 'wipro', 'cohesity', 'red_hat', 'netscout', 'hashicorp', 'pagerduty', 'palo_alto_networks', 'cisco', 'databricks', 'capgemini', 'netapp', 'dynatrace', 'capital_one', 'rubrik', 'pwc', 'veritas_technologies', 'tata_consultancy_services', 'trend_micro', 'cloudreach', 'gitlab', 'slalom', 'druva', 'intel', 'snowflake_inc', 'datadog', 'veeam_software', 'verizon', 'new_relic', 'redis_labs', 'mongodb', 'accenture', 'salesforce', 'deloitte');
		$executiveContent = array('executive_summit_at_aws_re_invent', 'executive_live', 'executive_simulive');
		$pressLounge = array('press_lounge', 'press');
		$languagesContent = array('chinese', 'japanese', 'korean', 'italian', 'french', 'portuguese', 'spanish');
		$theCube = array('thecube', 'the_cube');
		$onAir = array('on_air');
		$leadership = array('leadership');
		$keynotes = array('keynotes');
		$embargo = array('embargo', 'breakouts_embargo');
		if (count(array_intersect($keynotes, $specialTags)) > 0) return '#0AD3FF';
		if (count(array_intersect($leadership, $specialTags)) > 0) return '#85E9FF';
		if (count(array_intersect($executiveContent, $specialTags)) > 0) return '#99ffcc';
		if (count(array_intersect($sponsorContent, $specialTags)) > 0) return '#F38BE4';
		if (count(array_intersect($pressLounge, $specialTags)) > 0) return '#04828B';
		if (count(array_intersect($theCube, $specialTags)) > 0) return '#C3979F';
		if (count(array_intersect($onAir, $specialTags)) > 0) return '#9B5965';
		//if (count(array_intersect($languagesContent, $specialTags)) > 0) return '#F0E5E7';
		if (count(array_intersect($embargo, $specialTags)) > 0) return '#d7e04a';
		/*if (count(array_intersect($sponsorsNames, $specialTags)) > 0)
			return '#F38BE4';
		else
			return '#ededed';*/
	}
	private function getSpecialTags($tags)
	{
		$tagsArr = explode(',', $tags);
		$speacialTags = array();
		foreach ($tagsArr as $tag) {
			if (strpos($tag, '__') !== false) {
				$speacialTags[] = trim(str_replace('__', '', $tag));
			}
		}
		return $speacialTags;
	}
	public function timestamp2datestr($timestamp, $datetimeFormat = 'Y-m-d\TH:i:sP')
	{
		$date = new \DateTime();
		$date = new \DateTime('now', new \DateTimeZone(KalturaImportUtilsConfig::EVENT_TIMEZONE));
		$date->setTimestamp($timestamp);
		return $date->format($datetimeFormat);
	}
	public function log($message, $consoleLog = false)
	{
		$errline = date('Y-m-d H:i:s') . ' ' .  $message . "\n";
		file_put_contents(KalturaImportUtilsConfig::ERROR_LOG_FILE, $errline, FILE_APPEND);
		if (GetLiveSchedules::DEBUG_PRINTS && $consoleLog) echo $message . PHP_EOL;
	}
	private function presistantApiRequest($service, $actionName, $paramsArray, $numOfAttempts)
	{
		$attempts = 0;
		$lastError = null;
		$response = null;
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
					$this->log("Error Processing API Action: " . $actionName);
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
			$this->log('======= API BREAKE =======' . PHP_EOL);
			$this->log('Message: ' . $lastError->getMessage() . PHP_EOL);
			$this->log('Last Kaltura client headers:' . PHP_EOL);
			$this->log(
				print_r(
					$this
						->client
						->getResponseHeaders()
				)
			);
			$this->log('===============================');
		}
		return $response;
	}
}
$executionTime = new ExecutionTime();
$executionTime->start();
foreach (KalturaImportUtilsConfig::KALTURA_ACCOUNTS as $pid => $configs) {
	if ($configs['environment'] == KalturaImportUtilsConfig::ENVIRONMENT_NAME) {
		$instance = new GetLiveSchedules();
		$instance->run($pid, $configs['adminSecret']);
		unset($instance);
	}
}
$executionTime->end();
if (GetLiveSchedules::DEBUG_PRINTS) echo PHP_EOL;
if (GetLiveSchedules::DEBUG_PRINTS) echo $executionTime;
