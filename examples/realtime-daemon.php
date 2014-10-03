<?php

// the example of realtime daemon
// usage:
//      php realtime-daemon.php
//                                  to start it
//      php realtime-daemon.php -- --dnd
//                                  to execute only one iteration
//      php realtime-daemon.php -- --kill
//                                  to send termination signal
//      php realtime-daemon.php -- --process-number 1
//                                  to to start it the first process
//      php realtime-daemon.php -- --process-number 2
//                                  to to start it the second process
//      php realtime-daemon.php --process-number 1 --kill
//                                  to kill the first process

require_once("../src/PHPDaemonizer/Daemon.php"); // daemonization class and error handler

use PHPDaemonizer\Daemon;

// some daemon settings
mb_internal_encoding('utf-8');
date_default_timezone_set('Europe/Moscow');

// do daemonization:
Daemon::daemonize(5); // 5 seconds to sleep between iterations

// let's work!
Daemon::log("Hello, I am daemon.");

// main loop
while (Daemon::isRunning()) {
    Daemon::log("Now I am going to do some hard work...");

    // working
    $iIndex = 0;
    $iSum = 0;
    for ($iIndex = 0; $iIndex < 1000; $iIndex ++) {
        $iSum += $iIndex;
        usleep(1000);
    }

    // some notice to be caught by ErrorHandler...
    echo $b;

    // sleeping
    Daemon::sleep();
}




