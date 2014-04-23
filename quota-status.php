<?php
/**
 * Simple maildir quota policy server for postfix
 *
 * configure a policy-socket in file /etc/postfix/master.cf
 *   127.0.0.1:12340  inet  n       n       n       -       0       spawn
 *       user=mailuser argv=/usr/bin/php -f /etc/postfix/quota-status.php
 *
 * enable the check_policy_service in file /etc/postfix/main.cf
 *   smtpd_recipient_restrictions =
 *       check_policy_service inet:127.0.0.1:12340
 *   ...
 */

/**
 * Debugger - please set to FALSE after testing!
 */
define('DEBUGGER',true);
define('DEBUGGER_LOGFILE','/tmp/quota-status.log');

/**
 * Username-Key: recipient|sasl_username
 */
define('USERNAME_KEY','recipient');
/**
 * comma separated homedirectory lookup-functions
 */
define('HOME_LOOKUP_FUNCTIONS','home_static_lookup');
/**
 * Only for home_static_lookup
 */
define('MAILBOX_BASE_PATH','/var/spool/Mailbox/');
define('QUOTA_GRACE', 0);
define('ACTION_SUCCESS',"dunno");
define('ACTION_REJECT',"defer_if_permit 552 5.2.2 Mailbox is full");

$request = read_smptd_policy_request();
echo get_response($request);

/**
 * Reads postfix SMTPD_POLICY-request
 * End of request is a empty line
 *
 * More info: http://www.postfix.org/SMTPD_POLICY_README.html
 *
 * @return array get an name/value-array with all request attributes
 */
function read_smptd_policy_request() {
    $request_data = array();

    if ($fp = fopen("php://stdin", "r")) {
        while ($line = trim(fgets($fp, 512))) {
            $key_value = explode("=", $line);
            $request_data[$key_value[0]] = $key_value[1];
        }
        fclose($fp);
    }
    logger('REQUEST',var_export($request_data, true));
    return $request_data;
}

/**
 * Checks the mailbox quota and gets the response-action for postfix
 *
 * @param $request
 *
 * @return string Response action (dunno for pass to other checks or defer_if_permit to reject)
 */
function get_response($request) {
    $home = get_home($request);
    $maildirsize_file = $home."/Maildir/maildirsize";
    $action = ( is_overquota($maildirsize_file, $request['size']) === true ) ? ACTION_REJECT."\n\n" : ACTION_SUCCESS."\n\n";
    $action = 'action='.$action;
    logger('RESPONSE', $action);
    return $action;
}

/**
 * Checks overquota-status for current mail
 *
 * @param string $maildirsize_file quota-file for current user
 * @param int $mailsize size of the current mail
 *
 * @return bool true=>email can not be delivered (overquota), false=>email can be delivered
 */
function is_overquota($maildirsize_file, $mailsize) {
    logger('mailsize: ', $mailsize);
    logger('read file: ', $maildirsize_file);
    // quota-file does not exist or is not readable
    if(!is_readable($maildirsize_file)) return false;
    $quota_data = file($maildirsize_file);

    // quota-file is empty
    if(count($quota_data) < 1) return false;

    $quota_definitions = explode(',', $quota_data[0]);
    foreach($quota_definitions as $definition) {
        if(stristr($definition, 's')) {
            $max_size = substr($definition,0, -1);
            $max_size += QUOTA_GRACE;
            logger('quota max size + (QUOTA_GRACE = '.QUOTA_GRACE.')', $max_size);
        }

        if(stristr($definition, 'c')) {
            $max_count = substr($definition[0],0, -1);
            logger('quota max count', $max_count);
        }
    }
    unset($quota_data[0]);

    // calculate current size and count
    $current_size = 0;
    $current_count = 0;

    foreach($quota_data as $quota_line) {
        $size_count = explode(" ", $quota_line);
        $current_size += $size_count[0];
        $current_count += $size_count[1];
    }

    logger('current mailbox-size', $current_size);
    logger('current mailbox-count', $current_count);

    if(isset($max_size) && $current_size+$mailsize > $max_size) return true;
    if(isset($max_count) && $current_count+1 > $max_count) return true;
    return false;
}

function get_home($request) {
    $home = false;
    $lookup_functions = explode(',', HOME_LOOKUP_FUNCTIONS);
    foreach ($lookup_functions as $lookup_function) {
        if(function_exists($lookup_function)) {
            $home = $lookup_function($request);
            if($home !== false) break;
        }
    }
    return $home;
}

/**
 * Gets the user homedirectory-path or false
 *
 * @param $request
 *
 * @return false|string gets the user-home directory or false
 */
function home_static_lookup($request) {
    $user = trim(strtolower($request[USERNAME_KEY]));
    logger('user',$user);
    if(!$user) return false;

    $home = MAILBOX_BASE_PATH.$user;
    logger('user home directory', MAILBOX_BASE_PATH.$user);
    return $home;
}


function logger($description, $message) {
    if(DEBUGGER !== true) return;
    $now = date("M d H:i:s");
    file_put_contents(DEBUGGER_LOGFILE, $now." ".$description.": ".$message."\n", FILE_APPEND);
}

