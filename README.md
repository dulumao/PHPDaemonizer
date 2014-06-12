PHPDaemonizer
=============

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
