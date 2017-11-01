<?php
/**
 * Fritz!Box PHP tools CLI script to download the online statistics
 *
 * Check the config file fritzbox.conf.php!
 *
 * @author   Ozzie Isaacs>
 * @license  http://creativecommons.org/licenses/by-sa/3.0/de/ Creative Commons cc-by-sa
 * @package  Fritz!Box PHP tools
 *
 */

try
{
    // load the fritzbox_api class
    require_once('fritzbox_api.class.php');
        
    $fritz = new fritzbox_api();
    $message='';

    $output = $fritz->getOnlineCounterStatistic();
    print_r($output);
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