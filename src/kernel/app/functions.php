<?php
/**
 * Custom Global functions
 */
define('DS', DIRECTORY_SEPARATOR);


function __env() {
    $file = finder('.env',  dirname(dirname(dirname(__DIR__))) . '/');
    if (!file_exists($file)) error('env not loaded.');
    (new \Symfony\Component\Dotenv\Dotenv)->load($file);
}


function saveToFile($data, $file) {
    try {
        $handle = fopen($file, 'w') or error("Cant open/create the $file");
        fwrite($handle, $data);
        fclose($handle);
        return true;
    } catch (\Exception $e) {
        error($e->getMessage());
    }
}


/**
 * @return Array of parameters
 * @example key => value
 */
function params($limit):array {
    $params = $_SERVER['argv'];
    unset($params[0]); // Remove first param
    $param_list = [];
    $allowed_param = explode("|", getenv('ALLOWED_PARAM'));
    
    if (count($params) > $limit) error('Param exceeded to limit.');
    foreach ($params as $key => $param) {
        // str_contains global helper func of illuminate
        if (str_contains($param, '=') > 0) {
            $param = explode("=", $param);
            if (!in_array($param[0], $allowed_param) && $param != 'scrape') error("Param '$param[0]' not allowed.");
            if (!isset($param_list[$param[0]])) $param_list[$param[0]] = $param[1]; 
        } else {
            if (!in_array($param, $allowed_param) && $param != 'scrape') error("Param '$param' not allowed.");
            array_push($param_list, $param);
        }
    }
    
    return $param_list;
}


function checkOs():string {
    $current_os = PHP_OS;
    $oss = array(
        'Linux' => '',
        'WINNT' => '.exe',
        'FreeBSD' => ''
    );

    return $oss[$current_os];
}


function db_conf():array {
    return [
        'driver'    => getenv("DB_DRIVER"),
        'host'      => getenv("DB_HOST"),
        'database'  => getenv("DB_NAME"),
        'username'  => getenv("DB_USER"),
        'password'  => getenv("DB_PASS"),
        'charset'   => getenv("DB_CHARSET"),
        'collation' => getenv("DB_COLLATION"),
        'prefix'    => getenv("DB_PREFIX")
    ];
}

/**
 * @return Array of options
 */
function getOptions() {
    $array_opts = array();
    $opts = explode(",", getenv('OPTIONS'));
    foreach ($opts as $options) {
        $opt = explode("=", $options);

        if (ctype_digit($opt[1])) $opt[1] = (int) $opt[1]; 
        if (is_boolean($opt[1])) {
            if ($opt[1] == "false") $opt[1] = false;
            if ($opt[1] == "true") $opt[1] = true;
        }

        $array_opts[$opt[0]] = $opt[1];
    } 

    $custom_flags = explode("|", getenv('CUSTOM_FLAGS'));
    if (!empty($custom_flags)) {
        $array_opts['customFlags'] = $custom_flags;
    }
    
    return $array_opts;
}

/**
 * @link https://stackoverflow.com/questions/8272723/test-if-string-could-be-boolean-php
 */
function is_boolean($string) {
    $string = strtolower($string);
    return (in_array($string, array("true", "false", "1", "0", "yes", "no"), true));
}

function live_query():string {
    return "SELECT fs.`eventFK`, fs.`flashscore_link`, e.status_type
    FROM `event` e
    LEFT JOIN flashscore_source fs ON fs.`eventFK` = e.`id`
    WHERE e.`status_type` IN ('inprogress', 'delayed') AND fs.`flashscore_link` != ''
    UNION ALL
    SELECT fs.`eventFK`, fs.`flashscore_link`, e.status_type
    FROM `event` e
    LEFT JOIN flashscore_source fs ON fs.`eventFK` = e.`id`
    LEFT JOIN event_runtime er ON e.id = er.eventFK
    WHERE  fs.`flashscore_link` != ''
    AND e.status_type = 'finished'
    AND NOW() BETWEEN DATE_ADD(e.startdate, INTERVAL er.running_time MINUTE)
        AND DATE_ADD(e.startdate, INTERVAL (er.running_time+60) MINUTE)";
}


function _str_slug($title, $separator = '-', $language = 'en') {
            // $title = $language ? static::ascii($title, $language) : $title;

        // Convert all dashes/underscores into separator
        $flip = $separator === '-' ? '_' : '-';

        $title = preg_replace('!['.preg_quote($flip).']+!u', $separator, $title);

        // Replace @ with the word 'at'
        $title = str_replace('@', $separator.'at'.$separator, $title);

        // Remove all characters that are not the separator, letters, numbers, or whitespace.
        $title = preg_replace('![^'.preg_quote($separator).'\pL\pN\s]+!u', '', mb_strtolower($title, 'UTF-8'));

        // Replace all separator characters and whitespace by a single separator
        $title = preg_replace('!['.preg_quote($separator).'\s]+!u', $separator, $title);

        return trim($title, $separator);
}


/**
 * chrome_kill only support windows and linux
 */
function chrome_kill() {
    if (KILL_CHROME === 'false') exit;
    
    $os_type = windows_os();

    if ($os_type) {
        $exec = "taskkill /f /t /im chrome.exe";
        exec($exec);
        exit;
    }
    
    $exec = "pkill -f chrome";
    exec($exec);
    exit;
}


/**
* Function define
*/
function define_const(array $const) {
    foreach ($const as $const_name => $value) {
        define($const_name, $value);
    }

    is_const_defined(array_keys($const));
}

/**
* Function define checker
*/
function is_const_defined(array $const_names) {
    foreach ($const_names as $const_name) {
        defined($const_name) or error("Constant '$const_name' not defined.");
    }
}


/**
* File finder in php
* @return All occurrence of the filename, starting from the base dir.
*/
function find_file(string $file, string $path = __DIR__):array {
    $dir_exceptions = [".", "..", "vendor"];
    $dirs = $result = [];

    $dirs = (@scandir($path)) ? @scandir($path) : [];

    foreach ($dirs as $k => $dir) {
        if (in_array($dir, $dir_exceptions)) continue; 

        $file_path = realpath($path) . DS . $dir . DS . $file;

        if (file_exists($file_path)) {
            $result['files'][] = $file_path;
        } else if (file_exists(realpath($path) . DS . $file) && !is_dir(realpath($path) . DS . $file)) {
            if (!@in_array(realpath($path) . DS . $file, @$result['files'])) $result['files'][] = realpath($path) . DS . $file;
        } else {
            $return = find_file($file, $path . DS . $dir);
            if (@$return) $result['files'] = $return['files'];
        }
    }

    return $result;
}

function finder (string $file, string $path = __DIR__) {
    $result = find_file($file, $path);
    if (@$result['files']) {
        if (count($result['files']) == 1) {
            return $result['files'][0];
        } else {
            return false;
        }
    } else {
        return false;
    }
}

/**
 * @param $objClass Object Class obj
 * @param $function Method name
 */
function create_callable($objClass, $function) {
    return function () use ($objClass, $function) {
        $args = func_get_args();
        return call_user_func_array(array($objClass, $function), $args);
    };
}


/**
 * Custom error logging function w/ native php functions
 * @link https://www.php.net/manual/en/function.debug-backtrace.php
 * @param $message String
 * @param $level Int
 */
function error($message, $level = 1) {
    $db = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    $file = $db[0]['file'];
    $line = $db[0]['line'];

    try {
        throw new \Exception($message);
    } catch (\Exception $e) {
        echo NL;
        echo ERR_LEVELS[$level] . $e->getMessage() . NL;
        echo "Line: $line" . NL;
        echo "File: $file" . NL;
        echo NL;
        exit;
    }
}