<?
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_options.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/fan_helpers.php';

$action = array_key_exists('action', $_GET) ? $_GET['action'] : '';
$hdd_temp = get_highest_temp();

if (!empty($action)) {
    $state = ['Critical' => 'red', 'Warning' => 'yellow', 'Nominal' => 'green', 'N/A' => 'blue'];
    if ($action == 'ipmisensors'){
        $return  = ['Sensors' => ipmi_sensors($ignore),'Network' => ($netsvc == 'enable'),'State' => $state];
        echo json_encode($return);
    }
    elseif($action == 'ipmievents'){
        $return  = ['Events' => ipmi_events(),'Network' => ($netsvc == 'enable'),'State' => $state];
        echo json_encode($return);
    }
    elseif($action == 'ipmiarch'){
        $return  = ['Archives' => ipmi_events(true), 'Network' => ($netsvc == 'enable'), 'State' => $state];
        echo json_encode($return);
    }
    elseif($action == 'ipmidash') {
        $return  = ['Sensors' => ipmi_sensors($dignore), 'Network' => ($netsvc == 'enable'),'State' => $state];
        echo json_encode($return);
    }
}

/* get highest temp of hard drives */
function get_highest_temp(){
    $hdds = parse_ini_file('/var/local/emhttp/disks.ini',true);
    $highest_temp = '0';
    foreach ($hdds as $hdd) {
        $temp = $hdd['temp'];
        if(is_numeric($temp))
            $highest_temp = ($temp > $highest_temp) ? $temp : $highest_temp;
    }
    return $highest_temp;
}

/* get options for high or low temp thresholds */
function get_temp_range($range, $selected=null){
    $temps = [20,80];
    if ($range == 'HI')
      rsort($temps);
    $options = "";
    foreach(range($temps[0], $temps[1], 5) as $temp){
        $options .= "<option value='$temp'";

        // set saved option as selected
        if ($selected == $temp)
            $options .= " selected";

        $options .= ">$temp</option>";
    }
    return $options;
}

/* get an array of all sensors and their values */
function ipmi_sensors($ignore=null) {
    global $ipmi, $netopts, $hdd_temp;

    // return empty array if no ipmi detected and no network options
    if(!($ipmi || !empty($netopts)))
        return [];

    $ignored = (empty($ignore)) ? '' : "-R $ignore";
    $cmd = '/usr/sbin/ipmi-sensors --output-sensor-thresholds --comma-separated-output '.
        "--output-sensor-state --no-header-output --interpret-oem-data $netopts $ignored 2>/dev/null";
    exec($cmd, $output, $return_var=null);

    if ($return_var)
        return []; // return empty array if error

    // add highest hard drive temp sensor and check if hdd is ignored
    $hdd = ((preg_match('/99/', $ignore)) && !$all) ? '' :
        "99,HDD Temperature,Temperature,Nominal,$hdd_temp,C,N/A,N/A,N/A,45.00,50.00,N/A,Ok";
    if(!empty($hdd)){
        if(!empty($netopts))
            $hdd = '127.0.0.1:'.$hdd;
        $output[] = $hdd;
    }
    // key names for ipmi sensors output
    $keys = ['ID','Name','Type','State','Reading','Units','LowerNR','LowerC','LowerNC','UpperNC','UpperC','UpperNR','Event'];
    $sensors = [];

    foreach($output as $line){

        $sensor_raw = explode(",", $line);
        $size_raw = sizeof($sensor_raw);

        // add sensor keys as keys to ipmi sensor output
        $sensor = ($size_raw < 13) ? []: array_combine($keys, array_slice($sensor_raw,0,13,true));

        if(empty($netopts))
            $sensors[$sensor['ID']] = $sensor;
        else{

            //split id into host and id
            $id = explode(':',$sensor['ID']);
            $sensor['IP'] = trim($id[0]);
            $sensor['ID'] = trim($id[1]);
            if ($sensor['IP'] == 'localhost')
                $sensor['IP'] = '127.0.0.1';

            // add sensor to array of sensors
            $sensors[ip2long($sensor['IP']).'_'.$sensor['ID']] = $sensor;
        }
    }
    return $sensors;
}

/* get array of events and their values */
function ipmi_events($archive=null){
    global $ipmi, $netopts;

    // return empty array if no ipmi detected or network options
    if(!($ipmi || !empty($netopts)))
        return [];

    if($archive) {
        $filename = "/boot/config/plugins/ipmi/archived_events.log";
        $output = is_file($filename) ? file($filename, FILE_IGNORE_NEW_LINES) : [] ;
    } else {
        $cmd = '/usr/sbin/ipmi-sel --comma-separated-output --output-event-state --no-header-output '.
            "--interpret-oem-data $netopts 2>/dev/null";
        exec($cmd, $output, $return_var=null);
    }

    // return empty array if error
    if ($return_var)
        return [];

    // key names for ipmi event output
    $keys = ['ID','Date','Time','Name','Type','State','Event'];
    $events = [];

    foreach($output as $line){

        $event_raw = explode(",", $line);
        $size_raw = sizeof($event_raw);

        // add event keys as keys to ipmi event output
        $event = ($size_raw < 7) ? []: array_combine($keys, array_slice($event_raw,0,7,true));

        // put time in sortable format and add unix timestamp
        $timestamp = $event['Date']." ".$event['Time'];
        if(strtotime($timestamp)) {
            if($date = Datetime::createFromFormat('M-d-Y H:i:s', $timestamp)) {
                $event['Date'] = $date->format('Y-m-d H:i:s');
                $event['Time'] = $date->format('U');
            }
        }

        if (empty($netopts)){

            if($archive)
                $events[$event['Time']."-".$event['ID']] = $event;
            else
                $events[$event['ID']] = $event;

        }else{

            //split id into host and id
            $id = explode(':',$event['ID']);
            $event['IP'] = trim($id[0]);
            if($archive)
                $event['ID'] = $event['Time'];
            else
                $event['ID'] = trim($id[1]);
            if ($event['IP'] == 'localhost')
                $event['IP'] = '127.0.0.1';

            // add event to array of events
            $events[ip2long($event['IP']).'_'.$event['ID']] = $event;
        }
    }
    return $events;
}

/* get select options for a fan and temp sensors */
function ipmi_get_options($selected=null){
    global $sensors;
    $options = "";
    foreach($sensors as $id => $sensor){
        if (($sensor['Type'] == 'Temperature') || ($sensor['Type'] == 'Fan')){
            $name = $sensor['Name'];
            $reading  = $sensor['Reading'];
            $ip = (empty($sensor['IP'])) ? '' : " (${sensor['IP']})";
            $units    = ($reading == 'N/A')    ? '' : $sensor['Units'];
            $options .= "<option value='$id'";

            // set saved option as selected
            if ($selected == $id)
                $options .= " selected";

        $options .= ">$name$ip - $reading $units</option>";
        }
    }
    return $options;
}

/* get select options for enabled sensors */
function ipmi_get_enabled($ignore){
    global $ipmi, $netopts, $allsensors;

    // return empty array if no ipmi detected or network options
    if(!($ipmi || !empty($netopts)))
        return [];

    $ignored = array_flip(explode(',', $ignore)); // create array of keyed ignored sensors
    foreach($allsensors as $sensor){
        $id       = $sensor['ID'];
        $reading  = $sensor['Reading'];
        $units    = ($reading == 'N/A') ? '' : " ${sensor['Units']}";
        $ip       = (empty($netopts))   ? '' : " ${sensor['IP']}";
        $options .= "<option value='$id'";

        // search for id in array to not select ignored sensors
        $options .= array_key_exists($id, $ignored) ?  '' : " selected";

        $options .= ">${sensor['Name']}$ip - $reading$units</option>";

    }
    return $options;
}

// get a json array of the contents of gihub repo
function get_content_from_github($repo, $file) {
    $ch = curl_init();
    $ch_vers = curl_version();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERAGENT, 'curl/'.$ch_vers['version']);
    curl_setopt($ch, CURLOPT_URL, $repo);
    $content = curl_exec($ch);
    curl_close($ch);
    if (!empty($content) && (!is_file($file) || $content != file_get_contents($file)))
        file_put_contents($file, $content);
}
?>