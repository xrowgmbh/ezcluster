<?php
namespace xrow\eZCluster\Resources;

use xrow\eZCluster;
use \ezcTemplateConfiguration;
use \ezcTemplateNoContext;
use \ezcTemplate;
use Symfony\Component\Process\Process;

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
        system('/usr/bin/rm -Rf ' . $this->dir . '/*');
        system('/usr/bin/rm -Rf ' . $this->dir . '/.[^.]*');
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
        
        if (! file_exists($this->dir)) {
            eZCluster\ClusterTools::mkdir($this->dir, eZCluster\CloudSDK::USER, 0777);
        }
        chmod($this->dir, 0777);
        if (isset($this->environment->bootstrap->script)){
            $bootstrap_script = (string)$this->environment->bootstrap->script[0];
        }
        
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
             * if ($this->getDatabaseSlave()) { $slavedb = ezcDbFactory::parseDSN($this->getDatabaseSlave()); $fields = array( "username", "password", "hostspec" ); foreach ($fields as $field) { if ($slavedb[$field]) { $dbdetails[$field] = $slavedb[$field]; } } }
             */
        }
        
        $solr_master = eZCluster\ClusterNode::getSolrMasterHost();
        if (instance::current()->ip() == $solr_master or empty($solr_master)) {
            $solr = "http://localhost:8983/solr/" . $this->name;
        } else {
            $solr = "http://" . $solr_master . ":8983/solr/" . $this->name;
        }
        if (! is_dir("/home/" . eZCluster\CloudSDK::USER . "/.composer")) {
            eZCluster\ClusterTools::mkdir("/home/" . eZCluster\CloudSDK::USER . "/.composer", eZCluster\CloudSDK::USER, 0755);
        }
        if (isset($dfsdetails['mount']) && ! is_dir("/home/" . eZCluster\CloudSDK::USER . "/.composer")) {
            eZCluster\ClusterTools::mkdir($dfsdetails['mount'], eZCluster\CloudSDK::USER, 0777);
        }
        if (eZCluster\CloudSDK::$config['github-token']) {
            file_put_contents("/home/" . eZCluster\CloudSDK::USER . "/.composer/config.json", '{ "config": { "github-oauth": { "github.com": "' . (string) eZCluster\CloudSDK::$config['github-token'] . '" } } }');
        }
        $env = array();

        foreach ( $this->environment->attributes() as $key => $value ){
            $env[strtoupper($key)] = $value;
        }
        $env["ENVIRONMENT"] = $this->name;
        $env["DATABASE_NAME"] = $dbdetails["database"];
        $env["DATABASE_SERVER"] = $dbdetails["hostspec"];
        $env["DATABASE_USER"] = $dbdetails["username"];
        $env["DATABASE_PASSWORD"] = $dbdetails["password"];
        $env["AWS_KEY"] = (string) eZCluster\CloudSDK::$config['access_key'];
        $env["AWS_SECRETKEY"] = (string) eZCluster\CloudSDK::$config['secret_key'];
        $env["AWS_ACCOUNTID"] = (string) eZCluster\CloudSDK::$config['account_id'];
        $env["SOLR_URL"] = $solr;
        $env["DFS_TYPE"] = $dfsdetails["type"];
        $env["DFS_DATABASE_NAME"] = $dfsdetails["database"];
        $env["DFS_DATABASE_SERVER"] = $dfsdetails["hostspec"];
        $env["DFS_DATABASE_USER"] = $dfsdetails["username"];
        $env["DFS_DATABASE_PASSWORD"] = $dfsdetails["password"];
        $env["DFS_MOUNT"] = $dfsdetails["mount"];
        $env["MEMCACHED"] = $dfsdetails["memcached"];
        $env["BUCKET"] = $dfsdetails["bucket"];
        $env["PATH"] = "/sbin:/bin:/usr/sbin:/usr/bin";
        $env["HOME"] = "/home/" . eZCluster\CloudSDK::USER;
        $env["LANG"] = "en_US.UTF-8";
        $env["COMPOSER_NO_INTERACTION"] = "1";
        $scm = (string) $this->environment["scm"];
        $script = (string) $this->environment["script"];
        chmod($this->dir, 0777);
        chown($this->dir, eZCluster\CloudSDK::USER);
        chgrp($this->dir, eZCluster\CloudSDK::USER);
        // set vars
        if (! empty($scm)) {
            if (strpos($scm, 'svn') !== false) {
                if (strpos($scm, "/", strlen($scm) ) === false )
                {
                    $scm .= "/";
                }

                if (! isset($this->environment['branch'])) {
                    $url = new \ezcUrl( $scm );
                } else {
                    $url = new \ezcUrl( $scm . (string) $this->environment['branch'] );
                }
                $env["USER"] = $url->user;
                $env["PASSWORD"] = $url->pass;
                $env["SVN_USER"] = $url->user;
                $env["SVN_PASS"] = $url->pass;
                $env["PASSWORD"] = $url->pass;
                $env["BRANCH"] = $url->buildUrl();
                if (strpos($env["BRANCH"], "/", strlen($env["BRANCH"]) ) === false )
                {
                    $env["BRANCH"] .= "/";
                }
            }elseif (strpos($scm, 'git') !== false) {
                $url = new \ezcUrl($scm);
                $env["USER"] = $url->user;
                $env["PASSWORD"] = $url->pass;
                $env["PASS"] = $url->pass;
                if (! isset($this->environment['branch'])) {
                    $env["BRANCH"] = "master";
                } else {
                    $env["BRANCH"] = $this->environment['branch'];
                }
            }
        }
        //checkout & execute
        if (! empty($scm) and empty($script) and empty( $bootstrap_script )) {
            $file = $this->dir . "/build";
            if (strpos($scm, 'svn') !== false) {
                if (! is_dir($this->dir . "/.svn")) {
                    $this->run("svn co --force --quiet --trust-server-cert --non-interactive --username ". $env["USER"]. " --password ". $env["PASS"]. " " . $env["BRANCH"] . " " . $this->dir);
                }
            } elseif (strpos($scm, 'git') !== false) {
                
                $this->run("/usr/bin/git " . join(" ", array(
                    "clone",
                    $url->buildUrl(),
                    "--branch",
                    $env["BRANCH"],
                    "--single-branch",
                    $this->dir
                )), $env, $this->dir);
            }
            chmod( $file, 0755);
            $this->run($file, $env, $this->dir);
        }
        if (! empty($script) and empty($bootstrap_script)) {
            $file = $this->dir . "/build";

            file_put_contents( $file, file_get_contents( $script ));

            chmod( $file, 0755);
            $this->run($file, $env, $this->dir);
        }
        if (! empty($bootstrap_script)) {
            $script = $this->environment->bootstrap->script[0];
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
            if (isset($dfsdetails)) {
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
            
            $bootstrap_script = preg_replace($patterns, $replacements, ltrim((string) $bootstrap_script));
            file_put_contents($file, $bootstrap_script);
            chmod( $file, 0755);
            // execute as non root
            $this->run($file, $env, $this->dir);
            if (file_exists($file)) {
                unlink($file);
            }
            eZCluster\ClusterTools::mkdir($this->docroot, eZCluster\CloudSDK::USER, 0777);
        }

        chmod($this->dir, 0777);
        chown($this->dir, eZCluster\CloudSDK::USER);
        chgrp($this->dir, eZCluster\CloudSDK::USER);
    }

    function run($command, $env = array(), $wd = null)
    {
        $user = posix_getpwnam(eZCluster\CloudSDK::USER);
        posix_setgid($user['gid']);
        posix_setuid($user['uid']);
        $process = new Process($command);
        if ($wd) {
            $process->setWorkingDirectory($wd);
        }
        $process->setTimeout(3600);
        $process->setIdleTimeout(3600);
        $process->setEnv($env);
        $process->run(function ($type, $buffer) {
    if (Process::ERR === $type) {
        echo $buffer;
    } else {
        echo $buffer;
    }
});
        
        if (! $process->isSuccessful()) {
            $out = $process->getErrorOutput();
            throw new \RuntimeException($out);
        }
        
        $user = posix_getpwnam("root");
        posix_setgid($user['gid']);
        posix_setuid($user['uid']);
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
