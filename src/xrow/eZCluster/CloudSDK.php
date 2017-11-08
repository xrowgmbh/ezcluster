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
    const LOG_DIR = "/var/log/ezcluster";
    
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
    static function path()
    {
        if ( file_exists("/opt/rh/rh-php71/root/usr/bin/php") )
        {
            return "/opt/rh/rh-php71/root/usr/bin:/opt/rh/rh-php71/root/usr/sbin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin";
        }
        elseif ( file_exists("/opt/rh/rh-php70/root/usr/bin/php") )
        {
            return "/opt/rh/rh-php70/root/usr/bin:/opt/rh/rh-php70/root/usr/sbin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin";
        }
        elseif ( file_exists("/opt/rh/rh-php56/root/usr/bin/php") )
        {
            return "/opt/rh/rh-php56/root/usr/bin:/opt/rh/rh-php56/root/usr/sbin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin";
        }
        elseif ( file_exists("/opt/rh/php55/root/usr/bin") ){
            return "/opt/rh/php55/root/usr/bin:/opt/rh/php55/root/usr/sbin:/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/root/bin";
        }
        else{
            return "/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/root/bin";
        }
    }
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
        return realpath( dirname( __FILE__ ) . "/../../../" );
    }

    static function setRegion( $region )
    {
        return self::$region = $region;
    }

    static function getDefaultRegion()
    {
        return trim( @file_get_contents( 'http://169.254.169.254/latest/meta-data/placement/availability-zone' ) );
    }

    public static function init()
    {
        date_default_timezone_set( 'UTC' );
        $user = posix_getpwuid(posix_getuid());
        if ( $user['name'] !== "root" )
        {
            throw new \Exception( "You need to be root to execute the command." );
        }
        ClusterTools::mkdir( self::LOG_DIR, "root", 0777 );
        
        self::$ezcTemplateConfiguration = new \ezcTemplateConfiguration(dirname(__FILE__) . DIRECTORY_SEPARATOR . "templates", sys_get_temp_dir() . "/.compilation", new \ezcTemplateNoContext());
        self::$ezcTemplateConfiguration->checkModifiedTemplates = false;
        self::$ezcTemplateConfiguration->disableCache = true;
        $info = ezcSystemInfo::getInstance();

        self::$cloud = self::CLOUD_TYPE_SIMPLE;

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
                $result = self::$config->xpath( "sshkey[@name = 'APIKEY']" );
                foreach ( $result as $node )
                {
                    file_put_contents( "/etc/ezcluster/aws.pem", (string)$node );
                }
                $result = self::$config->xpath( "sshkey[@name = 'APICERT']" );
                foreach ( $result as $node )
                {
                    file_put_contents( "/etc/ezcluster/aws.cert", (string)$node );
                }
                $result = self::$config->xpath( "sshkey" );
                $config = "/home/" . self::USER . "/.ssh/config";
                $configtext = <<<CONFIG
# Autogenerated by src/xrow/eZCluster/CloudSDK.php

CONFIG;
                file_put_contents($config, $configtext);

                foreach ( $result as $node )
                {
                    if ( $node['host'] )
                    {
                        $host = (string)$node['host'];
                        $file="/home/" . self::USER . "/.ssh/id_rsa_" . $host;
                        if (isset( $node['user'] ))
                        {
                            file_put_contents($config, "Host " . $host . "\n    User ". (string)$node['user'] ."\n    StrictHostKeyChecking no\n    UserKnownHostsFile /dev/null\n    IdentityFile " . $file . "\n", FILE_APPEND | LOCK_EX);
                            
                        }
                        else {
                            file_put_contents($config, "Host " . $host . "\n    StrictHostKeyChecking no\n    UserKnownHostsFile /dev/null\n    IdentityFile " . $file . "\n", FILE_APPEND | LOCK_EX);
                        }
                    }
                    else
                    {
                        $host = false;
                        $file="/home/" . self::USER . "/.ssh/id_rsa";
                    }
                    file_put_contents( $file, trim((string)$node) );
                    chmod( $file, 0600 );
                    chown( $file, self::USER );
                    chgrp( $file, self::GROUP );
                    #$key = openssl_pkey_get_private(file_get_contents( $file ));
                    # @TODO How to covert to rsa format wiht PHP lib
                    #$keyData = openssl_pkey_get_details($key);
                    # file_put_contents( $file . '.pub', $keyData['key']);
                    $cmd = "ssh-keygen -y -f " . escapeshellarg($file) . " > " . escapeshellarg($file) . ".pub";
                    system( $cmd );
                    chmod(  $file . '.pub', 0600 );
                    chown(  $file . '.pub', self::USER );
                    chgrp(  $file . '.pub', self::GROUP );
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
