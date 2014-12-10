<?php
namespace xrow\eZCluster;

use \SimpleXMLElement;
use \ezcTemplate;
use \stdClass;
use xrow\eZCluster\Resources\db;
use xrow\eZCluster\Resources\lb;
use xrow\eZCluster\Resources\certificate;
use xrow\eZCluster\Resources\instance;
use xrow\eZCluster\Resources;
use Ssh;
use \ezcDbFactory;
use \ezcDbInstance;

class ClusterNode extends Resources\instance
{

    const IS_ALIVE = "eZ Publish is alive";

    const SOLR_CONFIG_FILE = '/etc/sysconfig/ezfind';

    const CLUSTER_CONFIG_FILE = '/etc/cluster/cluster.conf';

    const DRBD_CONFIG_FILE = '/etc/drbd.conf';

    const CRONTAB_FILE = '/etc/ezcluster/crontab.ec2-user';

    const HTTP_CONFIG_FILE = '/etc/httpd/sites/environment.conf';

    const HAPROXY_CONFIG_FILE = '/etc/haproxy/haproxy.cfg';

    const PHP_DEBUGGER_INI = '/usr/local/zend/etc/conf.d/debugger.ini';

    const EXPORTS_FILE = '/etc/exports';

    const SECURITY_GROUP = 'ezcluster';

    const PLACEMENT_GROUP = 'ezcluster';

    const STORAGE_TYPE_NONE = 0;

    const STORAGE_TYPE_SINGLE_MASTER = 1;

    const STORAGE_TYPE_MULTI_MASTER = 2;

    const STORAGE_TYPE_MASTER_SLAVE = 3;
    const MYSQL_DIR = "/var/lib/mysql";
    #const MYSQL_DIR = "/mnt/storage/mysql";
    
    const SOLR_MASTER_DIR = "/mnt/storage/ezfind";

    const SOLR_SLAVE_DIR = "/mnt/ephemeral/ezfind";

    const CRON_DEFAULT_MEMORY_LIMIT = "512M";
    // place in SDK
    public $dir;

    public static $config;

    private static $ec2;

    function __construct($id = null)
    {
        $this->dir = realpath(dirname(__FILE__) . '/../');
        if ($id === null) {
            $this->id = CloudInfo::getInstanceID();
        } else {
            $this->id = $id;
        }
        
        if (file_exists(CloudSDK::CONFIG_FILE)) {
            $str = file_get_contents(CloudSDK::CONFIG_FILE);
            $str = str_replace("xmlns=", "ns=", $str);
            self::$config = new \SimpleXMLElement($str);
        }
    }

    public function checkConfig()
    {
        // @TODO Check if config has only one solr master
    }

    public function configCDN()
    {
        // @TODO send update to all cluster nodes
        /*
         * <DistributionConfig xmlns="http://cloudfront.amazonaws.com/doc/2010-11-01/"> <CustomOrigin> <DNSName><-- Origin DNS name --></DNSName> <HTTPPort>80</HTTPPort> <OriginProtocolPolicy>http-only</OriginProtocolPolicy> </CustomOrigin> <CallerReference>0123012310230123</CallerReference> <CNAME><-- the CNAME of the CloudFront hosted site --></CNAME> <Comment></Comment> <Enabled>true</Enabled> <DefaultRootObject>index.php</DefaultRootObject> </DistributionConfig>
         */
    }

    public function csr()
    {
        echo "Creating certificate signing request \n";
        system('openssl genrsa 4096 > /tmp/private.pem');
        system('openssl req -new -key /tmp/private.pem -out /tmp/csr.pem');
        system('openssl x509 -req -days 3650 -in /tmp/csr.pem -signkey /tmp/private.pem -out /tmp/certificate.pem');
        echo "Private key:\n";
        echo file_get_contents('/tmp/private.pem');
        echo "Certificate signing request:\n";
        echo file_get_contents('/tmp/csr.pem');
        echo "Self-signed Certificate:\n";
        echo file_get_contents('/tmp/certificate.pem');
        unlink('/tmp/private.pem');
        unlink('/tmp/csr.pem');
        unlink('/tmp/certificate.pem');
    }

    public function init()
    {
        $certs = $this->getCertificates();
        if (is_array($certs)) {
            foreach ($certs as $cert) {
                try {
                    $cert = new certificate((string) $cert['name']);
                } catch (\Exception $e) {
                    try {
                        if (isset($cert->crt_chain)) {
                            $cert = certificate::create((string) $cert['name'], (string) $cert->crt, (string) $cert->key, (string) $cert->crt_chain);
                        } else {
                            $cert = certificate::create((string) $cert['name'], (string) $cert->crt, (string) $cert->key);
                        }
                    } catch (\Exception $e) {
                        $cert = null;
                    }
                }
            }
        }
        if ($this->getLB() and ! lb::exists($this->getLB())) {
            $lb = lb::create($this->getLB(), $this->getZones(), $cert);
        }
        
        $cdns = $this->getCDNs();
        if ($cdns) {
            foreach ($cdns as $cdn) {
                try {
                    new cdn($cdn);
                } catch (\Exception $e) {
                    $hosts = array();
                    foreach ($hosts as $host) {
                        $hosts[] = (string) $host;
                    }
                    cdn::create($cdn['origin'], $hosts);
                }
            }
        }
        if (self::getSitesStorageSize()) {
            $vol = volume::getByPath('/dev/xvdm');
            if ($vol === false) {
                echo "Creating sites volume";
                $vol = volume::create(self::getSitesStorageSize());
                $vol->attach('/dev/xvdm');
                system('echo "y" | mkfs -t ext4 /dev/xvdm');
            }
            system('mount -t ext4 -o defaults,noatime,auto /dev/xvdm /var/www/sites', $return);
        }
        if ($this->getIP()) {
            try {
                $ip = new ip($this->getIP());
                $ip->associate($this);
            } catch (\Exception $e) {
                echo $e;
            }
        }
    }

    public function setupDatabase()
    {
        $xp = "/aws/cluster[ @lb = '" . $this->getLB() . "' ]/environment/rds";
        $result = self::$config->xpath($xp);
        if (is_array($result) and count($result) > 0) {
            $masterdsn = (string) $result[0]['dsn'];
            $masterdetails = eZCluster/Resources/db::translateDSN($masterdsn);
        }
        
        $xp = "/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[role = 'database' and @name='" . $this->name() . "'] | /aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[role = 'dev' and @name='" . $this->name() . "']";
        
        $result = self::$config->xpath($xp);
        if (is_array($result) and count($result) > 0) {
            $masterdsn = 'mysql://root@localhost';
            $masterdetails = ezcDbFactory::parseDSN($masterdsn);
        }
        if (! isset($masterdetails)) {
            return false;
        }
        try {
            $dbmaster = ezcDbFactory::create($masterdetails);
        } catch (\Exception $e) {
            return false;
        }
        ezcDbInstance::set($dbmaster);
        
        if ($dbmaster) {
            $xp = "/aws/cluster[ @lb = '" . $this->getLB() . "' ]/environment/database";
            $result = self::$config->xpath($xp);
            foreach ($result as $db) {
                db::initDB((string) $db['dsn'], $dbmaster);
            }
            $xp = "/aws/cluster[ @lb = '" . $this->getLB() . "' ]/environment/storage";
            $result = self::$config->xpath($xp);
            foreach ($result as $db) {
                db::initDB((string) $db['dsn'], $dbmaster);
            }
        }
    }

    public function copyDataFromSource($name = null, $copydb = true, $copydata = true)
    {
        $environment = new Resources\environment($name);
        
        if (! isset($environment->environment->datasource)) {
            throw new \Exception("datasource not defeined for $name");
        }
        $connection = parse_url($environment->environment->datasource['connection']);
        $configuration = new Ssh\Configuration($connection['host']);
        $authentication = null;
        if (isset($connection['pass'])) {
            $authentication = new Ssh\Authentication\Password($connection['user'], $connection['pass']);
        } else {
            $authentication = new Ssh\Authentication\PublicKeyFile($connection['user'], '/root/.ssh/id_rsa.pub', '/root/.ssh/id_rsa');
        }
        $session = new Ssh\Session($configuration, $authentication);
        if ($copydb) {
            if (isset($environment->environment->datasource->database['dsn'])) {
                db::migrateDatabase((string) $environment->environment->datasource->database['dsn'], (string) $environment->environment->database["dsn"], $session);
            }
            if (isset($environment->environment->datasource->storage['dsn'])) {
                db::migrateDatabase((string) $environment->environment->datasource->storage['dsn'], (string) $environment->environment->storage["dsn"], $session);
            }
        }
        $storagepath = $environment->getStoragePath();
        $sourcestoragepath = $environment->getSourceStoragePath();
        
        $excludesRsync = '';
        $excludes = array(
            "cache/**",
            "autoload/**"
        );
        foreach ($excludes as $exclude) {
            $excludesRsync .= ' --exclude=' . escapeshellarg($exclude) . ' ';
        }
        if ($storagepath and $sourcestoragepath and $copydata) {
            $command = 'rsync -avztr --no-super --no-owner --no-group --delete-excluded --rsh="ssh -i ~/.ssh/id_rsa -p 22" ' . $excludesRsync . ' ' . "{$connection['user']}@{$connection['host']}:{$sourcestoragepath}/ {$storagepath}/";
            system($command);
        }
        if (file_exists($environment->dir . "/" . "bin/php/ezcache.php")) {
            $environment->run("php bin/php/ezcache.php --clear-all");
        } elseif (file_exists($environment->dir . "/" . "ezpublish/console")) {
            $environment->run("php ezpublish/console --env=prod cache:clear");
        }
    }



    public function getServices($roles)
    {
        $services = $roles;
        if (in_array('storage', $roles)) {
            array_push($services, 'solr');
        }
        
        if (in_array('dev', $roles)) {
            array_push($services, 'web', 'solr', 'database');
        }
        if (in_array('solr-slave', $services) and in_array('solr', $services)) {
            $key = array_search('solr-slave', $services);
            unset($services[$key]);
            
            // hrow new Exception( "Can`t use role 'solr-slave', if solr master is present" );
        }
        return array_unique($services);
    }

    public function roles()
    {
        $roles = array();
        if (self::$config) {
            $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[@name='" . $this->name() . "']/role");
            if (is_array($result)) {
                foreach ($result as $key => $role) {
                    $roles[$key] = strtolower(trim((string) $role));
                }
                return $roles;
            }
        }
        $response = CloudSDK::factory()->describe_tags(array(
            'Filter' => array(
                array(
                    'Name' => 'resource-id',
                    'Value' => $this->id
                ),
                array(
                    'Name' => 'key',
                    'Value' => 'ROLES'
                )
            )
        ));
        if (! $response->isOK()) {
            throw new eZCluster\Exceptions\xrowAWSException($response);
        }
        $roles = (string) $response->body->tagSet->item->value;
        $roles = explode(',', $roles);
        foreach ($roles as $key => $role) {
            $roles[$key] = strtolower(trim($role));
        }
        return $roles;
    }

    public function getCertificates()
    {
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/certificate");
        return $result;
    }

    public function getCDNs()
    {
        $result = self::$config->xpath("/aws/cdn");
        return $result;
    }

    public function getStorageSize()
    {
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[@name='" . $this->name() . "']/parent::*");
        if ($result[0]['storage']) {
            return (int) $result[0]['storage'];
        } else {
            return false;
        }
    }

    public function getRAMDiskSize()
    {
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[@name='" . $this->name() . "']/parent::*");
        if ($result[0]['database-ram-disk']) {
            return (string) $result[0]['database-ram-disk'];
        } else {
            return false;
        }
    }

    public function getSitesStorageSize()
    {
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[@name='" . $this->name() . "']/parent::*");
        if ($result[0]['sites_storage']) {
            return (int) $result[0]['sites_storage'];
        } else {
            return false;
        }
    }

    public function getZones()
    {
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[@name='" . $this->name() . "']/parent::*");
        if (isset($result[0]['zones'])) {
            return explode(",", (string) $result[0]['zones']);
        } else {
            return array(
                CloudSDK::getDefaultRegion()
            );
        }
    }

    public function getLB()
    {
        $result = self::$config->xpath("/aws/cluster/instance[@name='" . $this->name() . "']/parent::*");
        if (isset($result[0]['lb'])) {
            return (string) $result[0]['lb'];
        } else {
            return false;
        }
    }

    public function getInstances()
    {
        $instances = array();
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance");
        
        if (isset($result)) {
            $instances = array();
            foreach ($result as $node) {
                $instance = instance::byName((string) $node['name']);
                if ($instance->describe()->instanceState->name == 'running') {
                    $instances[] = $instance;
                }
            }
        }
        return $instances;
    }

    public function sync()
    {
        foreach (self::getInstances() as $instance) {
            if ($instance->id != $this->id) {
                echo "rsync root@" . $instance->ip() . ":/tmp/test.txt /tmp/text.txt\n";
                system();
            }
        }
    }

    public function getSolrMasterHost()
    {
        $result = CloudSDK::$config->xpath("/aws/cluster[ @lb = '" . Resources\lb::current() . "' ]/instance[role = 'solr']");
        if (isset($result)) {
            $instances = array();
            foreach ($result as $node) {
                $instance = instance::byName((string) $node['name']);
                if (isset($instance->describe()->instanceState) and $instance->describe()->instanceState->name == 'running') {
                    $instances[] = $instance;
                }
            }
            
            foreach ($instances as $instance) {
                if ((string) $this->describe()->placement->availabilityZone == (string) $instance->describe()->placement->availabilityZone) {
                    return (string) $instance->describe()->privateIpAddress;
                }
            }
            if (isset($instances[0])) {
                return (string) $instances[0]->describe()->privateIpAddress;
            }
            return false;
        }
    }

    public function getStorageHost()
    {
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[role = 'storage']");
        if (isset($result)) {
            $instances = array();
            foreach ($result as $node) {
                $instance = instance::byName((string) $node['name']);
                if ($instance instanceof instance and $instance->describe()->instanceState->name == 'running') {
                    $instances[] = $instance;
                }
            }
            
            foreach ($instances as $instance) {
                if ((string) $this->describe()->placement->availabilityZone == (string) $instance->describe()->placement->availabilityZone) {
                    return (string) $instance->describe()->privateIpAddress;
                }
            }
            if (isset($instances[0])) {
                return (string) $instances[0]->describe()->privateIpAddress;
            }
            return false;
        }
    }

    public function getDatabaseSlave()
    {
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[@name='" . $this->name() . "']");
        return (string) $result[0]['database-slave'];
    }

    public function getIP()
    {
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[@name='" . $this->name() . "']");
        return (string) $result[0]['bind'];
    }

    public function getActivePeriods()
    {
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[@name='" . $this->name() . "']");
        if (isset($result[0]['active'])) {
            return (string) $result[0]['active'];
        } else {
            return null;
        }
    }

    public function isLoadBalancerMember()
    {
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[@name='" . $this->name() . "']");
        if (isset($result[0]['lb']) and (string) $result[0]['lb'] == 'disabled') {
            return false;
        } else {
            return true;
        }
    }

    public function getStorageVolumeID()
    {
        $response = CloudSDK::factory()->describe_volumes();
        foreach ($response->body->volumeSet->item as $volume) {
            if (isset($volume->tagSet)) {
                foreach ($volume->tagSet->item as $tag) {
                    
                    if ((string) $tag->key == "Name" and (string) $tag->value == $this->name()) {
                        return (string) $volume->volumeId;
                    }
                }
            }
        }
    }

    public function getStorageType()
    {
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[role = 'storage-slave']");
        $hasSlave = false;
        if (isset($result)) {
            if (count($result) == 1) {
                $hasSlave = true;
            }
        }
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[role = 'storage']");
        
        if (isset($result)) {
            if (count($result) == 2) {
                return self::STORAGE_TYPE_MULTI_MASTER;
            }
            if (count($result) == 1) {
                if ($hasSlave) {
                    return self::STORAGE_TYPE_MASTER_SLAVE;
                } else {
                    return self::STORAGE_TYPE_SINGLE_MASTER;
                }
            } else {
                return self::STORAGE_TYPE_NONE;
            }
        }
        return self::STORAGE_TYPE_NONE;
    }

    public function setupHTTP()
    {
        $xp = "/aws/cluster[ @lb = '" . $this->getLB() . "' ]/environment";
        $result = self::$config->xpath($xp);
        foreach ($result as $env) {
            $vhost = new stdClass();
            $dir = CloudSDK::SITES_ROOT . '/' . (string) $env['name'];
            if (isset($env['docroot'])) {
                $dir .= '/' . (string) $env['docroot'];
            }
            
            $vhost->dir = $dir;
            
            if (! file_exists($dir)) {
                mkdir($dir, 0777, true);
                chown($dir, CloudSDK::USER);
                chgrp($dir, CloudSDK::USER);
            }
            $vhost->name = (string) $env['name'];
            $vhost->hosts = array();
            foreach ($env->xpath('hostname') as $host) {
                $vhost->hosts[] = (string) $host;
            }
            $vhosts[] = $vhost;
        }
        $t = new ezcTemplate();
        $t->configuration = CloudSDK::$ezcTemplateConfiguration;
        $t->send->vhosts = $vhosts;
        
        // Process the template and print it.
        if (! is_dir(dirname(self::HTTP_CONFIG_FILE))) {
            mkdir(dirname(self::HTTP_CONFIG_FILE), 0755, true);
        }
        file_put_contents(self::HTTP_CONFIG_FILE, $t->process('vhost.ezt'));
        if (is_link("/var/www/html/index.php")) {
            unlink("/var/www/html/index.php");
        }
        symlink("/usr/share/ezcluster/src/probe/index.php", "/var/www/html/index.php");
        file_put_contents(self::HAPROXY_CONFIG_FILE, $t->process('haproxy.ezt'));
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/certificate[ @name = 'ssl' ]");
        if (isset($result[0])) {
            file_put_contents("/etc/ssl/certs/haproxy.pem", (string) $result[0]);
        } else {
            $str = <<<EOD
-----BEGIN CERTIFICATE-----
MIIBrzCCARgCCQCfMsCGwq31yzANBgkqhkiG9w0BAQUFADAcMRowGAYDVQQDExF3
d3cuZXhjZWxpYW5jZS5mcjAeFw0xMjA5MDQwODU3MzNaFw0xMzA5MDQwODU3MzNa
MBwxGjAYBgNVBAMTEXd3dy5leGNlbGlhbmNlLmZyMIGfMA0GCSqGSIb3DQEBAQUA
A4GNADCBiQKBgQDFxSTUwX5RD4AL2Ya5t5PAaNjcwPa3Km40uaPKSHlU8AMydxC1
wB4L0k3Ms9uh98R+kIJS+TxdfDaYxk/GdDYI1CMm4TM+BLHGAVA2DeNf2hBhBRKb
TAgxCxXwORJQSB/B+1r0/ZiQ2ig5Jzr8xGHz+tBsHYZ+t+RmjZPQFjnlewIDAQAB
MA0GCSqGSIb3DQEBBQUAA4GBABqVuloGWHReSGLY1yAs20uhJ3j/9SvtoueyFBag
z5jX4BNO/4yhpKEpCGmzYtjr7us3v/s0mKoIVvAgah778rCZW3kF1Y6xR6TYqZna
1ryKB50/MJg9PC4LNL+sAu+WSslOf6+6Ru5N3JjhIZST8edJsGDi6/5HTKoqyvkp
wOMn
-----END CERTIFICATE-----
-----BEGIN RSA PRIVATE KEY-----
MIICXgIBAAKBgQDFxSTUwX5RD4AL2Ya5t5PAaNjcwPa3Km40uaPKSHlU8AMydxC1
wB4L0k3Ms9uh98R+kIJS+TxdfDaYxk/GdDYI1CMm4TM+BLHGAVA2DeNf2hBhBRKb
TAgxCxXwORJQSB/B+1r0/ZiQ2ig5Jzr8xGHz+tBsHYZ+t+RmjZPQFjnlewIDAQAB
AoGBALUeVhuuVLOB4X94qGSe1eZpXunUol2esy0AMhtIAi4iXJsz5Y69sgabg/qL
YQJVOZO7Xk8EyB7JaerB+z9BIFWbZwS9HirqR/sKjjbhu/rAQDgjVWw2Y9sjPhEr
CEAvqmQskT4mY+RW4qz2k8pe4HKq8NAFwbe8iNP7AySP3K4BAkEA4ZPBagtlJzrU
7Tw4BvQJhBmvNYEFviMScipHBlpwzfW+79xvZhTxtsSBHAM9KLbqO33VmJ3C/L/t
xukW8SO6ewJBAOBxU0TfS0EzcRQJ4sn78G6hTjjLwJM2q4xuSwLQDVaWwtXDI6HE
jb7HePaGBGnOrlXxEOFQZCVdDaLhX0zcEQECQQDHcvc+phioGRKPOAFp1HhdfsA2
FIBZX3U90DfAXFMFKFXMiyFMJxSZPyHQ/OQkjaaJN3eWW1c+Vw0MJKgOSkLlAkEA
h8xpqoFEgkXCxHIa00VpuzZEIt89PJVWhJhzMFd7yolbh4UTeRx4+xasHNUHtJFG
MF+0a+99OJIt3wBn7hQ1AQJACScT3p6zJ4llm59xTPeOYpSXyllR4GMilsGIRNzT
RGYxcvqR775RkAgE+5DHmAkswX7TBaxcO6+C1+LJEwFRxw==
-----END RSA PRIVATE KEY-----
EOD;
            file_put_contents("/etc/ssl/certs/haproxy.pem", $str);
        }
    }

    public function setupMounts()
    {
        $xp = "/aws/cluster[ @lb = '" . $this->getLB() . "' ]/environment";
        ClusterTools::mkdir("/nfs", CloudSDK::USER, 0777);
        $result = self::$config->xpath($xp);
        file_put_contents("/etc/auto.master", "/nfs    /etc/auto.ezcluster\n
+auto.master\n");
        chmod("/etc/auto.master", 0755);
        chown("/etc/auto.master", CloudSDK::USER);
        chgrp("/etc/auto.master", CloudSDK::USER);
        $mounts = array();
        if (is_array($result)) {
            foreach ($result as $environment) {
                $name = (string) $environment['name'];
                if (isset($environment->storage['mount'])) {
                    $mount = (string) $environment->storage['mount'];
                    if (strpos($mount, "nfs://") === 0) {
                        ClusterTools::mkdir("/nfs/{$name}", CloudSDK::USER, 0777);
                        $parts = parse_url($mount);
                        system("mount -t nfs4 -o rw {$parts['host']}:{$parts['path']} /nfs/{$name}");
                        $mounts[] = "{$name} -fstype=nfs,rw {$parts['host']}:{$parts['path']}";
                    }
                }
            }
        }
        // idn`t get it working
        // ile_put_contents( "/etc/auto.ezcluster", join( $mounts, "\n" ) );
        // hmod( "/etc/auto.ezcluster", 0755 );
        // hown( "/etc/auto.ezcluster", CloudSDK::USER );
        // hgrp( "/etc/auto.ezcluster", CloudSDK::USER );
        // ystem("/etc/init.d/autofs start");
    }

    public function setupCrons()
    {
        if (! self::$config) {
            return false;
        }
        $crondata = "";
        $crondata = "1 * * * * rm -Rf /var/www/sites/*/ezpublish/cache/dev/profiler/\n";
        
        $xp = "/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[role = 'admin' and @name='" . $this->name() . "']";
        
        $result = self::$config->xpath($xp);
        
        if (is_array($result) and count($result) > 0) {
            $xp = "/aws/cluster[ @lb = '" . $this->getLB() . "' ]/environment[cron]";
            $result = self::$config->xpath($xp);
            foreach ($result as $env) {
                $path = CloudSDK::SITES_ROOT . '/' . (string) $env['name'];
                
                foreach ($env->xpath('cron') as $cron) {
                    if ((string) $cron['timing'] and (string) $cron['group']) {
                        $memory_limit = (isset($cron['memory_limit'])) ? (string) $cron['memory_limit'] : self::CRON_DEFAULT_MEMORY_LIMIT;
                        $crondata .= (string) $cron['timing'] . " cd $path && php -d memory_limit=$memory_limit ezpublish/console ezpublish:legacy:script runcronjobs.php " . (string) $cron['group'] . " >> $path/cron.log 2>&1\n";
                    }
                    if ((string) $cron['timing'] and (string) $cron['cmd']) {
                        $crondata .= (string) $cron['timing'] . " cd $path && " . (string) $cron['cmd'] . " >> $path/cron.log 2>&1\n";
                    }
                }
            }
        }
        $xp = "/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[@name='" . $this->name() . "']/cron";
        
        $result = self::$config->xpath($xp);
        
        if (is_array($result) and count($result) > 0) {
            foreach ($result as $cron) {
                if ((string) $cron['timing'] and (string) $cron['cmd']) {
                    $crondata .= (string) $cron['timing'] . " cd " . self::SITES_ROOT . " && " . (string) $cron['cmd'] . "\n";
                    // User ec2-user has Permissions to the log /var/log/ezcluster/cron.log, so the crons will not run.
                    // . " >> /var/log/ezcluster/cron.log 2>&1\n";
                }
            }
        }
        if (isset($crondata)) {
            echo "Setup crontab " . self::CRONTAB_FILE . " for " . CloudSDK::USER . "\n";
            $vars = "PATH=/usr/local/zend/bin:/usr/local/bin:/bin:/usr/bin\nLD_LIBRARY_PATH=/usr/local/zend/lib:/lib:/usr/lib\n\n";
            file_put_contents(self::CRONTAB_FILE, $vars . $crondata);
            $cmd = "crontab -u " . CloudSDK::USER . " " . self::CRONTAB_FILE;
            system($cmd);
        }
    }

    public function startServices()
    {
        $roles = $this->roles();
        echo "Assigned Roles " . join($roles, ',') . "\n";
        $services = self::getServices($roles);
        echo "Starting " . join($services, ',') . "\n";
        
        $this->init();
        $this->setupMounts();
        $dir = "/mnt/storage";
        if (! file_exists($dir)) {
            mkdir($dir, 0777, true);
            chown($dir, "root");
            chgrp($dir, "root");
        }
        if (in_array('storage', $services) or in_array('storage-slave', $services)) {
            if (! $this->getStorageSize()) {
                $ok = true;
            } else {
                if ($this->getStorageVolumeID()) {
                    $vol = new volume($this->getStorageVolumeID());
                } else {
                    $vol = volume::create($this->getStorageSize(), $this->name());
                }
                $info = $vol->get('attachmentSet');
                if ($vol->get('status') == 'available') {
                    $vol->attach('/dev/xvdl');
                } elseif ($vol->get('status') == 'in-use' and $info['instanceId'] != $this->id) {
                    $test = new instance($info['instanceId']);
                    if ((string) $test->describe()->instanceState->name != 'running') {
                        $vol->detach();
                        $vol->attach('/dev/xvdl');
                    } else {
                        throw new \Exception("Can`t mount teast");
                    }
                } elseif ($vol->get('status') == 'in-use' and $info['instanceId'] == $this->id) {
                    // do nothing
                } else {
                    throw new \Exception("Can`t mount. Volume not in a ready state");
                }
                
                $result = self::$config->xpath("/aws/cluster[ @lb = '" . $this->getLB() . "' ]/instance[role = 'storage' or role = 'storage-slave']");
                
                if (isset($result)) {
                    
                    $t = new ezcTemplate();
                    $t->configuration = CloudSDK::$ezcTemplateConfiguration;
                    $response = CloudSDK::factory()->describe_instances();
                    
                    foreach ($response->body->reservationSet->item as $set) {
                        if ((string) $set->instancesSet->item->instanceState->name == 'running') {
                            $instances[] = new ClusterNode((string) $set->instancesSet->item->instanceId);
                        }
                    }
                    $i = 1;
                    foreach ($instances as $key => $instance) {
                        if (in_array('storage', $instance->roles()) or in_array('storage-slave', $instance->roles())) {
                            $backend = new stdClass();
                            $backend->name = $instance->getTag('Name');
                            $dns = (string) $instance->describe()->privateDnsName;
                            $backend->ip = (string) $instance->describe()->privateIpAddress;
                            $list = explode(".", $dns);
                            $backend->host = $list[0];
                            $backend->fqdn = $dns;
                            $backends[$i] = $backend;
                            $i ++;
                        }
                    }
                }
                $ok = true;
                $dev = system('blkid /dev/xvdl', $return);
                
                if (strpos($type, 'TYPE="ext4"') === false and count($backends) == 1 and self::getStorageTYpe() == self::STORAGE_TYPE_MULTI_MASTER) {
                    echo "Will not start till I see one more node. Need the private IP. Can`t guess.\n";
                    echo "Try restart later.\n";
                }
                
                if (self::getStorageType() == self::STORAGE_TYPE_MULTI_MASTER and (count($backends) == 2 or $dev = "/dev/xvdl: block special (8/80)")) {
                    echo "Entering Multi Master Setup\n";
                    $t->send->backends = $backends;
                    $t->send->multimaster = true;
                    $t->send->master = true;
                    $t->send->slave = false;
                    // Process the template and print it.
                    if (! is_dir(dirname(self::CLUSTER_CONFIG_FILE))) {
                        mkdir(dirname(self::CLUSTER_CONFIG_FILE), 0755, true);
                    }
                    file_put_contents(self::CLUSTER_CONFIG_FILE, $t->process('cluster.ezt'));
                    
                    file_put_contents(self::DRBD_CONFIG_FILE, $t->process('drbd.ezt'));
                    system("/sbin/drbdmeta 1 v08 /dev/xvdl internal check-resize", $return);
                    if ($return == 255) {
                        echo "drbdmeta return 255\n";
                        system("drbdadm create-md storage");
                        $createdMeta = true;
                        
                        // @TODO figure out a a way to define primary
                        // drbdadm -- --overwrite-data-of-peer primary storage
                    }
                    
                    system('systemctl start drbd', $return);
                    
                    // @TODO http://www.drbd.org/users-guide/s-gfs-create.html
                    // system( 'mount -t gfs2 -o defaults,noexec,noatime,noauto /dev/drbd/by-res/storage /mnt/storage', $return );
                    if ($return == 32 && isset($createdMeta) and $createdMeta == true) {
                        // system( "drbdadm -- --overwrite-data-of-peer primary storage" );
                        // system( 'mkfs -t gfs2 -p lock_dlm -t ezcluster:storage -j 2 /dev/drbd/by-res/storage' );
                        // system( 'mount -t gfs2 -o defaults,noexec,noatime,noauto /dev/drbd/by-res/storage /mnt/storage', $return );
                        if ($return != 0) {
                            throw new \Exception("Can`t mount");
                        }
                    }
                    system("chmod 777 /mnt/storage");
                    system('cat /proc/drbd');
                }
                if (self::getStorageType() == self::STORAGE_TYPE_MASTER_SLAVE and (count($backends) == 2 or $dev = "/dev/xvdl: block special (8/80)")) {
                    echo "Entering Master Slave Setup\n";
                    $t->send->backends = $backends;
                    if (in_array('storage', $services)) {
                        $t->send->master = true;
                        $t->send->slave = false;
                        $t->send->multimaster = false;
                    } elseif (in_array('storage-slave', $services)) {
                        $t->send->master = false;
                        $t->send->slave = true;
                        $t->send->multimaster = false;
                    }
                    
                    file_put_contents(self::DRBD_CONFIG_FILE, $t->process('drbd.ezt'));
                    system("/sbin/drbdmeta 1 v08 /dev/xvdl internal check-resize", $return);
                    if ($return == 255) {
                        echo "drbdmeta return 255\n";
                        system("drbdadm create-md storage");
                        $createdMeta = true;
                    }
                    
                    system('systemctl start drbd', $return);
                    system('mount -t ext4 -o defaults,noexec,noatime,noauto /dev/drbd/by-res/storage /mnt/storage', $return);
                    if ($return == 32 && isset($createdMeta) and $createdMeta == true) {
                        system('echo "y" | mkfs -t ext4 /dev/drbd/by-res/storage');
                        system('mount -t ext4 -o defaults,noexec,noatime,noauto /dev/drbd/by-res/storage /mnt/storage', $return);
                        if ($return != 0) {
                            throw new \Exception("Can`t mount");
                        }
                    }
                    system("chmod 777 /mnt/storage");
                    system('cat /proc/drbd');
                } elseif (self::getStorageTYpe() == self::STORAGE_TYPE_SINGLE_MASTER and count($backends) == 1) {
                    system('cat /proc/mounts | grep xvdl', $return);
                    if ($return != 0) {
                        system('mount -t ext4 -o defaults,noexec,noatime,noauto /dev/xvdl /mnt/storage', $return);
                        // ount: wrong fs type, bad option, bad superblock on /dev/xvdl,
                        // ight be cluster filesystem
                        if ($return == 32) {
                            $type = system('blkid /dev/xvdl', $return);
                            if ($return == 2) {
                                system('echo "y" | mkfs -t ext4 /dev/xvdl');
                            }
                            $type = system('blkid /dev/xvdl', $return);
                            // test file system type on disk
                            if (strpos($type, 'TYPE="ext4"') === false) {
                                throw new \Exception("Disk doesn`t use ext4 filesystem");
                            }
                            system('mount -t ext4 -o defaults,noexec,noatime,noauto /dev/xvdl /mnt/storage', $return);
                            if ($return != 0) {
                                throw new \Exception("Can`t mount");
                            }
                        }
                        system("chmod 777 /mnt/storage");
                    }
                }
            }
            if ($ok === true) {
                file_put_contents(self::EXPORTS_FILE, "/mnt/storage *(rw,sync,no_acl,all_squash,anonuid=48,anongid=48)\n");
                system('systemctl start nfslock');
                system('systemctl start nfs');
                @mkdir('/mnt/nas', 0755);
                system('mount -t nfs -o rw ' . $this->getStorageHost() . ':/mnt/storage /mnt/nas');
            }
        }
        
        if (in_array('solr', $services) or in_array('solr-slave', $services)) {
            $sorconf = "";
            if (in_array('solr', $services)) {
                $dir = self::SOLR_MASTER_DIR;
                ClusterTools::mkdir($dir, 'ezfind', 0755);
                $sorconf = "DATA_DIR=$dir\n";
                $cores = array( "ezp-default" ); 
                $list = Resources\environment::getList();
                foreach ( $list as $env )
                {
                    $cores[] = $env->name;
                }
                $sorconf = "CORES=\"".join(" ",$cores)."\"\n";
                $sorconf .= "PARAMETERS=\"-Denable.master=true -Denable.slave=false\"\n";
            } else {
                $dir = self::SOLR_SLAVE_DIR;
                ClusterTools::mkdir($dir, 'ezfind', 0755);
                $sorconf = "DATA_DIR=$dir\n";
                $master = self::getSolrMasterHost();
                if ($master === false) {
                    echo "SOLR MASTER not present. Can`t start SOLR SLAVE.\n";
                    $url = "";
                } else {
                    $url = "http://" . $master . ":8983/solr/replication";
                }
                
                $sorconf .= "PARAMETERS=\"-Denable.master=false -Denable.slave=true -Dsolr.master.url=$url\"\n";
            }
            
            file_put_contents(self::SOLR_CONFIG_FILE, $sorconf);
            system('/etc/init.d/ezfind-solr start');
        }
        
        if (in_array('database', $services)) {
            $t = new ezcTemplate();
            $t->configuration = CloudSDK::$ezcTemplateConfiguration;
            if (! is_dir( self::MYSQL_DIR ) ) {
                die("NOO".self::MYSQL_DIR);
                mkdir( self::MYSQL_DIR, 0755);
                chown( self::MYSQL_DIR, 'mysql');
                chgrp( self::MYSQL_DIR, 'mysql');
                system("/usr/bin/sudo /usr/bin/mysql_install_db --datadir=".self::MYSQL_DIR."--basedir=/usr --user=mysql --defaults-file=/etc/my.cnf");
                $pass = substr(rtrim(file_get_contents("/root/.mysql_secret")), - 8);
                echo "MySql password: $pass\n";
                unlink("/root/.mysql_secret");
                system("/usr/bin/sudo /usr/bin/mysql -e\"GRANT ALL PRIVILEGES ON *.* TO 'ec2-user'@'localhost' WITH GRANT OPTION\"");
            }
            $t->send->datadir = self::MYSQL_DIR;

            $t->send->settings = db::getDatabaseSettings(false);
            file_put_contents('/etc/my.cnf.d/ezcluster.cnf', $t->process('my.ezt'));

            // Ram Disk
            if (self::getRAMDiskSize()) {
                if (! is_dir('/var/mysql.tmp')) {
                    mkdir('/var/mysql.tmp', 0755);
                    chown('/var/mysql.tmp', 'mysql');
                    chgrp('/var/mysql.tmp', 'mysql');
                    system("mount -t tmpfs -o size=" . (int) self::getRAMDiskSize() . "M,mode=0775,noatime,nodiratime tmpfs /var/mysql.tmp");
                }
            }
            system('systemctl start mariadb');

            if (isset($pass)) {
                system("mysql -e\"SET PASSWORD FOR 'root'@'localhost' = PASSWORD('');\" -u root -p$pass");
            }
        }
        $this->setupDatabase();
        
        if ($this->getStorageHost()) {
            $dir = "/mnt/nas";
            if (! file_exists($dir)) {
                mkdir($dir, 0777, true);
                chown($dir, "root");
                chgrp($dir, "root");
            }
            echo 'mount -t nfs -o rw ' . $this->getStorageHost() . ':/mnt/storage /mnt/nas' . "\n";
            system('mount -t nfs -o rw ' . $this->getStorageHost() . ':/mnt/storage /mnt/nas');
        } else {
            echo "No storage detected. Won`t mount.\n";
        }

        $this->setupCrons();
        if (in_array('web', $services)) {
            $this->setupHTTP();
            
            if (! in_array('dev', $services)) {
                if (file_exists(self::PHP_DEBUGGER_INI)) {
                    unlink(self::PHP_DEBUGGER_INI);
                }
            } else {
                
                $debug = <<<'EOD'
; register the extension to be loaded by Zend Extension Manager
zend_extension_manager.dir.debugger=/usr/local/zend/lib/debugger

; Specifies the hosts that are allowed to connect (hostmask list) with Zend Debugger when running a remote debug session with Zend Studio
zend_debugger.allow_hosts=127.0.0.1/32,10.0.0.0/8,192.168.0.0/16,172.16.0.0/12

; Specifies the hosts that are not allowed to connect (hostmask list) with the Zend Debugger when running a remote debug session with Zend Studio
zend_debugger.deny_hosts=

; A list of hosts (hostmask list) that can use the machine on which Zend Server is installed to create a communication tunnel for remote debgging with Zend Studio. This is done to solve firewall connectivity limitations
zend_debugger.allow_tunnel=

; The user ID of the httpd process that runs the Zend Debugger (only for tunneling)
zend_debugger.httpd_uid=-1

; A range of ports that the communication tunnel can use. This defines the minimum value for the range
zend_debugger.tunnel_min_port=1024

; A range of ports that the communication tunnel can use. This defines the maximum value for the range
zend_debugger.tunnel_max_port=65535

; Define whether to expose the presence of the Zend Debugger to remote clients
zend_debugger.expose_remotely=1

; The Debugger's timeout period (in seconds) to wait for a response from the client (Zend Studio) (units: seconds)
zend_debugger.passive_mode_timeout=20

; Enables fast time sampling which is dependent on CPU cycles and frequency, otherwise, the directive uses operating system timing (which may be less accurate)
zend_debugger.use_fast_timestamp=1

; Enable code-coverage feature, should only be true on local debugger
zend_debugger.enable_coverage=0
EOD;
                file_put_contents(self::PHP_DEBUGGER_INI, $debug);
            }
            system('systemctl start httpd');
            system('systemctl start varnish');
            usleep(1000000);
            system('systemctl start varnishncsa');
            system('systemctl start haproxy');
            if (! in_array('dev', $services) and $this->isLoadBalancerMember()) {
                try {
                    $lb = new lb($this->getLB());
                    $lb->register($this);
                } catch (\Exception $e) {
                    echo (string) $e->getMessage() . "\n";
                }
            }
        }
    }

    public function checkInstance()
    {
        if ($this instanceof ClusterNode and in_array('web', $this->roles())) {
            $str = @file_get_contents('http://' . $this->ip() . '/ezinfo/is_alive');
            if ($str != self::IS_ALIVE) {
                return false;
            }
        }
        return true;
    }

    public function stopServices()
    {
        $roles = $this->roles();
        echo "Assigned Roles " . join($roles, ',') . "\n";
        system('crontab -u ec2-user -r');
        $services = self::getServices($roles);
        
        system('umount -f /mnt/nas');
        
        if (in_array('web', $services)) {
            if (! in_array('dev', $services) and $this->getLB() and $this->isLoadBalancerMember() and lb::exists($this->getLB())) {
                $lb = new lb($this->getLB());
                $lb->deregister($this);
            }
            system('systemctl stop haproxy');
            system('systemctl stop varnishncsa');
            system('systemctl stop varnish');
            system('systemctl stop httpd');
        }
        system('systemctl stop autofs');
        if (in_array('solr', $services) or in_array('solr-slave', $services)) {
            
            system('/etc/init.d/ezfind-solr stop');
            if (file_exists(self::SOLR_CONFIG_FILE)) {
                unlink(self::SOLR_CONFIG_FILE);
            }
        }
        if (in_array('database', $services)) {
            system('systemctl stop mariadb');
        }
        if (in_array('storage', $services) or in_array('storage-slave', $services)) {
            // will kill myself
            // ystem( 'killall php' );
            system('systemctl stop nfs');
            system('systemctl stop nfslock');
            system('systemctl stop drbd');
        /**
         * try
         * {
         * $vol = new volume( $this->getStorageVolumeID() );
         * echo "Detaching volume $vol.\n";
         * if ( $vol->get( 'status' ) == 'in-use' )
         * {
         * $vol->detach();
         * }
         * }
         * catch ( \ Exception $e )
         * {
         * }
         */
        }
    }
}
