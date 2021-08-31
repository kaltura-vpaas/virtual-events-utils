<?php
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Jerusalem'); // Make sure to set this to your desired timezone (https://www.php.net/manual/en/timezones.php)
require './config.php';

function make_post_request_context($params)
{
    $postdata = http_build_query($params);
    $opts = array(
        'http' =>
        array(
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $postdata
        )
    );
    $context = stream_context_create($opts);
    return $context;
}

const REPORT_SESSION_ATTENDANCE = 3009;
const REPORT_CERTIFICATE_OF_COMPLETION = 3010;
const REPORT_ADD_TO_CALENDAR = 3006;

/*
Types of reports in this example:

* REPORT_SESSION_ATTENDANCE (3009) -
    CSV Output Columns: 
        user,email,entry_id,session_id,entry_name,view_time
    Inputs: 
        from_date (Mandatory) - The date to start exporting report data from. Format: unix timestamp
        to_date (Mandatory) - The date to end exporting report data on. Format: unix timestamp
    Notes:   
        View time is the total time the user spent watching this specific video (entry ID)
        If the user watched the same video several times, View time will show the aggregate time in minutes the user spent watching this video (e.g. If a user watched a specific video 5 times for a duration of 10 minutes each time, View time will show 50)

* REPORT_CERTIFICATE_OF_COMPLETION (3010) -
    CSV Output Columns: 
        Date,Entry Id,Session Id,Entry Name,VOD Duration (min),View Time (min)
    Inputs: 
        from_date (Mandatory) - The date to start exporting report data from. Format: unix timestamp
        to_date (Mandatory) - The date to end exporting report data on. Format: unix timestamp
        user_id (Mandatory) - User ID to get data on (the user.id field from the API)

* REPORT_ADD_TO_CALENDAR (3006) -
    CSV Output Columns: entry_id, user_id, email, count_add_to_calendar_clicked
    Inputs: 
        from_date_id (Mandatory) - The date to start exporting report data from. Format: YYYYMMDD
        to_date_id (Mandatory) - The date to end exporting report data on. Format: YYYYMMDD
        entries_ids (Optional) - comma seperated list of entry IDs to query for. If empty string, all entries in the account will be returned.
*/
$partnerId = null; // your Kaltura account ID (https://kmc.kaltura.com/index.php/kmcng/settings/integrationSettings)
$adminSecret = null; // your Kaltura account API secret key (https://kmc.kaltura.com/index.php/kmcng/settings/integrationSettings)
foreach (KalturaImportUtilsConfig::KALTURA_ACCOUNTS as $pid => $configs) {
    if ($configs['environment'] == KalturaImportUtilsConfig::ENVIRONMENT_NAME) {
        $partnerId = $pid;
        $adminSecret = $configs['adminSecret'];
    }
}

$reportUserId = 'adminReportsDownloader'; // a user name this script will run by (used for audit trailing / report issues)
$appName = 'customerAnalyticsApp'; // some string representing your application name (used for audit trailing / report issues)
$appDomain = 'customerdomain.com'; // the app domain to track this session to (used for audit trailing / report issues)

if ($partnerId == null || $adminSecret == null) {
    echo 'Please configure the script first!' . PHP_EOL;
    die();
}

$reportId = REPORT_ADD_TO_CALENDAR; // which report type to download
$userIdToQuery = 'someUniqueUserId'; // MANDATORY parameter for report type REPORT_CERTIFICATE_OF_COMPLETION
$entryIdsToQuery = ''; // OPTIONAL (can be empty string) parameter for report type REPORT_ADD_TO_CALENDAR

// For $reportId == REPORT_ADD_TO_CALENDAR, use the following dates format:
$reportStartDateStr = '20210101'; //The date to start exporting report data from. Format: YYYYMMDD
$reportEndDateStr = '20210501'; //The date to end exporting report data on. Format: YYYYMMDD
$reportTimeZoneOffset = -180; //negative 180 is Israel timezone

// For $reportId == REPORT_SESSION_ATTENDANCE or REPORT_CERTIFICATE_OF_COMPLETION, use the following dates format:
$dateFormatStr = 'Ymd';
$reportStartDate = DateTime::createFromFormat($dateFormatStr, $reportStartDateStr, new DateTimeZone($reportTimeZoneOffset / 60));
$reportStartDateTimestamp = $reportStartDate->format('U'); //'1618066800'; //The date to start exporting report data from. Format: unix timestamp
$reportEndDate = DateTime::createFromFormat($dateFormatStr, $reportEndDateStr, new DateTimeZone($reportTimeZoneOffset / 60));
$reportEndDateTimestamp = $reportEndDate->format('U'); //'1619557149'; //The date to end exporting report data on. Format: unix timestamp

// generate a Kaltura Session restricted to only get an analytics report
$expiry = 1200; //how long should the session be usable? Can be set to 1 second up to 10 years, make sure to use the shortest time needed for your report to download. 
//Learn more about Kaltura Session creation and security considerations: https://developer.kaltura.com/api-docs/VPaaS-API-Getting-Started/Kaltura_API_Authentication_and_Security.html
//To tighten the security of this session, consider adding the actionslimit privilege and set it to to 1 per report download, that way the KS will only be usable once
//If the IP of the client downloading the report is known, consider using iprestrict and tie the session to a specific IP
$privileges = "appid:$appName-$appDomain,urirestrict:/api_v3/service/report/action/getCsvFromStringParams*";

$KalturaSessionStartUrl = 'https://www.kaltura.com/api_v3/service/session/action/start/format/1?type=2';
$context = make_post_request_context(array(
    'secret' => $adminSecret,
    'userId' => $reportUserId,
    'partnerId' => $partnerId,
    'expiry' => $expiry,
    'privileges' => $privileges
));
$ks = json_decode(file_get_contents($KalturaSessionStartUrl, false, $context));

// construct the analytics report download URL
$reportParams = '';
switch ($reportId) {
    case REPORT_SESSION_ATTENDANCE:
        $reportParams = "from_date=$reportStartDateTimestamp;to_date=$reportEndDateTimestamp;";
        break;

    case REPORT_CERTIFICATE_OF_COMPLETION:
        $reportParams = "from_date=$reportStartDateTimestamp;to_date=$reportEndDateTimestamp;user_id=$userIdToQuery;";
        break;

    case REPORT_ADD_TO_CALENDAR:
        $reportParams = "from_date_id=$reportStartDateStr;to_date_id=$reportEndDateStr;timezone_offset=$reportTimeZoneOffset;entries_ids=$entryIdsToQuery;";
        break;
}

$csvReportUrl = "https://www.kaltura.com/api_v3/service/report/action/getCsvFromStringParams/format/1";
echo 'Executing: ' . $csvReportUrl . ' with POST params...' . PHP_EOL;
$context = make_post_request_context(array(
    'id' => $reportId,
    'params' => $reportParams,
    'ks' => $ks
));
$csvReportData = file_get_contents($csvReportUrl, false, $context);
var_dump($csvReportData);

/*
parse the csv into a PHP array
$lines = explode("\n", $csvReportData);
$headers = str_getcsv(array_shift($lines));
$data = array();
foreach ($lines as $line) {
    $row = array();
    foreach (str_getcsv($line) as $key => $field)
        $row[$headers[$key]] = $field;
    $row = array_filter($row);
    $data[] = $row;
}
var_dump($data);
*/
