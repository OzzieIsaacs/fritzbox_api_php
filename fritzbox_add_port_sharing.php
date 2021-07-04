<?php
/**
 * Fritz!Box PHP tools CLI script enable port sharing port 80
 *
 * Check the config file fritzbox.conf.php!
 *
 * @author   Ozzie Fernandez Isaacs
 * @license  http://creativecommons.org/licenses/by-sa/3.0/de/ Creative Commons cc-by-sa
 * @version  0.4 2013-01-02
 * @package  Fritz!Box PHP tools
 *
 */
try
{
    // load the fritzbox_api class
    require_once('fritzbox_api.class.php');
    $fritz = new fritzbox_api();
    $message='';
    $server = $fritz->config->getItem("server");
    $fritz->setPortsharing($server,80,80,80);
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