<?php
/**
 * Fritz!Box PHP tools CLI script to enable or disable answering machines
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
    $fritz = new fritzbox_api();
    $message='';
    // handle the CLI arguments or give a help message
    if (isset($argv[1]) && ($argv[1] == 0 || $argv[1] == 1 || $argv[1] == 2) )
    {
        $mode = (int)$argv[1];
    }
    else
    {
        if ( $fritz->config->getItem('logging') == 'console' )
        {
            echo '
            Enables or disables an answering machine (TAM) of a Fritz!Box
            
            Usage on UNIX systems:
            /path/to/php ' .  $argv[0] . ' {0|1} [optional: TAM]
            
            Usage on Windows systems:
            c:\path\to\php.exe ' .  $argv[0] . ' {0|1} [optional: TAM]
            
            0 disable the TAM
            1 enable the TAM
            
            The optional argument TAM and addresses the nth answering machine
            Defaults to 0, which is the TAM **600; 1 is the TAM **601 and so on
            
            Warning: A non existent TAM will not lead to an error message but adds a
            new TAM to the Fritz!Box, which will answer all incoming calls by default!
              ';
        }
        else
        {
            $fritz->logMessage($message . 'ERROR: Script was called without or with an invalid argument');
        }
        exit;
    }
    $tam = (isset($argv[2]) && $argv[2] >= 0 && $argv[2] <= 9) ? (int)$argv[2] : 0;

    if ($mode == 0 || $mode == 1)
    {
        $result=$fritz->setTamStatus($tam,$mode);

        if ( $result == $mode)
        {
            $message .= $mode == 1 ? 'TAM' . $tam . ' enabled' : 'TAM' . $tam . ' disabled';
        }
        else if ($result>=0)
        {
            $message .= 'ERROR: TAM' . $tam . ' status change failed, should be ' . $mode . ', but is ' . $result;
        }
        else
        {
            $message .= 'NOTICE: TAM' . $tam . ' status change could have failed, should be ' . $mode . ' now, but I don\'t now if it actually is. Check your check section in the script.';
        }
    } // mode = 0|1
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