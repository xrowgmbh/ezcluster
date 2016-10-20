<?php
namespace xrow\eZCluster;

class ClusterTools
{

    const TMP_PRIVATE_KEY = '/tmp/id_rsa';
    const TMP_PUBLIC_KEY = '/tmp/id_rsa.pub';
    const TMP_KEY = '/tmp/id_rsa.pub';
    const CENTOS_RELEASE = "centos-release-6-2.el6.centos.7";
    const RPM_CENTOS = "http://mirror.centos.org/centos/6.4/os/x86_64/Packages/centos-release-6-4.el6.centos.10.x86_64.rpm";
    const RPM_EPEL = "http://dl.fedoraproject.org/pub/epel/6/x86_64/epel-release-6-8.noarch.rpm";
    const RPM_XROW = "http://packages.xrow.com/redhat/6/xrow-repo-2.2-34.noarch.rpm";

    static function constantsScriptString()
    {
        $str = "#!/bin/sh\n";
        $str .= "RPM_EPEL=" . self::RPM_EPEL . "\n";
        $str .= "RPM_XROW=" . self::RPM_XROW . "\n";
        $str .= "RPM_CENTOS=" . self::RPM_CENTOS . "\n";
        $str .= "CENTOS_RELEASE=" . basename( self::RPM_CENTOS, '.x86_64.rpm' ) . "\n";
        return $str;
    }

    static function libxml_display_errors()
    {
        $return = '';
        $errors = libxml_get_errors();
        foreach ( $errors as $error )
        {
            switch ( $error->level )
            {
                case LIBXML_ERR_WARNING:
                    $return .= "Warning $error->code: ";
                    break;
                case LIBXML_ERR_ERROR:
                    $return .= "Error $error->code: ";
                    break;
                case LIBXML_ERR_FATAL:
                    $return .= "Fatal Error $error->code: ";
                    break;
            }
            $return .= trim( $error->message );
            if ( $error->file )
            {
                $return .= " in $error->file";
            }
            $return .= " on line $error->line\n";
        }
        libxml_clear_errors();
        return $return;
    }

    static function sendMail( $emailaddress, $text, $subject = null )
    {
        if ( empty( $emailaddress ) )
        {
            throw new \Exception( "Please provide an email address." );
        }
        $email = CloudSDK::factoryAWS2( 'SesClient' );
        
        $response = $email->send_email( 'service@xrow.com', array( 
            'ToAddresses' => $emailaddress 
        ), array( 
            'Subject.Data' => $subject , 
            'Body.Text.Data' => $text 
        ) );
        if ( ! $response->isOK() )
        {
            throw new \Exception(  (string) $response->body->Errors->Error->Message );
        }
        return true; 
    }

    static function validateXML( $file, $schema = '/schema/ezcluster.xsd' )
    {
        // Enable user error handling
        libxml_use_internal_errors( true );
        libxml_disable_entity_loader( false );
        
        $xml = new \DOMDocument();
        $xml->load( $file );
        
        if ( ! $xml->schemaValidate( CloudSDK::basedir() . $schema ) )
        {
            throw new \Exception( self::libxml_display_errors() );
        }
        return true;
    }

    /**
     *
     * @param string $range
     *            ISO formated time periods "start date / time period" like 1-08:00/PT10H;2-08:00/PT10H;3-08:00/PT10H:00;4-08:00/PT10H;5-08:00/PT10H
     * @param DateTime $dt            
     * @return boolean returns true if $dt is in $range
     */
    static public function isDateTimeinRange( $range, DateTime $dt = null )
    {
        if ( empty( $range ) )
        {
            return false;
        }
        if ( $dt === null )
        {
            $dt = new DateTime();
        }
        $now = new DateTime();
        $list = explode( ';', $range );
        foreach ( $list as $item )
        {
            list ( $startstr, $period ) = explode( "/", $item );
            $start = new DateTime( $now->format( 'Y' ) . '-W' . $now->format( 'W' ) . '-' . $startstr );
            $end = new DateTime( $now->format( 'Y' ) . '-W' . $now->format( 'W' ) . '-' . $startstr );
            $interval = new DateInterval( $period );
            $end->add( $interval );
            if ( $start <= $dt and $end >= $dt )
            {
                return true;
            }
        }
        return false;
    }

    static public function mkdir( $dir, $user = false, $permissions = 0775, $group = CloudSDK::GROUP )
    {
        if ( is_dir( $dir ) )
        {
            return false;
        }
        $old = umask(0);
        mkdir( $dir, $permissions, true );
        if ( $user !== false )
        {
            chown( $dir, $user );
            chgrp( $dir, $group );
        }
        umask($old);
    }

    static public function cleanString( $name )
    {
        // remove chars like -:+
        // remove Frist chars, if not alpha
        $name = preg_replace( '/^[^(\x41-\x5A)(\x61-\x7A)]+/', '', $name );
        // a-z,A-Z,0-9,-
        $name = preg_replace( '/[^(\x41-\x5A)(\x61-\x7A)(\x30-\x39)\-]+/', '', $name );
        $name = preg_replace( '/[\-]+$/', '', $name );
        return $name;
    }

    static public function backup( $days = 14 )
    {
        $date = new DateTime();
        $instance = new ClusterNode();
        /*
         * Snapshot RDS $xp = "/aws/cluster[ @lb = '" . $instance->getLB() . "' ]/rds"; $result = $instance::$config->xpath( $xp ); if ( is_array( $result ) and count( $result ) > 0 ) { $masterdsn = (string) $result[0]['dsn']; }
         */
        // Backup MYSQL DBs
        $dsns = array();
        
        $xp = "/aws/cluster[ @lb = '" . $instance->getLB() . "' ]/instance[role = 'database' and @name='" . $instance->getTag( 'Name' ) . "'] | /aws/cluster[ @lb = '" . $instance->getLB() . "' ]/instance[role = 'dev' and @name='" . $instance->getTag( 'Name' ) . "']";
        
        $result = $instance::$config->xpath( $xp );
        if ( is_array( $result ) and count( $result ) > 0 )
        {
            $dsns[] = 'mysql://root@localhost';
        }
        
        $xp = "/aws/cluster[ @lb = '" . $instance->getLB() . "' ]/environment/database | /aws/cluster[ @lb = '" . $instance->getLB() . "' ]/environment/storage";
        
        $result = $instance::$config->xpath( $xp );
        if ( is_array( $result ) and count( $result ) > 0 )
        {
            foreach ( $result as $dbtag )
            {
                $dsns[] = (string) $dbtag['dsn'];
            }
        }
        
        if ( empty( $dsns ) )
        {
            throw new \Exception( "There is no database connection defined." );
        }
        xrowClusterTools::mkdir( "/mnt/nas/.backup" );
        
        foreach ( $dsns as $dsn )
        {
            $dbdetails = ezcDbFactory::parseDSN( $dsn );
            $db = ezcDbFactory::create( $dsn );
            ezcDbInstance::set( $db );
            $db->query( "SET NAMES utf8" );
            
            if ( empty( $dbdetails['database'] ) )
            {
                $rows = $db->query( 'SHOW DATABASES' );
                $dbs = array();
                foreach ( $rows as $row )
                {
                    if ( ! in_array( $row, array( 
                        'mysql' , 
                        'information_schema' 
                    ) ) )
                    {
                        $dbs[] = $row['database'];
                    }
                }
            }
            else
            {
                $cmd = "mysqldump -n --opt --single-transaction -h" . $dbdetails['hostspec'] . " -u" . $dbdetails['username'] . " -p" . $dbdetails['password'] . " " . $dbdetails['database'] . " | gzip > /mnt/nas/.backup/" . $dbdetails['hostspec'] . "_" . $dbdetails['database'] . ".sql.gz";
                
                // ystem( $cmd, $return );
                // cho "DB backup return $return \n";
            }
        }
        
        // Snapshot RDS
        $rds = CloudSDK::factoryAWS( 'AmazonRDS' );
        
        $response = $rds->describe_db_instances();
        if ( ! $response->isOK() )
        {
            throw new \Exception( $response );
        }
        
        foreach ( $response->body->DescribeDBInstancesResult->DBInstances->DBInstance as $db )
        {
            if ( (string) $db->DBInstanceStatus == 'backing-up' )
            {
                continue;
            }
            
            $name = (string) $db->DBInstanceIdentifier;
            
            $ssname = 'BACKUP-' . $name . '-' . $date->format( DateTime::ISO8601 );
            $ssname = self::cleanString( $ssname );
            
            $response = $rds->create_db_snapshot( $ssname, $name );
            
            if ( ! $response->isOK() )
            {
                throw new \Exception( $response );
            }
        }
        
        // Snapshot storage
        $result = $instance::$config->xpath( "/aws/cluster[ @lb = '" . $instance->getLB() . "' ]/instance[role = 'storage' or role = 'storage-slave']" );
        
        $response = CloudSDK::factory()->describe_instances();
        
        foreach ( $response->body->reservationSet->item as $set )
        {
            if ( (string) $set->instancesSet->item->instanceState->name == 'running' )
            {
                $instances[] = new ClusterNode( (string) $set->instancesSet->item->instanceId );
            }
        }
        
        foreach ( $instances as $key => $instance )
        {
            if ( in_array( 'storage', $instance->roles() ) or in_array( 'storage-slave', $instance->roles() ) )
            {
                $vol = volume::getByPath( '/dev/xvdf', $instance );
                $name = self::cleanString( 'BACKUP-' . $instance->getTag( 'Name' ) . "-" . $date->format( DateTime::ISO8601 ) );
                $ss = $vol->snapshot( $name ); //
                $ss->setTag( 'Name', $name );
            }
        }
    }
    /*
     * Prototype of a region function
     */
    static public function convertRegion( $mixed, $class = 'AmazonELB' )
    {
        if ( is_array( $mixed ) )
        {
            $result = array();
            foreach ( $mixed as $region )
            {
                $result[] = constant( $class . '::' . $region );
            }
            return $result;
        }
        else
        {
            return constant( $class . '::' . $region );
        }
    }
}
