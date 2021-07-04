<?php
/**
 * Fritz!Box PHP tools CLI script read dsl statistics
 *
 * Must be called via a command line, shows a help message if called without any or an invalid argument
 * Can log to the console or a logfile or be silent
 * new in v0.2: Can handle remote config mode via https://example.dyndns.org
 * new in v0.3: Refactored code to match API version 0.5
 *
 * Check the config file fritzbox.conf.php!
 *
 * @author   Gregor Nathanael Meyer <Gregor [at] der-meyer.de>
 * @license  http://creativecommons.org/licenses/by-sa/3.0/de/ Creative Commons cc-by-sa
 * @version  0.4 2013-01-02
 * @package  Fritz!Box PHP tools
 *
 * Geändert: 19.01.2015 mit query.lua ab Firmware xxx.04.88
 * Geändert: 27.06.2015 mit lua ab Firmware xxx.06.25
 */

try
{
    // load the fritzbox_api class
    require_once('fritzbox_api.class.php');
    require_once('helper.php');
    
    if (checkDateStatus())
    {
        exit;
    }
    
    $fritz = new fritzbox_api();
    $message='';

    $formfields = array(
        'getpage' => '/data.lua',
        'page' => 'netCnt',
        'lang' => 'de',
        'xhr' =>'1'
    );

    $output = $fritz->doPostForm($formfields);
    preg_match_all('/class="time">([0-9]+:[0-9]+)<.*?gesamt\(MB\)".class="vol vol-sum">.*?empfangen\(MB\)" class="vol.*?class="conn">([0-9]+)</s', $output, $online);
    preg_match_all('/"Yesterday":{"BytesSentHigh":"(\d+)","BytesSentLow":"(\d+)","BytesReceivedHigh":"(\d+)","BytesReceivedLow":"(\d+)"}/', $output, $volume);
    $upload = intval(round(($volume[1][0]*4294967296+$volume[2][0])/1000000, 0));
    $download = intval(round(($volume[3][0]*4294967296+$volume[4][0])/1000000, 0));
    $gesamt = $upload + $download;
    // put data to database from field yesterday
    // datum  | onlinezeit | gesamt Daten | download | upload | connections
    addDataset(array(date('Y-m-d',strtotime('-1 days')), $online[1][1],$gesamt, $download, $upload, $online[2][1]));
}

catch (Exception $e)
{
    if (!empty($message)) {
        $message .= $e->getMessage();
    }
    else{
        $message = $e->getMessage();
    }
}

// log the result
if ( isset($fritz) && is_object($fritz) && get_class($fritz) == 'fritzbox_api' )
{
    $fritz->logMessage($message);
}
else
{
    echo($message);
}
$fritz = null; // destroy the object to log out

?>
