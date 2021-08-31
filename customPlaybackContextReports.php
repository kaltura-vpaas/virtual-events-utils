<?php
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Jerusalem'); //make sure to set the expected timezone
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

/*
reports IDs - 
* 1108 - 
    entry_id,playback_context,count_plays,count_loads,sum_view_period,avg_view_drop_off,avg_completion_rate,count_plays_25,count_plays_50,count_plays_75,count_plays_100 
* 1109 - 
    entry_id,playback_context,country,count_plays,count_loads,sum_view_period,avg_view_drop_off,avg_completion_rate,count_plays_25,count_plays_50,count_plays_75,count_plays_100
* 1111 - 
    entry_id,playback_context,browser,count_plays,count_loads,sum_view_period
* 1112 - 
    entry_id,playback_context,operating_system,count_plays,count_loads,sum_view_period

examples:
http://www.kaltura.com/api_v3/service/report/action/getCsvFromStringParams/id/1109/ks/<ks>/params/from_date_id=20200901;to_date_id=20200930
http://www.kaltura.com/api_v3/service/report/action/getCsvFromStringParams/id/1109/ks/<ks>/params/from_date_id=20200901;to_date_id=20200930;timezone_offset=-240
*/
$reportId = 1109;
$reportStartDate = '20210101'; //The month to start exporting report data from. Format: YYYYMMDD 
$reportEndDate = '20210505'; //The month to end exporting report data on. Format: YYYYMMDD
$reportTimeZoneOffset = -180; //negative 180 is Israel timezone

$partnerId = null; // your Kaltura account ID (https://kmc.kaltura.com/index.php/kmcng/settings/integrationSettings)
$adminSecret = null; // your Kaltura account API secret key (https://kmc.kaltura.com/index.php/kmcng/settings/integrationSettings)
foreach (KalturaImportUtilsConfig::KALTURA_ACCOUNTS as $pid => $configs) {
    if ($configs['environment'] == KalturaImportUtilsConfig::ENVIRONMENT_NAME) {
        $partnerId = $pid;
        $adminSecret = $configs['adminSecret'];
    }
}
if ($partnerId == null || $adminSecret == null) {
    echo 'Please configure the script first!' . PHP_EOL;
    die();
}

$userId = 'UniqueUserId';
$expiry = 1200; //how long should the session be usable? Can be set to 1 second up to 10 years, make sure to use the shortest time needed for your report to download. 
//Learn more about Kaltura Session creation and security considerations: https://developer.kaltura.com/api-docs/VPaaS-API-Getting-Started/Kaltura_API_Authentication_and_Security.html
//To tighten the security of this session, consider adding the actionslimit privilege and set it to to 1 per report download, that way the KS will only be usable once
//If the IP of the client downloading the report is known, consider using iprestrict and tie the session to a specific IP
$appName = 'yourappname'; //used to designate your application name
$appDomain = 'yourdomain.com'; // the app domain to track this session to
$privileges = "appid:$appName-$appDomain,urirestrict:/api_v3/service/report/action/getCsvFromStringParams*";
// generate a Kaltura Session restricted to only get an analytics report
$KalturaSessionStartUrl = 'https://www.kaltura.com/api_v3/service/session/action/start/format/1?type=2';
$context = make_post_request_context(array(
    'secret' => $adminSecret,
    'userId' => $userId,
    'partnerId' => $partnerId,
    'expiry' => $expiry,
    'privileges' => $privileges
));
$ks = json_decode(file_get_contents($KalturaSessionStartUrl, false, $context));

// construct the analytics report download URL
$reportParams = "from_date_id=$reportStartDate;to_date_id=$reportEndDate;timezone_offset=$reportTimeZoneOffset";
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
