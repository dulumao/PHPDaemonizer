<?php

/*
    Class for daemonization php command line scripts.
*/

namespace PHPDaemonizer;

class Daemon {

    /* variables */

    private static $isRunning = true;
    private static $iSecondsToSleep = 0;
    private static $oErrorHandler;
    private static $oDaemonInstance = null;
    private static $fLogCallback = null;

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
    public static function getArgumentByKey($sGivenKey, $sDefault = "") {
        global $argv;

        if ($argv) {
            foreach ($argv as $sKey => $sArg) {
                if ($sGivenKey == $sArg) {
                    return isset($argv[$sKey + 1]) ? $argv[$sKey + 1] : $sDefault;
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

    /* */

    public static function isMultiprocessing() {
        return self::doesKeyExist('--process-number');
    }

    /* */

    public static function getProcessNumber() {
        if (! self::isMultiprocessing()) {
            return '';
        }
        return (int)self::getArgumentByKey('--process-number', '');
    }

    /* is process with given --process-number running (check it via PID file) */

    public static function isProcessRunning() {
        return file_exists(self::getPIDFileName());
    }

    /*  descructor to catch moment the daemon is shutting down
        see savePID: self::$oDaemonInstance = new self */

    public function __destruct() {
        self::removePIDFile();
    }

    /* removing PID file from file system, see also ErrorHandler::__errorHandler */

    public static function removePIDFile() {
        $sPIDFileName = self::getPIDFileName();
        if (file_exists($sPIDFileName) and is_writable($sPIDFileName)) {
            unlink($sPIDFileName);
        }
    }

    /* log callback, to write important messages in database, etc. */

    public static function setLogCallback($fLogCallback) {
        if (is_callable($fLogCallback)) {
            self::$fLogCallback = $fLogCallback;
        }
    }

    /* logging */

    public static function log($sMessage, $iTypeId = self::MESSAGE) {
        echo date("d-m-Y H:i:s ") . $sMessage . "\n";

        //
        if (self::$fLogCallback) {
            call_user_func(self::$fLogCallback, $sMessage, $iTypeId);
        }
    }

    /* daemonization */

    private static function _daemonize($iSecondsToSleep = 10, $fHandler = null) {
        set_time_limit(0);
        declare(ticks = 1);

        if (self::doesKeyExist("--do-not-daemonize") or
            self::doesKeyExist("--dnd")) {
            self::log("Daemonization is turned off. We will perform only one iteration of the main loop.");
            return;
        }

        $sPIDFile = self::getPIDFileName();

        if (self::doesKeyExist("--kill")) {
            if (file_exists($sPIDFile) and is_readable($sPIDFile)) {
                $iPid = file_get_contents($sPIDFile);
                if (posix_kill($iPid, SIGTERM)) {
                    self::log("SIGTERM was sent.");
                } else {
                    self::log("Unable to send SIGTERM.", Daemon::PHP_MESSAGE);
                }
                if (is_writable($sPIDFile)) {
                    unlink($sPIDFile);
                } else {
                    self::log("Unable to unlink PID ($sPIDFile).", Daemon::PHP_MESSAGE);
                }
            } else {
                self::log("There is no PID ($sPIDFile).", Daemon::PHP_MESSAGE);
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

        sleep(1);

        /* signal handler */

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

    /* cron daemonization */

    private static function _cronDaemonize() {
        set_time_limit(0);
        declare(ticks = 1);

        /* signal handler */

        $fHandler = function($iSignal) {
            switch($iSignal) {
                case SIGTERM: case SIGINT: case SIGHUP: {
                    self::log("Daemon " . self::getCurrentScriptBasename(). "  has received signal.");
                    self::removePIDFile();
                    break;
                }
            }

        };

        if (! pcntl_signal(SIGTERM, $fHandler) or
            ! pcntl_signal(SIGINT, $fHandler) or
            ! pcntl_signal(SIGHUP, $fHandler)) {
            self::log("Unable to set signal handler.");
            die();
        }
    }

    /* main daemonization method */

    public static function daemonize($fHandler = null) {
        self::$oErrorHandler = ErrorHandler::getInstance();
        self::preventMultipleInstances();
        self::_daemonize($fHandler);
        self::savePID();
    }

    /* main cron daemonization method */

    public static function cronDaemonize() {
        self::$oErrorHandler = ErrorHandler::getInstance();
        self::preventMultipleInstances();
        self::_cronDaemonize();
        self::savePID();
    }

    /* prevent daemon to be executed in more than one copy (instance) */

    public static function preventMultipleInstances() {
        if (self::doesKeyExist("--kill")) {
            return;
        }

        if(self::isProcessRunning()) {
            self::log("Daemon " . self::getCurrentScriptSymbolicName() . " is already running.");
            die();
        }
    }

    /* */

    private static function getPIDFileName() {
        $sSymbolicName = self::getCurrentScriptSymbolicName();
        $sPIDFile = "/tmp/$sSymbolicName.pid";
        return $sPIDFile;
    }

    public static function getCurrentScriptSymbolicName() {
        $sSymbolicName = self::getCurrentScriptBasename();
        $iProcessNumber = self::getProcessNumber();
        if ($iProcessNumber) {
            $iProcessNumber = '-' . $iProcessNumber;
        }
        return $sSymbolicName . $iProcessNumber;
    }

    private static function savePID() {
        $sPIDFile = self::getPIDFileName();

        if (is_writable(dirname($sPIDFile))) {
            file_put_contents($sPIDFile, posix_getpid());
        } else {
            self::log("Unable to save PID '$sPIDFile'.");
        }

        self::$oDaemonInstance = new self(0);
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
        set_error_handler(array($this, '__errorHandler'), E_ALL);
        register_shutdown_function(array($this, '__fatalErrorShutdownHandler'));

        error_reporting(0);
        ini_set('display_errors', 0);
    }

    public function __destruct() { }

    public function __fatalErrorShutdownHandler() {
        $aLastError = error_get_last();

        if ($aLastError['type'] === E_ERROR) {
            $this->__errorHandler(E_ERROR, $aLastError['message'], $aLastError['file'], $aLastError['line']);
        }
    }

    public function __errorHandler($iErrorNumber, $sErrorMessage, $sErrorFile, $iErrorLine) {
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

        if ($bDie) {
            Daemon::removePIDFile();    // see Daemon::destructor
            die;
        }

        return true;
    }
}
