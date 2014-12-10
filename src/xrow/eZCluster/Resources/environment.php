<?php
namespace xrow\eZCluster\Resources;

use xrow\eZCluster;
use \ezcTemplateConfiguration;
use \ezcTemplateNoContext;
use \ezcTemplate;

class environment
{

    public $environment = null;

    public $docroot = null;

    public $dir = null;

    public $name = null;

    function __construct($name)
    {
        if ($name) {
            $xp = "/aws/cluster[ @lb = '" . lb::current() . "' ]/environment[ @name = '" . $name . "' ]";
            $result = eZCluster\CloudSDK::$config->xpath($xp);
            if ($result === array()) {
                throw new \Exception("$name not known in configuration");
            }
            $this->environment = $result[0];
            $this->name = (string) $this->environment['name'];
            $this->dir = eZCluster\CloudSDK::SITES_ROOT . "/" . $this->name;
            if (isset($this->environment['docroot'])) {
                $this->docroot = $this->dir . "/" . (string) $this->environment['docroot'];
            } else {
                $this->docroot = $this->dir;
            }
        } else {
            throw new \Exception("Name not given. Please define a enviroment.");
        }
    }

    public function getStoragePath()
    {
        $name = (string) $this->environment['name'];
        if ($this->environment->storage) {
            $mount = (string) $this->environment->storage['mount'];
            if (strpos($mount, "nfs://") === 0) {
                $dir = "/nfs/{$name}";
                if (isset($this->environment->storage['dir'])) {
                    $dir .= "/" . (string) $this->environment->storage['dir'];
                }
            } elseif (strpos($mount, "/") === 0) {
                $dir = $mount;
            } else {
                $dir = "/mnt/storage/{$name}";
            }
        } else {
            $dir = "/mnt/storage/{$name}";
        }
        return $dir;
    }

    public function clean($name = null)
    {
        $this->run('rm -Rf ' . $this->dir . '/*');
        $this->run('rm -Rf ' . $this->dir . '/.[^.]*');
    }

    public function getSourceStoragePath()
    {
        $name = (string) $this->environment['name'];
        $dir = false;
        if ($this->environment->datasource->storage) {
            $mount = (string) $this->environment->datasource->storage['mount'];
            if (strpos($mount, "nfs://") === 0) {
                $dir = "/nfs/{$name}";
                if (isset($this->environment->datasource->storage['dir'])) {
                    $dir .= "/" . (string) $this->environment->datasource->storage['dir'];
                }
            } elseif (strpos($mount, "/") === 0) {
                $dir = $mount;
            }
        }
        return $dir;
    }

    public function setup()
    {
        $t = new ezcTemplate();
        $t->configuration = eZCluster\CloudSDK::$ezcTemplateConfiguration;
        $t->send->access_key = (string) eZCluster\CloudSDK::$config['access_key'];
        $t->send->secret_key = (string) eZCluster\CloudSDK::$config['secret_key'];
        
        file_put_contents("/home/ec2-user/.s3cfg", $t->process('s3cfg.ezt'));
        file_put_contents("/root/.s3cfg", $t->process('s3cfg.ezt'));
        
        if (! file_exists($this->dir)) {
            eZCluster\ClusterTools::mkdir($this->dir, eZCluster\CloudSDK::USER, 0777);
        }
        chmod($this->dir, 0777);
        $bootstrap = $this->environment->xpath("bootstrap");
        if (! empty($bootstrap)) {
            $result = $this->environment->xpath("storage");
            foreach ($result as $db) {
                $dfsdsn = (string) $db['dsn'];
                $dfsdetails = db::translateDSN($dfsdsn);
                $dfsdetails['type'] = (string) $db['type'];
                $dfsdetails['mount'] = (string) $db['mount'];
                if (strpos($dfsdetails['mount'], "nfs://") === 0) {
                    $dfsdetails['mount'] = "/nfs/{$this->name}";
                    if (isset($db['dir'])) {
                        $dfsdetails['mount'] .= "/" . (string) $db['dir'];
                    }
                    if (! is_dir($dfsdetails['mount'])) {
                        eZCluster\ClusterTools::mkdir($dfsdetails['mount'], eZCluster\CloudSDK::USER, 0777);
                    }
                }
                $dfsdetails['memcached'] = (string) $db['memcached'];
                $dfsdetails['bucket'] = (string) $db['bucket'];
            }
        }
        if (! empty($bootstrap) and ! file_exists($this->docroot . "/index.php") and ! file_exists($this->docroot . "/app.php")) {
            $result = $this->environment->xpath("rds");
            foreach ($result as $db) {
                $dsn = (string) $db['dsn'];
                $dbdetails = db::translateDSN($dsn);
            }
            $result = $this->environment->xpath("database");
            foreach ($result as $db) {
                $dsn = (string) $db['dsn'];
                $dbdetails = db::translateDSN($dsn);
                /*
                if ($this->getDatabaseSlave()) {
                    $slavedb = ezcDbFactory::parseDSN($this->getDatabaseSlave());
                    $fields = array(
                        "username",
                        "password",
                        "hostspec"
                    );
                    foreach ($fields as $field) {
                        if ($slavedb[$field]) {
                            $dbdetails[$field] = $slavedb[$field];
                        }
                    }
                }
                */
            }
            
            $solr_master = eZCluster\ClusterNode::getSolrMasterHost();
            if (instance::current()->ip() == $solr_master or empty($solr_master)) {
                $solr = "http://localhost:8983/solr/".$this->name;
            } else {
                $solr = "http://" . $solr_master . ":8983/solr/".$this->name;
            }
            if (! is_dir("/home/" . eZCluster\CloudSDK::USER . "/.composer")) {
                eZCluster\ClusterTools::mkdir("/home/" . eZCluster\CloudSDK::USER . "/.composer", eZCluster \ CloudSDK::USER, 0755);
            }
            if (isset($dfsdetails['mount']) && ! is_dir("/home/" . eZCluster \ CloudSDK::USER . "/.composer")) {
                eZCluster\ClusterTools::mkdir($dfsdetails['mount'], eZCluster \ CloudSDK::USER, 0777);
            }
            if (eZCluster \ CloudSDK::$config['github-token']) {
                file_put_contents("/home/" . eZCluster \ CloudSDK::USER . "/.composer/config.json", '{ "config": { "github-oauth": { "github.com": "' . (string) eZCluster \ CloudSDK::$config['github-token'] . '" } } }');
            }
            foreach ($bootstrap[0] as $script) {
                $file = tempnam($this->dir, "script_");
                $patterns = array();
                $patterns[] = '/\[ENVIRONMENT\]/';
                $patterns[] = '/\[DATABASE_NAME\]/';
                $patterns[] = '/\[DATABASE_SERVER\]/';
                $patterns[] = '/\[DATABASE_USER\]/';
                $patterns[] = '/\[DATABASE_PASSWORD\]/';
                $patterns[] = '/\[AWS_KEY\]/';
                $patterns[] = '/\[AWS_SECRETKEY\]/';
                $patterns[] = '/\[AWS_ACCOUNTID\]/';
                $patterns[] = '/\[SOLR_URL\]/';
                $replacements = array();
                $replacements[] = $this->name;
                $replacements[] = $dbdetails["database"];
                $replacements[] = $dbdetails["hostspec"];
                $replacements[] = $dbdetails["username"];
                $replacements[] = $dbdetails["password"];
                $replacements[] = (string) eZCluster\CloudSDK::$config['access_key'];
                $replacements[] = (string) eZCluster\CloudSDK::$config['secret_key'];
                $replacements[] = (string) eZCluster\CloudSDK::$config['account_id'];
                $replacements[] = $solr;
                if (isset($dfsdetails))
                {
                $patterns[] = '/\[DFS_TYPE\]/';
                $patterns[] = '/\[DFS_DATABASE_NAME\]/';
                $patterns[] = '/\[DFS_DATABASE_SERVER\]/';
                $patterns[] = '/\[DFS_DATABASE_USER\]/';
                $patterns[] = '/\[DFS_DATABASE_PASSWORD\]/';
                $patterns[] = '/\[DFS_MOUNT\]/';
                $patterns[] = '/\[MEMCACHED\]/';
                $patterns[] = '/\[BUCKET\]/';
                $replacements[] = $dfsdetails["type"];
                $replacements[] = $dfsdetails["database"];
                $replacements[] = $dfsdetails["hostspec"];
                $replacements[] = $dfsdetails["username"];
                $replacements[] = $dfsdetails["password"];
                $replacements[] = $dfsdetails["mount"];
                $replacements[] = $dfsdetails["memcached"];
                $replacements[] = $dfsdetails["bucket"];                
                }
                
                $script = preg_replace($patterns, $replacements, ltrim((string) $script));
                file_put_contents($file, $script);
                chmod($file, 0755);
                chown($file, eZCluster\CloudSDK::USER);
                chgrp($file, eZCluster\CloudSDK::USER);
                // execute as non root
                $this->run( $file );
                if (file_exists($file)) {
                    unlink($file);
                }
                eZCluster\ClusterTools::mkdir($this->docroot, eZCluster\CloudSDK::USER, 0777);
            }
            chmod($this->dir, 0777);
            chown($this->dir, eZCluster\CloudSDK::USER);
            chgrp($this->dir, eZCluster\CloudSDK::USER);
        }
        $scm = $this->environment->xpath("scm");
        if (! empty($scm) and empty($bootstrap)) {
            $name = strtoupper($this->name);
            $url = (string) $scm[0]['url'];
            
            if ((string) $scm[0]['type'] == 'svn') {
                $url = new ezcUrl((string) $scm[0]['url'] . '/' . (string) $scm[0]['branch']);
                $user = $url->user;
                $pass = $url->pass;
                
                if (is_dir($this->dir) and ! is_dir($this->dir . "/.svn")) {
                    $url->user = null;
                    $url->pass = null;
                    system("svn co --force --trust-server-cert --non-interactive --username $user --password $pass " . $url->buildUrl() . " " . $this->dir);
                }
            } elseif ((string) $scm[0]['type'] == 'git') {
                system("git clone " . $url->buildUrl() . " " . $this->dir);
            }
        }
    }

    function run($command)
    {
        $command = escapeshellarg("cd {$this->dir} && " . $command);
        $command = "su -m -c {$command}  - " . eZCluster\CloudSDK::USER;
        system($command);
    }

    static function getList()
    {
        $list = array();
        $xp = "/aws/cluster[ @lb = '" . lb::current()->id . "' ]/environment";
        $result = eZCluster\CloudSDK::$config->xpath($xp);
        if (is_array($result)) {
            foreach ($result as $environment) {
                $list[] = new self($environment['name']);
            }
        }
        return $list;
    }
}
