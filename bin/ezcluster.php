#!/usr/bin/env php
<?php

require_once realpath( dirname( __FILE__ ) . '/../vendor/autoload.php' );

use Symfony\Component\Console\Application;
use xrow\eZCluster\eZClusterCommand;

$application = new Application();
$application->add(new eZClusterCommand);
$application->run();

// Set our real user ID to root like a sudo
//@TODO How todo on redhat 6?
/*
$success = posix_setuid( 0 );
if ( ! $success )
{
    echo "Error: Cannot uid() to root.\n";
    exit( 1 );
}

*/
