<?php

/*
    Class for daemonization php command line scripts.
    The script to be daemonized have to be of structure shown below:

    <?php
        declare(ticks=1);

        Daemon::daemonize(N);

        while (Daemon::isRunning()) {
            // some work here

            Daemon::sleep();
        }
    ?>

    Daemonization is performed via pcntl_fork.

    To start script run:
        php realtime-daemon.php

    To execute only 1 iteration run:
        php realtime-daemon.php -- --dnd

    To send termination signal run:
        php realtime-daemon.php -- --kill
    In this case the last iteration will be completely performed so it is the "soft" termination.
    See http://en.wikipedia.org/wiki/SIGTERM#SIGTERM for more information.

    Only one process is allowed to be executed at the same time, see Daemon::preventMultipleInstances().
    To organize some kind of multiprocessing use the unix symbolic links:
        ln -s realtime-daemon-process1.php realtime-daemon.php
        ln -s realtime-daemon-process2.php realtime-daemon.php
        php realtime-daemon-process1.php
        php realtime-daemon-process2.php

    Use crontab to be sure that the daemonized script will be run again after hard crush that can happen.

    The object of ErrorHandler class is used for interpretator' notices and error catching and handling.
    These events are logged with PHP_MESSAGES type so you can provide your own logic in Daemon::log
    to process them.
*/

class Daemon {

    /* variables */

    private static $isRunning = true;
    private static $iSecondsToSleep = 0;
    private static $oErrorHandler;

    /* types of messages */

    const MESSAGE               = 1;    // plain message
    const IMPORTANT_MESSAGE     = 2;    // logically important message
    const PHP_MESSAGE           = 3;    // php notice or error

    /* some parameters functions */

    // php daemon.name -- --key1 --key2 --key3
    // doesKeyExist('key1') == true
    // doesKeyExist('key4') == false
    public static function doesKeyExist($sGivenKey) {
        global $argv;
        return in_array($sGivenKey, $argv);
    }

    // php daemon.name -- --key1 2 --key2 3 --key3 4
    // getArgumentByKey('key1') == 2
    // getArgumentByKey('key4', 100) == 100
    public function getArgumentByKey($sGivenKey, $sDefault = "") {
        global $argv;

        if ($argv) {
            foreach ($argv as $sKey => $sArg) {
                if ($sGivenKey == $sArg) {
                    return $argv[$sKey + 1];
                }
            }
        }

        return $sDefault;
    }

    /* script basename */

    public static function getCurrentScriptBasename() {
        global $argv;

        if (isset($argv[0])) {
            return basename($argv[0]);
        }

        return 'UNKNOWN_SCRIPT.php';
    }

    /* number of processes with given name */

    public static function findActiveProcesses($sProcessName = false) {
        if (! $sProcessName) {
            $sProcessName = self::getCurrentScriptBasename();
        }

        $sOutput = '';
        $iCount  = 0;

        ob_start();
        system(
            'ps xw | grep '. escapeshellarg($sProcessName) .
            ' | grep -v '. escapeshellarg('/bin/sh') .
            ' | grep -v '. escapeshellarg('grep') .
            ' | grep -v ' . escapeshellarg('new mail')
        );

        $sOutput = ob_get_clean();

        if (empty($sOutput)) {
            return 0;
        }

        str_replace("\n", '', $sOutput, $iCount);

        return $iCount;
    }

    /* logging */

    public static function log($sMessage, $iTypeId = self::MESSAGE) {
        echo date("d-m-Y H:i:s ") . $sMessage . "\n";
        // you can handle PHP_MESSAGE here
    }

    /* daemonization */

    private static function _daemonize($iSecondsToSleep = 10, $fHandler = null) {
        set_time_limit(0);

        if (self::doesKeyExist("--do-not-daemonize") or
            self::doesKeyExist("--dnd")) {
            self::log("Daemonization is turned off. We will perform only one iteration of the main loop.");
            return;
        }

        $sSymbolicName = self::getCurrentScriptBasename();
        $sPIDFile = "/tmp/$sSymbolicName.pid";

        if (self::doesKeyExist("--kill")) {
            if (file_exists($sPIDFile) and is_readable($sPIDFile)) {
                $iPid = file_get_contents($sPIDFile);
                if (posix_kill($iPid, SIGTERM)) {
                    self::log("SIGTERM was sent.");
                } else {
                    self::log("Unable to send SIGTERM.");
                }
                if (is_writable($sPIDFile)) {
                    unlink($sPIDFile);
                } else {
                    self::log("Unable to unlink PID ($sPIDFile).");
                }
            } else {
                self::log("There is no PID ($sPIDFile).");
            }
            die();
        }

        $iChildPid = pcntl_fork();

        if($iChildPid) {
            /* shut down the parent process */
            die();
        }

        /* the child process will be main */

        posix_setsid();

        /* sleep in order to parent process will be completely unloaded */

        sleep(3);

        /* singnal handler */

        if (! $fHandler) {
            $fHandler = function($iSignal) {
                switch($iSignal) {
                    case SIGTERM: {
                        self::log("Daemon " . self::getCurrentScriptBasename(). "  has received SIGTERM.");
                        self::halt('SIGTERM');
                        break;
                    }
                }

            };
        }

        if (! pcntl_signal(SIGTERM, $fHandler)) {
            self::log("Unable to set signal handler.");
            die();
        }

        self::$iSecondsToSleep = $iSecondsToSleep;
    }

    /* main daemonization method */

    public static function daemonize($fHandler = null) {
        self::$oErrorHandler = ErrorHandler::getInstance();
        self::preventMultipleInstances();
        self::_daemonize($fHandler);
        self::savePID();
    }

    /* prevent daemon to be executed in more than one copy (instance) */

    public static function preventMultipleInstances() {
        if (self::doesKeyExist("--kill")) {
            return;
        }

        if(self::findActiveProcesses() > 1) {
            self::log("Daemon is running.");
            die();
        }
    }

    /* */

    private static function savePID() {
        global $argv;

        $sSymbolicName = self::getCurrentScriptBasename($argv[0]);

        $sPIDFile = "/tmp/$sSymbolicName.pid";

        if (is_writable(dirname($sPIDFile))) {
            file_put_contents($sPIDFile, posix_getpid());
        } else {
            self::log("Unable to save PID '$sPIDFile'.");
        }
    }

    /* */

    public static function isRunning() {
        return self::$isRunning;
    }

    /*  */

    public static function halt($sMessage = '', $bAndDieImmediately = false) {
        self::$isRunning = false;
        self::log("Stopping the daemon.");
        if ($sMessage) {
            self::log("Reason: " . $sMessage,
                $sMessage != '--dnd' ? self::IMPORTANT_MESSAGE : self::MESSAGE);
        }

        if ($bAndDieImmediately) {
            die();
        }
    }

    /*  */

    public static function sleep($iSeconds = -1) {
        if (self::doesKeyExist("--do-not-daemonize") or
            self::doesKeyExist("--dnd")) {
            self::log("The end of main loop.");
            self::halt('--dnd');
            return;
        }

        sleep($iSeconds > 0 ? $iSeconds : self::$iSecondsToSleep);
    }

}

class ErrorHandler {
    public static $oInstance = null;

    public static function getInstance() {
        return  ErrorHandler::$oInstance
                ? ErrorHandler::$oInstance
                : ErrorHandler::$oInstance = new ErrorHandler();
    }

    public function __construct() {
        set_error_handler('__errorHandler', E_ALL);
        register_shutdown_function('__fatalErrorShutdownHandler');
    }

    public function __destruct() { }
}

function __fatalErrorShutdownHandler() {
    $aLastError = error_get_last();

    if ($aLastError['type'] === E_ERROR) {
        __errorHandler(E_ERROR, $aLastError['message'], $aLastError['file'], $aLastError['line']);
    }
}

function __errorHandler($iErrorNumber, $sErrorMessage, $sErrorFile, $iErrorLine) {
    $oHandler = ErrorHandler::getInstance();
    $bDie     = false;

    $sText = "";

    switch ($iErrorNumber) {
        case E_ERROR:             $sText .= 'Error';                 $bDie = true; break;
        case E_WARNING:           $sText .= 'Warning';                             break;
        case E_PARSE:             $sText .= 'Parsing Error';                       break;
        case E_NOTICE:            $sText .= 'Notice';                              break;
        case E_CORE_ERROR:        $sText .= 'Core Error';            $bDie = true; break;
        case E_CORE_WARNING:      $sText .= 'Core Warning';                        break;
        case E_COMPILE_ERROR:     $sText .= 'Compile Error';         $bDie = true; break;
        case E_COMPILE_WARNING:   $sText .= 'Compile Warning';                     break;
        case E_USER_ERROR:        $sText .= 'User Error';            $bDie = true; break;
        case E_USER_WARNING:      $sText .= 'User Warning';                        break;
        case E_USER_NOTICE:       $sText .= 'User Notice';                         break;
        case E_STRICT:            $sText .= 'Strict Standards';                    break;
        case E_RECOVERABLE_ERROR: $sText .= 'Catchable Fatal Error'; $bDie = true; break;
        default:                  $sText .= 'Unkown Error';
    }

    $sText .= ': ' . $sErrorMessage . ' in file "' . $sErrorFile . '" on line ' . $iErrorLine . "\n";

    $aDebugInfo = debug_backtrace();

    for ($i = count($aDebugInfo) - 1; $i > 0; $i--) {
        $aDebugRecord = $aDebugInfo[$i];

        if (!empty($aDebugRecord['file'])) {
            $sText .= "\tCalled from ";

            $sText .= $aDebugRecord['file'] . ' [line '. $aDebugRecord['line'] .'] ';

            $sText .= '('. (! empty($aDebugRecord['class'])
                            ? $aDebugRecord['class'] . $aDebugRecord['type']
                            : '');

            $sText .= ( ! empty($aDebugRecord['function'])
                        ? $aDebugRecord['function']
                        : '');
            $sText .= ")\n";
        }
    }

    Daemon::log(trim($sText), Daemon::PHP_MESSAGE);

    return true;
}

