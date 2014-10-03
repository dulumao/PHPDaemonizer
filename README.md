PHPDaemonizer
=============

Class for daemonization php command line scripts.

Installation:

    composer require denismilovanov/phpdaemonizer

The script to be daemonized have to be of structure shown below:

    <?php
        require_once("autoload.php");

        use PHPDaemonizer\Daemon;

        Daemon::daemonize(N);

        while (Daemon::isRunning()) {
            // some work here

            Daemon::sleep();
        }
    ?>

Daemonization is performed via `pcntl_fork`.

To start script run:
    `php realtime-daemon.php`

To execute only 1 iteration run:
    `php realtime-daemon.php --dnd`

To send termination signal run:
    `php realtime-daemon.php --kill`
In this case the last iteration will be completely performed so it is the "soft" termination.
See `http://en.wikipedia.org/wiki/SIGTERM#SIGTERM` for more information.

To organize multiprocessing pass the --process-number argument:

    php realtime-daemon-process1.php --process-number 1
    php realtime-daemon-process2.php --process-number 2

To kill them use:

    php realtime-daemon-process1.php --process-number 1 --kill
    php realtime-daemon-process2.php --process-number 2 --kill

Use crontab to be sure that the daemonized script will be run again after hard crush that can happen.

The object of ErrorHandler class is used for interpretator' notices and error catching and handling.
These events are logged with `PHP_MESSAGE` type so you can provide your own logic in `Daemon::log`
to process them.

Also note, that any resourses allocations (database connections etc.) should be placed after `pcntl_fork`
(after `Daemon::daemonize` actually, not before) because of tricky nature of unix forks.
