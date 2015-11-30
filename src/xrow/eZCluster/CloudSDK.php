<?php

namespace xrow\eZCluster;

use Aws\Common\Aws;
use \ezcSystemInfo;
use \SimpleXMLElement;

class CloudSDK
{
    const SITES_ROOT = '/var/www/sites';
    const USER = 'ec2-user';
    const GROUP = 'apache';
    public static $ezcTemplateConfiguration;
    const CONFIG_FILE = '/etc/ezcluster/ezcluster.xml';
    const XML_NAMESPACE = 'http://www.xrow.com/schema/ezcluster';
    const CLOUD_TYPE_AWS = 'aws';
    const CLOUD_TYPE_SIMPLE = 'simple';
    private static $ec2;
    private static $region = null;
    public static $config;
    public static $cloud;
    private static $factories;
    public static $kernels = array( 
        "US_E1" => 'aki-4e7d9527' , 
        "US_W1" => 'aki-9fa0f1da' , 
        "EU_W1" => 'aki-41eec435' , 
        "APAC_SE1" => 'aki-6dd5aa3f' , 
        "APAC_NE1" => '??' 
    );
    // in format "ec2." . $avail_zone . "amazonaws.com"
    static function kernel( $region )
    {
        if ( !array_key_exists( $region, xrowCloudInfo::$kernels ) )
        {
           throw new \Exception( "Kernel in region $region not found." );
        }
        return xrowCloudInfo::$kernels[ $region ];
    }
    static function basedir()
    {
        return realpath( dirname( __FILE__ ) . "/../" );
    }

    static function setRegion( $region )
    {
        return self::$region = $region;
    }

    static function getDefaultRegion()
    {
        return trim( @file_get_contents( 'http://169.254.169.254/latest/meta-data/placement/availability-zone' ) );
    }

    public static function init( $renew = false )
    {
        date_default_timezone_set( 'UTC' );
        $user = posix_getpwuid(posix_getuid());
        if ( $user['name'] !== "root" )
        {
            throw new \Exception( "You need to be root to execute the command." );
        }
        self::$ezcTemplateConfiguration = new \ezcTemplateConfiguration(dirname(__FILE__) . DIRECTORY_SEPARATOR . "templates", sys_get_temp_dir() . "/.compilation", new \ezcTemplateNoContext());
        self::$ezcTemplateConfiguration->checkModifiedTemplates = false;
        self::$ezcTemplateConfiguration->disableCache = true;
        $info = ezcSystemInfo::getInstance();
        //Testing for init AWS
        $fp = @fsockopen( "169.254.169.254", 80, $errno, $errstr, 1 );
        if ( $fp )
        {
            fclose( $fp );
            self::$cloud = self::CLOUD_TYPE_AWS;
        }
        elseif ( $info->osType == 'win32' )
        {
            self::$cloud = self::CLOUD_TYPE_SIMPLE;
            $renew = true;
        }
        else
        {
            self::$cloud = self::CLOUD_TYPE_SIMPLE;
        }
        
        //AWS magic not supported anymore, AWS SDK need upgade to version 2
        self::$cloud = self::CLOUD_TYPE_SIMPLE;
        if (!defined('CLOUD'))
        {
        	define( 'CLOUD', self::$cloud );
        }
        if ( ! file_exists( self::CONFIG_FILE ) or $renew )
        {
            if ( $info->osType == 'win32' and self::$cloud === self::CLOUD_TYPE_SIMPLE )
            {
                $data = trim( file_get_contents( self::basedir() . '/build/min-ezcluster.xml' ) );
            }
            elseif ( file_exists( self::CONFIG_FILE ) )
            {
                $data = trim( file_get_contents( self::CONFIG_FILE ) );
            }
            elseif ( self::$cloud === self::CLOUD_TYPE_AWS )
            {
                $data = trim( @file_get_contents( 'http://169.254.169.254/latest/user-data' ) );
                if ( strpos( $data, 'http' ) === 0 )
                {
                	$data = trim( file_get_contents( $data ) );
                }
            }
            
            if ( $data != "" )
            {
                $tmpfname = tempnam( sys_get_temp_dir(), "test_" );
                
                $handle = fopen( $tmpfname, "w" );
                fwrite( $handle, $data );
                fclose( $handle );
                try
                {
                    xrowClusterTools::validateXML( $tmpfname );
                    unlink( $tmpfname );
                }
                catch ( \Exception $e )
                {
                    unlink( $tmpfname );
                    echo $e->getMessage();
                }
                
                $file = xrowAWSSDK::CONFIG_FILE;
                
                $data = mb_convert_encoding( $data, 'UTF-8' );
                libxml_use_internal_errors( true );
                
                $data = str_replace( "xmlns=", "ns=", $data );
                $userdata = new SimpleXMLElement( $data );
                if ( ! $userdata )
                {
                    $str = "";
                    foreach ( libxml_get_errors() as $error )
                    {
                        $str .= $error->message;
                    }
                    throw new \Exception( "Failed loading XML: \n" . $str );
                }
                libxml_use_internal_errors( false );
            }
            if ( isset( $userdata ) )
            {
                $count = (int) $userdata['revision'];
                if ( $count < 1 )
                {
                    $userdata['revision'] = $count + 1;
                }
                if ( ! is_dir( dirname( self::CONFIG_FILE ) ) )
                {
                    mkdir( dirname( self::CONFIG_FILE ), 755, true );
                }
                file_put_contents( self::CONFIG_FILE, $userdata->asXML() );
                self::$config = new SimpleXMLElement( file_get_contents( self::CONFIG_FILE ) );
            }
            
            //init rpms
            if ( self::$config )
            {
                $result = self::$config->xpath( 'cluster/rpm' );
                foreach ( $result as $node )
                {
                	system("sudo yum install -y " . (string) $node);
                }
            }
        }
        if ( file_exists( self::CONFIG_FILE ) )
        {
            self::$config = new SimpleXMLElement( file_get_contents( self::CONFIG_FILE ) );
        }
        else
        {
            if ( file_exists( self::CONFIG_FILE.".dist" ) )
            {
                copy(self::CONFIG_FILE.".dist", self::CONFIG_FILE);
                self::$config = new SimpleXMLElement( file_get_contents( self::CONFIG_FILE ) );
            }
            else
            {
                throw new \Exception( "Can`t init CloudSDK. " . self::CONFIG_FILE . " is missing." . __METHOD__ );
            }
        }
        if ( self::$region === null and self::$cloud === self::CLOUD_TYPE_AWS )
        {
            $region = self::getDefaultRegion();
            switch ( $region )
            {
                case 'eu-west-1a':
                case 'eu-west-1b':
                case 'eu-west-1c':
                    self::$region = "EU_W1";
                    break;
                case 'eu-central-1a':
                case 'eu-central-1b':
                case 'eu-central-1c':
                    self::$region = "EU_W1";
                    break;
                case 'us-west-1a':
                case 'us-west-1b':
                case 'us-west-1c':
                    self::$region = "US_W1";
                    break;
                case 'us-east-1a':
                case 'us-east-1b':
                case 'us-east-1c':
                case 'us-east-1d':
                case 'us-east-1e':
                    self::$region = "US_E1";
                    break;
                default:
                    throw new \Exception( "Region $region is not defined" );
                    break;
            }
        
        }
            if ( self::$config )
            {
                $result = self::$config->xpath( "/aws/sshkey[@name = 'APIKEY']" );
                foreach ( $result as $node )
                {
                    file_put_contents( "/etc/ezcluster/aws.pem", (string)$node );
                }
                $result = self::$config->xpath( "/aws/sshkey[@name = 'APICERT']" );
                foreach ( $result as $node )
                {
                    file_put_contents( "/etc/ezcluster/aws.cert", (string)$node );
                }
                $result = self::$config->xpath( "/aws/sshkey[@name = 'deploy']" );
                foreach ( $result as $node )
                {
                    $file="/home/" . self::USER . "/.ssh/id_rsa";
                    file_put_contents( $file, trim((string)$node) );
                    chmod( $file, 0600 );
                    chown( $file, self::USER );
                    chgrp( $file, self::GROUP );
                }
            }        
        self::factory();
    }
    static public function factoryAWS2( $name = 'SesClient', $namespace = "Aws\\Ses" )
    {
        return call_user_func( array( $namespace . "\\" . $name , 'factory' ),
                    array( 'key'    => AWS_KEY, 'secret' => AWS_SECRET_KEY, 'region' => substr( self::getDefaultRegion(), 0, 9 ) )
        );
    }
    static public function factoryAWS( $name = 'AmazonEC2' )
    {
        if ( isset( self::$factories[$name] ) )
        {
            if ( self::$region )
            {
                self::$factories[$name]->set_region( self::$region );
            }
            return self::$factories[$name];
        }
        self::$factories[$name] = new $name();
        if ( self::$region )
        {
            self::$factories[$name]->set_region( self::$region );
        }
        $info = ezcSystemInfo::getInstance();
        if ( $info->osType == 'win32' )
        {
            self::$factories[$name]->ssl_verification = false;
        }
        
        return self::$factories[$name];
    }

    static public function factory()
    {
        if ( ! isset( self::$config ) )
        {
            self::init();
        }
        
        if ( ! defined( 'AWS_KEY' ) and (string) self::$config['access_key'] )
        {
            define( 'AWS_KEY', (string) self::$config['access_key'] );
        }
        if ( ! defined( 'AWS_SECRET_KEY' ) and (string) self::$config['secret_key'] )
        {
            define( 'AWS_SECRET_KEY', (string) self::$config['secret_key'] );
        }
        
        if ( defined( 'AWS_SECRET_KEY' ) and defined( 'AWS_KEY' ) )
        {
            $config = array(
               'key'    => AWS_KEY,
               'secret' => AWS_SECRET_KEY,
            );
            $aws = Aws::factory( $config );
            if ( ! $aws->get('s3') )
            {
                throw new \Exception( "No login to AWS" );
            }
            return $aws;
        }
    }
}
