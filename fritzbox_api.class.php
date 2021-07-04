<?php
/**
 * Fritz!Box API - A simple wrapper for automated changes in the Fritz!Box Web-UI
 * 
 * handles the new secured login/session system and implements a cURL wrapper
 * new in v0.2: Can handle remote config mode via https://example.dyndns.org
 * new in v0.3: New method doGetRequest handles GET-requests
 * new in v0.4: Added support for the new .lua forms like the WLAN guest access settings
 * new in v0.5: added support for the new .lua-loginpage in newest Fritz!OS firmwares and refactored the code
 * 
 * @author   Gregor Nathanael Meyer <Gregor [at] der-meyer.de>
 * @license  http://creativecommons.org/licenses/by-sa/3.0/de/ Creative Commons cc-by-sa
 * @version  0.5.0b7 2013-01-02
 * @package  Fritz!Box PHP tools
 */

/* A simple usage example
 *
 * try
 * { 
 *   // load the fritzbox_api class
 *   require_once('fritzbox_api.class.php');
 *   $fritz = new fritzbox_api();
 *
 *   // init the output message
 *   $message = date('Y-m-d H:i') . ' ';
 *   
 *   // update the setting
 *   $formfields = array(
 *     'telcfg:command/Dial'      => '**610',
 *   );
 *   $fritz->doPostForm($formfields);
 *   $message .= 'Phone ' . $dial . ' ringed.';
 * }
 * catch (Exception $e)
 * {
 *   $message .= $e->getMessage();
 * }
 *
 * // log the result
 * $fritz->logMessage($message);
 * $fritz = null; // destroy the object to log out
 */
 
/**
 * the main Fritz!Box API class
 *
 */
class fritzbox_api {
  /**
    * @var  object  config object
    */
  public $config = array();
  
  /**
    * @var  string  the session ID, set by method initSID() after login
    */
  protected $sid = '0000000000000000';
  
  
  /**
    * the constructor, initializes the object and calls the login method
    * 
    * @access public
    */
  public function __construct($password = false,$user_name = false,$fritzbox_ip = 'fritz.box')
  {
    // init the config object
    $this->config = new fritzbox_api_config();
    
    // try autoloading the config
    if (file_exists(__DIR__ . '/fritzbox_user.conf.php') && is_readable(__DIR__ . '/fritzbox_user.conf.php') ) {
        require_once(__DIR__ . '/fritzbox_user.conf.php');
    }

    // set FRITZ!Box-IP and URL
    $this->config->setItem('fritzbox_ip',$fritzbox_ip);

    // check if login on local network (fritz.box) or via a dynamic DNS-host
    if ($fritzbox_ip != 'fritz.box'){
        $this->config->setItem('enable_remote_config',true);
        $this->config->setItem('remote_config_user',$user_name);
        $this->config->setItem('remote_config_password',$password);
        $this->config->setItem('fritzbox_url', 'https://'.$this->config->getItem('fritzbox_ip'));
    } else {
        $this->config->setItem('enable_remote_config',false);
        if($user_name != false){
            $this->config->setItem('username',$user_name);
        }
        if($password != false){
            $this->config->setItem('password',$password);
        }
        $this->config->setItem('fritzbox_url', 'http://' . $this->config->getItem('fritzbox_ip'));
    }

    // make some config consistency checks
    if ( $this->config->getItem('enable_remote_config') === true ){
        if ( !$this->config->getItem('remote_config_user') || !$this->config->getItem('remote_config_password') ){
          $this->error('ERROR: Remote config mode enabled, but no username or no password provided');
        }
    }
    else {
        $this->config->setItem('old_remote_config_user', null);
        $this->config->setItem('old_remote_config_password', null);
    }
    $this->sid = $this->initSID();
  }
  
  
  /**
    * the destructor just calls the logout method
    * 
    * @access public
    */
  public function __destruct()
  {
    $this->logout();
  }
  
  
  /**
    * do a POST request on the box
    * the main cURL wrapper handles the command
    * 
    * @param  array  $formfields    an associative array with the POST fields to pass
    * @return string                the raw HTML code returned by the Fritz!Box
    */
  public function doPostForm($formfields = array())
  {
    $ch = curl_init();

    if ($this->sid != '0000000000000000')
    {
        $formfields['sid'] = $this->sid;
    }
    curl_setopt($ch, CURLOPT_URL, $this->config->getItem('fritzbox_url') . $formfields['getpage']);
      
    unset($formfields['getpage']);
      
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    if ( $this->config->getItem('enable_remote_config') == true )
    {
        // set name of SSL-certificate (must be same as remote-hostname (dynDNS) of FRITZ!Box) and remove port in address if alternate port is given
        if (strpos($this->config->getItem('fritzbox_ip'),":")){
            $ssl_cert_fritzbox = explode(":", $this->config->getItem('fritzbox_ip'));
            $ssl_cert_fritzbox = $ssl_cert_fritzbox[0];
        } else {
            $ssl_cert_fritzbox = $this->config->getItem('fritzbox_ip');
        }

        // set SSL-options and path to certificate
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CAINFO, '/etc/ssl/certs/'.$ssl_cert_fritzbox.'.pem');
        curl_setopt($ch, CURLOPT_CAPATH, '/etc/ssl/certs');

        // support for pre FRITZ!OS 5.50 remote config
        if (!$this->config->getItem('use_lua_login_method')){
        curl_setopt($ch, CURLOPT_USERPWD, $this->config->getItem('remote_config_user') . ':' . $this->config->getItem('remote_config_password'));
        }
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($formfields));
    $output = curl_exec($ch);
    curl_close($ch);
    return $output;
  }

  public function doPostFile($formfields = array(), $filefileds = array())
  {  
    $ch = curl_init();
   
    // add the sid, if it is already set
    if ($this->sid != '0000000000000000')
    {
        // 'sid' MUST be the first POST variable!!! (otherwise it will not work!!)
        // therfore we use array_merge to ensuere the foreach outputs 'sid' fist
        $formfields = array_merge(array('sid' => $this->sid), $formfields);
        //$formfields['sid'] = $this->sid;
    }   
    curl_setopt($ch, CURLOPT_URL, $this->config->getItem('fritzbox_url') . '/cgi-bin/firmwarecfg'); 
    curl_setopt($ch, CURLOPT_POST, 1);
    
    // enable for debugging:
    //curl_setopt($ch, CURLOPT_VERBOSE, TRUE); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    
    // if filefileds not specified ('@/path/to/file.xml;type=text/xml' works fine)
    if(empty( $filefileds )) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $formfields); // http_build_query
    }
    // post calculated raw data
    else {
        $header = $this->_create_custom_file_post_header($formfields, $filefileds);
        curl_setopt($ch, CURLOPT_HTTPHEADER , array(
            'Content-Type: multipart/form-data; boundary=' . $header['delimiter'], 'Content-Length: ' . strlen($header['data']) )
            );

        curl_setopt($ch, CURLOPT_POSTFIELDS, $header['data']);
    }

    $output = curl_exec($ch);

    // curl error
    if(curl_errno($ch)) {
        $this->error(curl_error($ch)." (".curl_errno($ch).")");
    }

    // finger out an error message, if given
    preg_match('@<p class="ErrorMsg">(.*?)</p>@is', $output, $matches);
    if (isset($matches[1]))
    {
        $this->error(str_replace('&nbsp;', ' ', $matches[1]));
    }

    curl_close($ch);
    return $output;
  }

  private function _create_custom_file_post_header($postFields, $fileFields) {
        // form field separator
        $delimiter = '-------------' . uniqid();

        /*
        // file upload fields: name => array(type=>'mime/type',content=>'raw data')
        $fileFields = array(
            'file1' => array(
                'type' => 'text/xml',
                'content' => '...your raw file content goes here...',
                'filename' = 'filename.xml'
            ),
        );
        // all other fields (not file upload): name => value
        $postFields = array(
            'otherformfield'   => 'content of otherformfield is this text',
        );
        */

        $data = '';

        // populate normal fields first (simpler)
        foreach ($postFields as $name => $content) {
           $data .= "--" . $delimiter . "\r\n";
            $data .= 'Content-Disposition: form-data; name="' . urlencode($name) . '"';
            $data .= "\r\n\r\n";
            $data .= $content;
            $data .= "\r\n";
        }
        // populate file fields
        foreach ($fileFields as $name => $file) {
            $data .= "--" . $delimiter . "\r\n";
            // "filename" attribute is not essential; server-side scripts may use it
            $data .= 'Content-Disposition: form-data; name="' . urlencode($name) . '";' .
                     ' filename="' . $file['filename'] . '"' . "\r\n";

            //$data .= 'Content-Transfer-Encoding: binary'."\r\n";
            // this is, again, informative only; good practice to include though
            $data .= 'Content-Type: ' . $file['type'] . "\r\n";
            // this endline must be here to indicate end of headers
            $data .= "\r\n";
            // the file itself (note: there's no encoding of any kind)
            $data .= $file['content'] . "\r\n";
        }
        // last delimiter
        $data .= "--" . $delimiter . "--\r\n";

        return array('delimiter' => $delimiter, 'data' => $data);
    }
  
  /**
    * do a GET request on the box
    * the main cURL wrapper handles the command
    * 
    * @param  array  $params    an associative array with the GET params to pass
    * @return string            the raw HTML code returned by the Fritz!Box
    */
  public function doGetRequest($params = array())
  {
    // add the sid, if it is already set
    if ($this->sid != '0000000000000000')
    {
      $params['sid'] = $this->sid;
    }    
  
    $ch = curl_init();
    $getpage = ''.$params['getpage'];
    unset($params['getpage']);
    $query=$this->config->getItem('fritzbox_url') . $getpage . '?' . http_build_query($params);

    if ($this->config->getItem('requests')) {
        $this->logMessage($query);
    }
    curl_setopt($ch, CURLOPT_URL, $query);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPGET, 1);
    if ( $this->config->getItem('enable_remote_config') )
    {
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

      // support for pre FRITZ!OS 5.50 remote config
      if ( !$this->config->getItem('use_lua_login_method') )
      {
        curl_setopt($ch, CURLOPT_USERPWD, $this->config->getItem('remote_config_user') . ':' . $this->config->getItem('remote_config_password'));
      }
    }
    $output = curl_exec($ch);
    curl_close($ch);
    return $output;
  }
  
  
  /**
    * the login method, handles the secured login-process
    * newer firmwares (xx.04.74 and newer) need a challenge-response mechanism to prevent Cross-Site Request Forgery attacks
    * see http://www.avm.de/de/Extern/Technical_Note_Session_ID.pdf for details
    * 
    * @return string                a valid SID, if the login was successful, otherwise throws an Exception with an error message
    */
    protected function initSID()
    {
        // determine, wich login type we have to use
        if ( $this->config->getItem('use_lua_login_method') == true )
        {
            $loginpage = '/login_sid.lua';
        }
        else
        {
            $loginpage = '../html/login_sid.xml';
        }
    
        // read the current status
        $session_status_simplexml = simplexml_load_string($this->doGetRequest(array('getpage' => $loginpage)));
    
        if ( !is_object($session_status_simplexml) || get_class($session_status_simplexml) != 'SimpleXMLElement' )
        {
            $this->error('Response of initialization call ' . $loginpage . ' in ' . __FUNCTION__ . ' was not xml-formatted.');
        }
    
        // perhaps we already have a SID (i.e. when no password is set)
        if ( $session_status_simplexml->SID != '0000000000000000' )
        {
            return $session_status_simplexml->SID;
        }
        // we have to login and get a new SID
        else
        {
            // the challenge-response magic, pay attention to the mb_convert_encoding()
            $challenge = $session_status_simplexml->Challenge;
      
            // do the login
            $formfields = array(
                'getpage' => $loginpage,
            );
            if ( $this->config->getItem('use_lua_login_method') )
            {
                if ( $this->config->getItem('enable_remote_config') )
                {
                    $formfields['username'] = $this->config->getItem('remote_config_user');
                    $response = $challenge . '-' . md5(mb_convert_encoding($challenge . '-' . $this->config->getItem('remote_config_password'), "UCS-2LE", "UTF-8"));
                }
                else
                {
                    if ( $this->config->getItem('username') )
                    {
                        $formfields['username'] = $this->config->getItem('username');
                    }
                    $response = $challenge . '-' . md5(mb_convert_encoding($challenge . '-' . $this->config->getItem('password'), "UCS-2LE", "UTF-8"));
                }
                $formfields['response'] = $response;
            }
            else
            {
                $response = $challenge . '-' . md5(mb_convert_encoding($challenge . '-' . $this->config->getItem('password'), "UCS-2LE", "UTF-8"));
                $formfields['login:command/response'] = $response;
            }
            $output = $this->doPostForm($formfields);

            // finger out the SID from the response
            $session_status_simplexml = simplexml_load_string($output);
            if ( !is_object($session_status_simplexml) || get_class($session_status_simplexml) != 'SimpleXMLElement' )
            {
                $this->error('Response of login call to ' . $loginpage . ' in ' . __FUNCTION__ . ' was not xml-formatted.');
            }
            if ( $session_status_simplexml->SID != '0000000000000000' )
            {
                return (string)$session_status_simplexml->SID;
            }
            else
            {
                $this->error('ERROR: Login failed with an unknown response.');
            }
        }
    }
  
  
    /**
    * the logout method just sends a logout command to the Fritz!Box
    *
    */
    protected function logout()
    {
        if ( $this->config->getItem('use_lua_login_method') == true )
        {
            $this->doGetRequest(array('getpage' => '/home/home.lua', 'logout' => '1'));
        }
        else
        {
            $formfields = array(
                'getpage'                 => '../html/de/menus/menu2.html',
                'security:command/logout' => 'logout',
            );
            $this->doPostForm($formfields);
        }
    }
  
  
    /**
    * the error method just throws an Exception
    *
    * @param  string   $message     an error message for the Exception
    */
    public function error($message = null)
    {
        throw new Exception($message);
    }
  
  
    /**
    * a getter for the session ID
    *
    * @return string                $this->sid
    */
    public function getSID()
    {
        return $this->sid;
    }
  
    /**
    * log a message
    *
    * @param  $message  string  the message to log
    */
  public function logMessage($message)
  {
    if ( $this->config->getItem('newline') == false )
    {
      $this->config->setItem('newline', (PHP_OS == 'WINNT') ? "\r\n" : "\n");
    }
  
    if ( $this->config->getItem('logging') == 'console' )
    {
      echo date('Y-m-d H:i') . ' ' . $message . $this->config->getItem('newline');
    }
    else if ( $this->config->getItem('logging') == 'silent' || $this->config->getItem('logging') == false )
    {
      // do nothing
    }
    else
    {
      if ( is_writable($this->config->getItem('logging')) || is_writable(dirname($this->config->getItem('logging'))) )
      {
        file_put_contents($this->config->getItem('logging'), $message . $this->config->getItem('newline'), FILE_APPEND);
      }
      else
      {
        echo('Error: Cannot log to non-writeable file or dir: ' . $this->config->getItem('logging'));
      }
    }
  }
  
    /**
    * set tam status
    * 
    * @param  int  $tam             telephone answering machine no to switch on / off
    * @param  bool $mode            new status of telephone answering machine
    * @return string                -1 -> error, 0/1 new status of tam
    */
    public function setTamStatus($tam, $mode)
    {
        $ret=-1;
        if ($mode == 0 || $mode == 1)
        {
            // Check if corresponding tam is existing and if status has to be changed
            $tamlist=$this->getTamStatus();
            if ($tamlist)
            {
                if($tamlist[$tam]!=$mode){
                    $formfields = array(
                        'getpage' => '/fon_devices/tam_list.lua',
                        'useajax' => '1',
                        'TamNr' => $tam,
                        'switch' => 'toggle'
                    );
                    $output = $this->doGetRequest($formfields);
                    $output_o = json_decode($output);
                    if ( isset($output_o->switch_on))
                    {
                        $ret=$output_o->switch_on;
                    }
                }
            }
        }
        return $ret;
    }

    /**
     * delete port sharing
     *
     * @param  int  $device          device id for setting the port sharing
     * @param  int  $portsharing_no  port sharing to delete
     * @return false, if erroroccurs, otherwise the devicelist with all remaining port sharings
     */

    public function deletePortsharing($devicename, $portsharing)
    {
        $deviceNo = -1;
        $portRule = -1;
        $curr= $this->getPortOverview();
        foreach($curr->data->devices as $key=>$device)
        {
            if (strtolower($devicename) == strtolower($device->devicename)) {
                $deviceNo = $key;
                break;
            }
        }
        if ($deviceNo != -1)
        {
            foreach($curr->data->devices[$deviceNo]->rules as $key=>$rule)
            {
                if ($portsharing == intval($rule->port)) {
                    $portRule = $key;
                    break;
                }
            }
            if ($portRule != -1)
            {
                // apply rule
                $rule = "UID=".$curr->data->devices[$deviceNo]->rules[$portRule]->UID.";";
                $rule .="accesstype=ipv4;";
                $rule .="app=".$curr->data->devices[$deviceNo]->rules[$portRule]->app.";";
                $rule .="description=".$curr->data->devices[$deviceNo]->rules[$portRule]->app.";";
                $rule .="directory=;";
                $rule .="activated=true;";
                $rule .="fwport=".$curr->data->devices[$deviceNo]->rules[$portRule]->fwport.";";
                $rule .="fwendport=".$curr->data->devices[$deviceNo]->rules[$portRule]->fwendport.";";
                $rule .="port=".$curr->data->devices[$deviceNo]->rules[$portRule]->endport.";";
                $rule .="myfritz_adr=;";
                $rule .="scheme=;";
                $rule .="protocol=TCP;";
                $rule .="rulestate=delete;";
                $rule .="type=port;";
                $rule .="myfritzdevice_uid=;";
                $rule .="myfritzservice_uid=;";

                $formfields = array(
                    'getpage' => '/data.lua',
                    'xhr'=> 1,
                    'allow_pcp_and_upnp'=> 0,
                    'exposed_ipv4'=>'off',
                    'rulecount'=>$portRule,
                    'rule1'=> $rule,
                    'ipv4expostedhost_count'=>0,
                    'exposed_ipv4_node'=>"",
                    'device'=>$curr->data->devices[$deviceNo]->UID,
                    'local_ipv4'=>$curr->data->devices[$deviceNo]->local_ipv4,
                    'landevice'=>$curr->data->devices[$deviceNo]->UID,
                    'ipv6_rulenode'=>"",
                    'isIpv6Activ'=>"",
                    'ifaceid'=>':::::',
                    'edify'=>"",
                    'lang' => 'de',
                    'page'=>'portoverview',
                );
                $this->doPostForm($formfields);
                return True;
            }
        }
        return false;
    }

    /**
     * set port sharing
     *
     * @param  int  $device          device id for setting the port sharing
     * @param  int  $externalport    external port for port sharing
     * @param  int  $startPort       interal start port of port sharing range
     * @param  int  $endPort         interal end port of port sharing range
     * @return false, if erroroccurs, otherwise the devicelist with all port sharings
     */
    public function setPortsharing($devicename, $externalPort, $startPort, $endPort)
    {
        $curr = $this->getPortOverview();
        foreach ($curr->data->devices as $key => $device) {
            if (strtolower($devicename) == strtolower($device->devicename)) {
                $deviceNo = $key;
                break;
            }
        }
        if ($deviceNo != -1) {
            $rulecount = sizeof($curr->data->devices[$deviceNo]->rules);
            $UID = $curr->data->devices[$deviceNo]->UID;
            $local_ip4 = $curr->data->devices[$deviceNo]->local_ipv4;
        } else {
            return false;
        }

        // generate rule
        $formfields = array(
            'getpage' => '/data.lua',
            'xhr' => 1,
            'sharingtype' => 'port',
            'description_portsharing' => 'HTTP-Server',
            'start_portsharing' => $startPort,
            'end_portsharing' => $endPort,
            'port_portsharing' => $externalPort,
            'portsharing_activ' => 1,
            'portsharingtype' => 'ipv4',
            'allow_pcp_and_upnp' => 0,
            'exposed_ipv4' => 'off',
            'apply_rule' => "",
            'page' => 'portoverview',
            'lang' => 'de'
        );
        $output = $this->doPostForm($formfields);
        $output = json_decode($output);
        if ($output->data->apply_rule != "ok") {
            return false;
        }

        // apply rule
        $rule = "UID=newRule1;";
        $rule .= "accesstype=ipv4;";
        $rule .= "app=HTTP-Server;";
        $rule .= "description=HTTP-Server;";
        $rule .= "directory=;";
        $rule .= "activated=1;";
        $rule .= "fwport=" . $startPort . ";";
        $rule .= "fwendport=" . $endPort . ";";
        $rule .= "port=" . $externalPort . ";";
        $rule .= "myfritz_adr=;";
        $rule .= "scheme=undefined;";
        $rule .= "protocol=TCP;";
        $rule .= "rulestate=new;";
        $rule .= "type=port;";
        $rule .= "myfritzdevice_uid=;";
        $rule .= "myfritzservice_uid=undefined;";

        $formfields = array(
            'getpage' => '/data.lua',
            'xhr' => 1,
            'allow_pcp_and_upnp' => 0,
            'exposed_ipv4' => 'off',
            "rulecount" => $rulecount,
            "rule1" => $rule,
            "ipv4exposedhost_count" => 0,
            "exposed_ipv4_node" => "",
            "device" => $UID,
            "local_ipv4" => $local_ip4,
            "landevice" => $UID,
            "ipv6_rulenode" => "",
            "isIpv6Active" => "false",
            "ifaceid" => ":::::",
            "edify" => "",
            'page' => 'portoverview',
            'lang' => 'de'
        );
        $output2 = $this->doPostForm($formfields);
        $decoded_Output = json_decode($output2);
        if (sizeof($decoded_Output->data->devices[$deviceNo]->rules) == ($rulecount + 1)) {
            return true;
        }
        return false;
    }

    /**
    * get tam status
    * 
    * @return NULL if no tams are present, otherwise array with 1 for activated tam, 0 for deactivated tam
    */
    
    public function getTamStatus()
    {
        $formfields = array(
            'getpage' => '/data.lua',
            'page' => 'tam',
            'lang' => 'de',
            'xhr' =>'1'
        );
        $ret=NULL;
        $output = $this->doPostForm($formfields);
        preg_match_all('/id="uiSwitch([0-9])" class="switch_(off|on) /', $output, $status);
        foreach($status[2] as $value )
        {
            $ret[]= ($value=='on'? 1:0);
        }
        return $ret;
    }

    /**
     * get DSL Spectrum
     *
     * @return array    Array of Spectrum values
     */

    public function getDSLSpectrum()
    {
        $formfields = array(
            'getpage' => '/internet/dsl_spectrum.lua',
            'useajax' => '1'
        );
        $output = $this->doGetRequest($formfields);
        return json_decode($output);
    }

    /**
     * get DSL Statistics (CRC errors, resync?, and signal to noise ratio (snr)
     *
     * @return array    Array of statistic values
     */

    public function getDSLStats()
    {
        $formfields = array(
            'getpage' => '/internet/dsl_stats_graph.lua',
            'useajax' => '1'
        );
        $output = $this->doGetRequest($formfields);
        return json_decode($output);
    }
    
    /**
    * get Online Counter statistic 
    * 
    * @return array                array of statistics 
    * today             time    totalData   UploadData DownloadData Connections
    * yesterday         time    totalData   UploadData DownloadData Connections
    * current week      time    totalData   UploadData DownloadData Connections
    * current month     time    totalData   UploadData DownloadData Connections
    * last month        time    totalData   UploadData DownloadData Connections
    */
    
    public function getOnlineCounterStatistic()
    {
        $formfields = array(
            'getpage' => '/data.lua',
            'page' => 'netCnt',
            'lang' => 'de',
            'xhr' =>'1'
        );

        $output = $this->doPostForm($formfields);
        preg_match_all('/class="time">([0-9]+:[0-9]+)<.*gesamt\(MB\)".class="vol">([0-9]+)<.*gesendet\(MB\)" class="vol">([0-9]+)<.*empfangen\(MB\)" class="vol">([0-9]+)<.*class="conn">([0-9]+)</', $output, $online);
        
        return $online;
    }

    /**
     * get Logbook from Fritz!Box
     *
     * @return array    with max 400 entries, every entry has 6 lines with date, time, logtext, log code,
     *                  priority and link to helppage
     */

    public function getLogbook()
    {
        $formfields = array(
            'getpage' => '/data.lua',
            'page' => 'log',
            'lang' => 'de',
            'xhrid' =>'all',
            'xhr' => '1'
        );

        $output = $this->doPostForm($formfields);
        $out=json_decode($output);
        return $out->data->log;
    }


    /**
    * getOverview 
    * 
    * @return array     array of all data from startpage
    */
    
    public function getOverview()
    {
        $formfields = array(
            'getpage' => '/data.lua',
            'page' => 'overview',
            'lang' => 'de',
            'xhr' =>'1',
            'xhrID' =>'all'
        );

        $output = $this->doPostForm($formfields);
        return json_decode($output);
    }


    /**
     * getOverview
     *
     * @return array     array of all data from startpage
     */

    public function getPortOverview()
    {
        $formfields = array(
            'getpage' => '/data.lua',
            'page' => 'portoverview',
            'lang' => 'de',
            'xhr' =>'1',
            'xhrID' =>'all'
        );

        $output = $this->doPostForm($formfields);
        return json_decode($output);
    }

    /**
     * DownloadPhonecall list
     *
     * @return bool     success or fail
     */

    public function downloadPhonecalllist()
    {
        // get the phone calls list
        $params = array(
            'getpage'         => '/fon_num/foncalls_list.lua',
            'csv'             => '',
        );
        $output = $this->doGetRequest($params);

        if ($output) {
            $ret=true;
            // write out the call list to the desired path
            file_put_contents($this->config->getItem('foncallslist_path'), $output);
        }else{
            $ret=false;
        }
        return $ret;
    }


    /**
     * deletePhonecall list
     *
     * @return array    Statusresponse of fritz Box
     */

    public function deleteCalllist()
    {
        $formfields = array(
            'getpage'	=> '/fon_num/foncalls_list.lua',
            'usejournal' 	=> '1',
            'callstab' 	=> 'all',
            'submit'	=> 'clear',
            'clear'	=> '1',
        );
        $output=$this->doPostForm($formfields);
        return json_decode($output);
    }
    

}

class fritzbox_api_config {
  protected $config = array();

  public function __construct()
  {
    # use the new .lua login method in current (end 2012) labor and newer firmwares (Fritz!OS 5.50 and up)
    $this->setItem('use_lua_login_method', true);
    
    # set to your Fritz!Box IP address or DNS name (defaults to fritz.box), for remote config mode, use the dyndns-name like example.dyndns.org
    $this->setItem('fritzbox_ip', 'fritz.box');

    # if needed, enable remote config here
    #$this->setItem('enable_remote_config', true);
    #$this->setItem('remote_config_user', 'test');
    #$this->setItem('remote_config_password', 'test123');

    # set to your Fritz!Box username, if login with username is enabled (will be ignored, when remote config is enabled)
    $this->setItem('username', false);
    
    # set to your Fritz!Box password (defaults to no password)
    $this->setItem('password', false);

    # set the logging mechanism (defaults to console logging)
    $this->setItem('logging', 'console'); // output to the console
    #$this->setItem('logging', 'silent');  // do not output anything, be careful with this logging mode
    #$this->setItem('logging', 'tam.log'); // the path to a writeable logfile

    # the newline character for the logfile (does not need to be changed in most cases)
    $this->setItem('newline', (PHP_OS == 'WINNT') ? "\r\n" : "\n");
  }
  
  /* gets an item from the config
   *
   * @param  $item   string  the item to get
   * @return         mixed   the value of the item
   */
  public function getItem($item = 'all')
  {
    if ( $item == 'all' )
    {
      return $this->config;
    }
    elseif ( isset($this->config[$item]) )
    {
      return $this->config[$item];
    }
    return false;
  }
  
  /* sets an item into the config
   *
   * @param  $item   string  the item to set
   * @param  $value  mixed   the value to store into the item
   */
  public function setItem($item, $value)
  {
    $this->config[$item] = $value;
  }
}
