<?php

// the example of realtime daemon
// usage:
//      php realtime-daemon.php
//                                  to start it
//      php realtime-daemon.php -- --dnd
//                                  to execute only one iteration
//      php realtime-daemon.php -- --kill
//                                  to send termination signal

// some magic to handle signals
// this code has to present in base daemon script, not in daemon.php
declare(ticks=1);

require_once("../daemon.php"); // daemonization class and error handler

// some daemon settings
mb_internal_encoding('utf-8');
date_default_timezone_set('Europe/Moscow');

// do daemonization:
Daemon::daemonize(20); // 20 seconds to sleep between iterations

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
    }

    // sleeping
    Daemon::sleep();
}




