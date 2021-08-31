<?php
class KalturaImportUtilsConfig
{
    const ENVIRONMENT_NAME = 'dev'; 
    // replace these with the environments (KMC accounts) API credentials
    //https://kmc.kaltura.com/index.php/kmcng/settings/integrationSettings
    const KALTURA_ACCOUNTS = array(
        40404040 => array(
            'environment' => 'envname',
            'adminSecret' => '01010101010101010101010101010101aaaa',
            'simuliveACP' => '30303030',
            'serviceUrl' => 'https://www.kaltura.com'
        ),
    );
    
    const SESSIONS_CSV_FILE = './sessions.csv';
    const FORCE_CREATE_NEW_SCHEDULES = false;
    const ONLY_UPDATE_METADATA = false;
    const REAPEAT_SESSION_CODE_EXTENSION = 'R1';
    
    const SPECIFIC_CODES_TO_UPDATE = array();
    const MEDIASPACE_INSTANCE_IDS = array('MediaSpace');
    const DEFAULT_THUMBNAIL_IMAGE_URL = 'URL-2-IMAGE-FILE';
    
    const EVENT_TIMEZONE = 'America/Chicago'; //valid PHP timezone - https://www.php.net/manual/en/timezones.php
    
    const CTA_SURVEY_BASE_URL = 'https://your-survey.system.com/sessionId='; //the session code will be concatenated to the end

    const ENTRIES_OWNER_USER_ID = 'Content_Uploader_Group';
    const ENTRIES_CO_EDITORS_USER_IDS = array('Admins');
    const SIMULIVE_VOD_SOURCE_HIDDEN_CATEGORY_NAME = 'VOD Sources for Simulive';
    
    const ENTRY_ADMINTAGS = 'googlesheet,kms-webcast-event';
    const ENTRY_ADMINTAGS_SIMULIVE = 'simulive';
    
    const SIMULIVE_PRE_START_TIME = 5 * 60; //minutes*60
    const SIMULIVE_POST_END_TIME = 5 * 60; //minutes*60
    
    const KS_EXPIRY_TIME = 86000; // Kaltura session length. Please note the script may run for a while so it mustn't be too short.
    const DEBUG_PRINTS = true; //Set to true if you'd like the script to output logging to the console (this is different from the KalturaLogger)
    const CYCLE_SIZES = 400; // Determines how many entries will be processed in each multi-request call - set it to whatever number works best for your server.
    const ERROR_LOG_FILE = './kaltura_logger.log'; //The name of the KalturaLogger export file
    const SHOULD_LOG = false; //if true will log all Kaltura calls into ERROR_LOG_FILE
}
