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

    static function deploy()
    {
        $pubfile = self::TMP_PUBLIC_KEY;
        $keyfile = self::TMP_PRIVATE_KEY;
        $result = CloudSDK::$config->xpath( "/aws/sshkey[@name='deployment']" );
        if ( isset( $result[0] ) )
        {
            $key = (string) $result[0];
            if ( file_exists( $keyfile ) )
            {
                unlink( $keyfile );
            }
            if ( file_exists( $pubfile ) )
            {
                unlink( $pubfile );
            }
            file_put_contents( $keyfile, $key );
            chmod( $keyfile, 0600 );
            system( "ssh-keygen -y -f /tmp/id_rsa > $pubfile");
            chmod( $pubfile, 0600 );
        }
        else
        {
            throw new Exception( "No SSH Key is provided in config" );
        }
        $node = new ClusterNode();
        $xp = "/aws/cluster[ @lb = '" . $node->getLB() . "' ]/instance";
        $result = $node::$config->xpath( $xp );
        if ( is_array( $result ) )
        {
            $instances = array();
            foreach ( $result as $server )
            {
                $instance = ClusterNode::byName( (string) $server['name'] );
                if( $instance )
                {
                    $instances[] = ClusterNode::byName( (string) $server['name'] );
                }
            }
            foreach ( $instances as $instance )
            {
                if (!$instance->checkInstance( ))
                {
                    throw new Exception( "Instance #" . $instance->id . "isn't online.");
                }
            }
            foreach ( $instances as $instance )
            {
                if ( $instance instanceof ClusterNode and ( in_array( 'web', $instance->roles() ) or in_array( 'admin', $instance->roles() ) ) )
                {
                    self::deployInstance( $instance );
                }
            }
        }
        if ( file_exists( $keyfile ) )
        {
            unlink( $keyfile );
        }
        if ( file_exists( $pubfile ) )
        {
            unlink( $pubfile );
        }
        return true;
    }

    static function deployInstance( $instance )
    {

        $ip = $instance->ip();
        if ( fsockopen( $instance->ip(), 22 ) === false )
        {
            throw new Exception( "No connect to $ip." );
        }
        echo "Connect to " . $instance->id . "  " . $instance->ip() . "\n";
        $connection = ssh2_connect( $instance->ip(), 22, array( 
            'hostkey' => 'ssh-rsa' 
        ) );

        if ( ! is_resource( $connection ) )
        {
            throw new Exception( "No ssh-rsa connect to $ip." );
        }
        
        if ( $methods = ssh2_methods_negotiated( $connection ) and isset( $methods['hostkey'] ) and $methods['hostkey'] != 'ssh-rsa' )
        {
            throw new Exception( "No connect to $ip." );
        }
        /** might work later on centos 7 or zend server php 5.4 with ssh agent
        if ( ssh2_auth_agent( $connection, 'ec2-user' ) )
        {
            echo "Authentication Successful!\n";
        }
        else
        {
            echo "Authentication failed!\n";
        }
        */

        if ( !ssh2_auth_pubkey_file( $connection, 'ec2-user', self::TMP_PUBLIC_KEY, self::TMP_PRIVATE_KEY ) )
        {
            throw new Exception( "Authentification failed." );
        }
        $tmpfname = tempnam( sys_get_temp_dir(), 'BUILDTMP' );
        $lb = new lb( $instance->getLB() );
        $lb->deregister( $instance );
        $handle = fopen( $tmpfname, "w" );
//@TODO Determine how to kill cron processes without killing the deployment script itself 
        $str = <<<EOF
#!/bin/sh
sleep 5
sudo /etc/init.d/crond stop
sudo /etc/init.d/varnish stop
sudo /etc/init.d/httpd stop
sudo ezcluster clean
sudo ezcluster bootstrap
sudo ezcluster setupcrons
sudo /etc/init.d/httpd start
sudo /etc/init.d/varnish start
sudo /etc/init.d/crond start
EOF;
        fwrite( $handle, $str );
        fclose( $handle );
        $node = new ClusterNode();
        if ( $node->id != $instance->id && !ssh2_scp_send( $connection, '/etc/ezcluster/ezcluster.xml', '/etc/ezcluster/ezcluster.xml', 0777 ) )
        {
            throw new Exception( "Can`t copy ezcluster.xml." );
        }
        if ( !ssh2_scp_send( $connection, $tmpfname, '/home/ec2-user/deploy.sh', 0644 ) )
        {
            throw new Exception( "Can`t copy deploy.sh." );   
        }
        unlink( $tmpfname );
        $stream = ssh2_exec( $connection, 'nohup sh --login /home/ec2-user/deploy.sh > /home/ec2-user/deploy.out 2> /home/ec2-user/deploy.err < /dev/null &' );
        $started = false;
        while (true) {
        	if ( !$started and !$instance->checkInstance( ) )
        	{
        		$started = true;
        	}
        	if ( $started and $instance->checkInstance( ) )
        	{
        	    echo "\nDeplyoment done #" . $instance->id . "\n";
        	    break;
        	}
        	echo ".";
        	sleep( 2 );
        }
        echo "\n";
        $lb->register( $instance );
        sleep( 10 );
    }

    static function buildString()
    {
        $str = "#!/bin/sh\n";
        $str .= "#yum -y update disable takes too long\n";
        // AMI COMES WITH EPEL
        //$str .= "rpm --nosignature -i " . self::RPM_EPEL . "\n";
        $str .= "yum -y install " . self::RPM_XROW . "\n";
        $str .= "yum -y --disablerepo=* --enablerepo=amzn*,xrow,xrow-opt,zend* install xrow-zend xrow-zend-packages \n";
        $str .= "yum -y --disablerepo=* --enablerepo=amzn*,xrow,xrow-opt,zend*,xrow-opt install ezcluster\n";
        $str .= "ezcluster create el6 2>&1 | tee -a /tmp/message\n";
        $str .= "ezcluster send bjoern@xrow.de 'Buildinfo' '/tmp/build.out'\n";
        $str .= "#ezcluster terminate\n";
        return $str;
    }

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
            throw new Exception( "Please provide an email address." );
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
            throw new Exception(  (string) $response->body->Errors->Error->Message );
        }
        return true; 
    }

    static function validateXML( $file, $schema = '/schema/ezcluster.xsd' )
    {
        // Enable user error handling
        libxml_use_internal_errors( true );
        libxml_disable_entity_loader( false );
        
        $xml = new DOMDocument();
        $xml->load( $file );
        
        if ( ! $xml->schemaValidate( CloudSDK::basedir() . $schema ) )
        {
            throw new Exception( self::libxml_display_errors() );
        }
        return true;
    }

    static public function vagrant( )
    {        
/**
yum install VirtualBox-4.2 vagrant
yum install https://dl.bintray.com/mitchellh/vagrant/vagrant_1.6.2_x86_64.rpm
KERN_DIR=/usr/src/kernels/2.6.32-358.6.1.el6.centos.plus.x86_64
export KERN_DIR
/etc/init.d/vboxdrv setup
*/
        $olddir = getcwd();

        chdir( "/usr/share/ezcluster/build/vagrant/" );
        file_put_contents( "/usr/share/ezcluster/build/vagrant/bootstrap.sh", file_get_contents( "https://raw.githubusercontent.com/xrowgmbh/xrowvagrant/master/bootstrap.sh" ) );
        system( "vagrant destroy --force" );
        echo "Build\n";
        system( "vagrant up centos64" );
        system( "vagrant halt" );
        $id = file_get_contents( ".vagrant/machines/centos64/virtualbox/id" );
        echo "Package\n";
        system( "vagrant package --base $id --output ezpublish.box --vagrantfile Vagrantfile.dist" );
        /*$s3 = Aws::factory(array('key' => $awskey,
                                       'secret' => $secretkey,
                                       'region' => $region))->get('s3');
        $s3->putObject(array(
    'Bucket'     => 'xrow',
    'Key'        => 'images/ezpublish.box',
    'SourceFile' => '/usr/share/ezcluster/build/vagrant/ezpublish.box'
     ));*/
        system( "s3cmd put ezpublish.box s3://xrow/downloads/images/ezpublish.box" );
        system( "mkdir source/" );
        system( "tar -C source/ -xzvf ezpublish.box" );
        system( "mv source/box-disk1.vmdk source/ezpublish.vmdk");
        system( "s3cmd put --acl-public source/ezpublish.vmdk s3://xrow/downloads/images/ezpublish.vmdk" );
        system( "vboxmanage clonehd --format VHD source/ezpublish.vmdk source/ezpublish.vhd" );
        system( "vboxmanage clonehd --format VDI source/ezpublish.vmdk source/ezpublish.vdi" );        
        system( "s3cmd put --acl-public source/ezpublish.vdh s3://xrow/downloads/images/ezpublish.vdh" );
        system( "s3cmd put --acl-public source/ezpublish.vdi s3://xrow/downloads/images/ezpublish.vdi" ); 
        unlink( "/usr/share/ezcluster/build/vagrant/ezpublish.box");
        unlink( "source/");
        chdir( $olddir );
    }
    static public function createOS2( $version = "el5", $path = '/mnt', $drive = '/dev/sdh' )
    {
        file_put_contents( "/tmp/constants.sh", self::constantsScriptString() );
        system( "sh /usr/share/ezcluster/lib/scripts/" . $version . "/build.sh $path $drive" );
        // create constants
        file_put_contents( $path . "/constants.sh", self::constantsScriptString() );
        system( "/usr/sbin/chroot $path /setup.sh" );
        system( "/usr/sbin/chroot $path /usr/share/ezcluster/lib/scripts/debug.sh" );
        system( "sh /usr/share/ezcluster/lib/scripts/" . $version . "/clean.sh $path $drive" );
    }

    static public function createOS( $version = "el5", $path = '/mnt', $drive = '/dev/sdh' )
    {
        file_put_contents( "/tmp/constants.sh", self::constantsScriptString() );
        system( "sh /usr/share/ezcluster/lib/scripts/" . $version . "/build.sh $path $drive" );
        // create constants
        file_put_contents( $path . "/constants.sh", self::constantsScriptString() );
        system( "/usr/sbin/chroot $path /setup.sh" );
        system( "sh /usr/share/ezcluster/lib/scripts/" . $version . "/clean.sh $path $drive" );
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

    static public function mkdir( $dir, $user = false, $permissions = 0775 )
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
            chgrp( $dir, $user );
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
            throw new Exception( "There is no database connection defined." );
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
            throw new xrowAWSException( $response );
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
                throw new xrowAWSException( $response );
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

    static public function cloudFormation()
    {
        $cf = new stdClass();
        $cf->Parameters = new stdClass();
        
        $key = new stdClass();
        $key->Description = "The EC2 Key Pair to allow SSH access to the instance";
        $key->Type = "String";
        $cf->Parameters->KeyName = $key;
        
        $cf->Mappings = new stdClass();
        $cf->Mappings->RegionMap = new stdClass();
        $ami = new stdClass();
        $ami->AMI = "ami-76f0061f";
        $cf->Mappings->RegionMap->{'us-east-1'} = $ami;
        $ami = new stdClass();
        $ami->AMI = "ami-76f0061f";
        $cf->Mappings->RegionMap->{'eu-west-1'} = $ami;
        
        $cf->Resources = new stdClass();
        
        $sg = new stdClass();
        $sg->Type = "AWS::EC2::SecurityGroup";
        $sg->Properties = new stdClass();
        $sg->Properties->GroupDescription = 'Rules for eZ Cluster';
        $sg->Properties->SecurityGroupIngress = array();
        $pol = new stdClass();
        $pol->IpProtocol = 'tcp';
        $pol->FromPort = '22';
        $pol->ToPort = '22';
        $pol->CidrIp = "0.0.0.0/0";
        $sg->Properties->SecurityGroupIngress[] = $pol;
        $pol = new stdClass();
        $pol->IpProtocol = 'tcp';
        $pol->FromPort = '80';
        $pol->ToPort = '80';
        $pol->CidrIp = "0.0.0.0/0";
        $sg->Properties->SecurityGroupIngress[] = $pol;
        $pol = new stdClass();
        $pol->IpProtocol = 'tcp';
        $pol->FromPort = '443';
        $pol->ToPort = '443';
        $pol->CidrIp = "0.0.0.0/0";
        $sg->Properties->SecurityGroupIngress[] = $pol;
        $cf->Resources->InstanceSecurityGroup = $sg;
        
        $cf->Outputs = new stdClass();
        $cf->Outputs->InstallURL = new stdClass();
        $cf->Outputs->InstallURL->Value = new stdClass();
        $fn = new stdClass();
        $fn->{'Fn::GetAtt'} = array( 
            'ElasticLoadBalancer' , 
            'DNSName' 
        );
        $cf->Outputs->InstallURL->Value->{'Fn::Join'} = array( 
            '' , 
            array( 
                'http://' , 
                $fn , 
                "/" 
            ) 
        );
        $cf->Outputs->InstallURL->Description = "Installation URL of the website";
        
        $result = CloudSDK::$config->xpath( "/aws/cluster/instance" );
        foreach ( $result as $instance )
        {
            $sec = new stdClass();
            $sec->Ref = 'InstanceSecurityGroup';
            $keyref = new stdClass();
            $keyref->Ref = 'KeyName';
            
            $obj = new stdClass();
            $obj->Type = "AWS::EC2::Instance";
            $obj->Properties = new stdClass();
            $obj->Properties->SecurityGroups = array( 
                $sec 
            );
            $obj->Properties->KeyName = $keyref;
            $obj->Properties->ImageId = "ami-7a11e213";
            $cf->Resources->Ec2Instance = $obj;
        }
        file_put_contents( 'cf.json', json_encode( $cf ) );
        echo json_encode( $cf );
    }

    static public function createImage( $region = AmazonEC2::REGION_US_E1, $type = 'el6' )
    {
        CloudSDK::setRegion( $region );
        if ( ! function_exists( 'ssh2_connect' ) )
        {
            throw new Exception( "SSH2 PHP module is not enabled." );
        }
        if ( !file_get_contents( self::RPM_CENTOS ) )
        {
            throw new Exception( "CEntos RPM not available." );
        }
        else
        {
            echo "Building OS: " . basename( self::RPM_CENTOS, '.x86_64.rpm' ) . "\n";
        }
        $result = CloudSDK::$config->xpath( "/aws/sshkey[@name='build']" );
        if ( isset( $result[0] ) )
        {
            $key = (string) $result[0];
            $key = openssl_pkey_get_private( $key );
        }
        else
        {
            throw new Exception( "No SSH Key is provided in config" );
        }
        
        $ec2 = CloudSDK::factoryAWS( 'AmazonEC2' );
        $ec2->ssl_verification = false;
        $pubkey = 'ssh-rsa AAAAB3NzaC1yc2EAAAABIwAAAIEAmKtOFjv/OLjzPP7VyjndOJJvxfzOIEfhJ+FXhiUVTOFFdTMXV2si0rqL3I8ot2mwM8bpeqvQr5zfng0CPOxl8ydkPsRY2qflyKWO19/nV3R/R5z29P+DgyQgfAiK5gbh2mMgdRkLn0MmE2GULKu7OGPUXIgRJpUTBVziySMAcSU= service@xrow.de';
        $response = $ec2->import_key_pair( 'auto-build-key', $pubkey );
        if ( ! $response->isOK() and (string) $response->body->Errors->Error->Code != 'InvalidKeyPair.Duplicate' )
        {
            throw new xrowAWSException( $response );
        }
        
        while ( $instance = instance::byName( xrowCloudInfo::$buildname[$region] ) )
        {
            echo "shutting down old " . $instance->id . "\n";
            $instance->terminate();
            sleep( 5 );
        }
        $out = new ezcConsoleOutput();
        
        $status = new ezcConsoleProgressMonitor( $out, 8 );
        $status->addEntry( 'ACTION', "Using AMI Build '".xrowCloudInfo::$buildami[$region]."'#1." );
        $response = $ec2->run_instances( xrowCloudInfo::$buildami[$region], 1, 1, array( 
            'InstanceType' => 't1.micro' , 
            'KeyName' => 'auto-build-key' , 
            'UserData' => base64_encode( CloudSDK::$config->asXML() ) 
        ) );
        if ( ! $response->isOK() )
        {
            throw new xrowAWSException( $response );
        }
        
        $id = (string) $response->body->instancesSet->item->instanceId;
        
        $instance = new instance( $id );
        $instance->setTag( 'Name', xrowCloudInfo::$buildname[$region] );
        $started = false;
        

        do
        {
            sleep( 5 );
            
            $info = $instance->describe();
            
            $ip = (string) $info->ipAddress;
            if ( $ip )
            {
                $status->addEntry( 'ACTION', "Server '$id' availaible #2." );
                break;
            }
        }
        while ( true );
        do
        {
            sleep( 2 );
            if ( fsockopen( $ip, 22 ) !== false )
            {
                break;
            }
        }
        while ( true );
        do
        {
            sleep( 5 );
            
            $connection = @ssh2_connect( $ip, 22, array( 
                'hostkey' => 'ssh-rsa' 
            ) );
            
            if ( is_resource( $connection ) )
            {
                $status->addEntry( 'ACTION', "Performed SSH connection to $ip #3." );
                break;
            }
        }
        while ( true );
        
        do
        {
            sleep( 5 );
            if ( $methods = ssh2_methods_negotiated( $connection ) and isset( $methods['hostkey'] ) and $methods['hostkey'] == 'ssh-rsa' )
            {
                $status->addEntry( 'ACTION', "Performed SSH check #4." );
                break;
            }
        }
        while ( true );
        
        do
        {
            if ( ezcSystemInfo::getInstance()->osType == 'win32' )
            {
                $pubfile = 'x:\Identity Keys & Passwords\xrow\id_rsa.pub';
                $keyfile = 'x:\Identity Keys & Passwords\xrow\id_rsa';
            }
            else
            {
                $pubfile = '/root/id_rsa.pub';
                $keyfile = '/root/id_rsa';
            }
            
            if ( ! file_exists( $pubfile ) or ! file_exists( $keyfile ) )
            {
                $instance->terminate();
                throw new Exception( "Key files '" . $keyfile . "' and '" . $pubfile . "' do not exists..." );
            }
            sleep( 5 );
            if ( ssh2_auth_pubkey_file( $connection, 'ec2-user', $pubfile, $keyfile ) )
            {
                $status->addEntry( 'ACTION', "Performed SSH AUTH #5." );
                break;
            }
        }
        while ( true );
        $tmpfname = tempnam( sys_get_temp_dir(), 'BUILDTMP' );
        
        $handle = fopen( $tmpfname, "w" );
        $str = self::buildString();
        fwrite( $handle, $str );
        fclose( $handle );
        
        if ( ssh2_scp_send( $connection, $tmpfname, '/home/ec2-user/build.sh', 0644 ) )
        {
            $status->addEntry( 'ACTION', "Performed Upload of build file #6." );
        }
        unlink( $tmpfname );
        
        sleep( 5 );
//        $stream = ssh2_exec( $connection, 'nohup sudo sh --login /home/ec2-user/build.sh > /tmp/build.out 2> /tmp/build.err < /dev/null &', "ansi" );
        $stream = ssh2_shell( $connection, 'xterm', null, 120, 24, SSH2_TERM_UNIT_CHARS);
        fwrite($stream, 'nohup sudo sh --login /home/ec2-user/build.sh > /tmp/build.out 2> /tmp/build.err < /dev/null &' . PHP_EOL);
        sleep(1);
        stream_set_blocking($stream, false);
        $status->addEntry( 'ACTION', "Execute build file #7." );
        
        sleep( 5 );
        fwrite( $stream, 'ps -ax > /tmp/test.log' . PHP_EOL );
        $status->addEntry( 'ACTION', "Close server connection #8." );
        fclose($stream);
    }
}
