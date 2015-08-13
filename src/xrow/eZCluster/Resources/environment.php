<?php
namespace xrow\eZCluster\Resources;

use xrow\eZCluster\CloudSDK;
use xrow\eZCluster\ClusterTools;
use xrow\eZCluster\ClusterNode;
use \ezcTemplateConfiguration;
use \ezcTemplateNoContext;
use \ezcTemplate;
use Symfony\Component\Process\Process;
use Symfony\Component\Finder\Finder;
use \stdClass;

class environment
{

    public $environment = null;

    public $docroot = null;

    public $dir = null;

    public $name = null;
    
    public $parameter = array();

    function __construct($name)
    {
        if ($name) {
            $xp = "/aws/cluster[ @lb = '" . lb::current() . "' ]/environment[ @name = '" . $name . "' ]";
            $result = CloudSDK::$config->xpath($xp);
            if ($result === array()) {
                throw new \Exception("$name not known in configuration");
            }
            $this->environment = $result[0];
            $this->name = (string) $this->environment['name'];
            $this->dir = CloudSDK::SITES_ROOT . "/" . $this->name;
            $this->dirtmp = CloudSDK::SITES_ROOT . "/" . $this->name . ".tmp";
            if (isset($this->environment['docroot'])) {
                $this->docroot = $this->dir . "/" . (string) $this->environment['docroot'];
                $this->docroottmp = $this->dirtmp . "/" . (string) $this->environment['docroot'];
            } else {
                $this->docroot = $this->dir;
                $this->docroottmp = $this->dirtmp;
            }
        } else {
            throw new \Exception("Name not given. Please define a enviroment.");
        }
        // set vars
        $this->parameter = array();
        foreach ( $this->environment->attributes() as $key => $value ){
            $this->parameter[strtoupper($key)] = $value;
        }
        if (!isset($this->parameter["SYMFONY_ENV"])){
            $this->parameter["SYMFONY_ENV"] = "prod";
        }
        if (! empty($this->parameter["SCM"])) {
            if (strpos($this->parameter["SCM"], 'svn') !== false) {
                if (strpos($this->parameter["SCM"], "/", strlen($this->parameter["SCM"]) ) === false )
                {
                    $this->parameter["SCM"] .= "/";
                }
        
                if (! isset($this->environment['branch'])) {
                    $url = new \ezcUrl( $this->parameter["SCM"] );
                } else {
                    $url = new \ezcUrl( $this->parameter["SCM"] . (string) $this->environment['branch'] );
                }
                $this->parameter["SVN_USER"] = $url->user;
                $this->parameter["SVN_PASS"] = $url->pass;
                $url->user = null;
                $url->pass = null;
                $this->parameter["BRANCH"] = $url->buildUrl();
                if (strpos($this->parameter["BRANCH"], "/", strlen($this->parameter["BRANCH"]) ) === false )
                {
                    $this->parameter["BRANCH"] .= "/";
                }
                svn_auth_set_parameter(SVN_AUTH_PARAM_DONT_STORE_PASSWORDS, false);
                svn_auth_set_parameter(PHP_SVN_AUTH_PARAM_IGNORE_SSL_VERIFY_ERRORS, true);
                svn_auth_set_parameter(SVN_AUTH_PARAM_DEFAULT_USERNAME, $this->parameter["SVN_USER"]);
                svn_auth_set_parameter(SVN_AUTH_PARAM_DEFAULT_PASSWORD, $this->parameter["SVN_PASS"]);
                $this->parameter["REVISION"] = svn_log($this->parameter["BRANCH"],SVN_REVISION_HEAD, 1, 1)[0]['rev'];
        
            }elseif (strpos($this->parameter["SCM"], 'git') !== false) {
                $url = new \ezcUrl($this->parameter["SCM"]);
                $this->parameter["GIT_USER"] = $url->user;
                $this->parameter["GIT_PASSWORD"] = $url->pass;
                if (! isset($this->environment['branch'])) {
                    $this->parameter["BRANCH"] = "master";
                } else {
                    $this->parameter["BRANCH"] = $this->environment['branch'];
                }
                if ( $this->parameter["BRANCH"] ){
                    $git_rev = shell_exec("/usr/bin/git ls-remote " . $this->parameter["SCM"] . " HEAD");
                }
                else {
                    $git_rev = shell_exec("/usr/bin/git ls-remote " . $this->parameter["SCM"] . " " . $this->parameter["BRANCH"] );
                }
                list( $git_rev, $branch ) = preg_split("/[\s,]+/", $git_rev, 2 );
                $this->parameter["REVISION"] = $git_rev;
            }
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
                    ClusterTools::mkdir($dfsdetails['mount'], CloudSDK::USER, 0777);
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
        $this->parameter["ENVIRONMENT"] = $this->name;
        $this->parameter["DATABASE_NAME"] = $dbdetails["database"];
        $this->parameter["DATABASE_SERVER"] = $dbdetails["hostspec"];
        $this->parameter["DATABASE_USER"] = $dbdetails["username"];
        $this->parameter["DATABASE_PASSWORD"] = $dbdetails["password"];
        $this->parameter["AWS_KEY"] = (string) CloudSDK::$config['access_key'];
        $this->parameter["AWS_SECRETKEY"] = (string) CloudSDK::$config['secret_key'];
        $this->parameter["AWS_ACCOUNTID"] = (string) CloudSDK::$config['account_id'];
        $solr_master = ClusterNode::getSolrMasterHost();
        if (instance::current()->ip() == $solr_master or empty($solr_master)) {
            $solr = "http://localhost:8983/solr/" . $this->name;
        } else {
            $solr = "http://" . $solr_master . ":8983/solr/" . $this->name;
        }
        $this->parameter["SOLR_URL"] = $solr;
        if ( isset( $dfsdetails ) )
        {
            $this->parameter["DFS_TYPE"] = $dfsdetails["type"];
            $this->parameter["DFS_DATABASE_NAME"] = $dfsdetails["database"];
            $this->parameter["DFS_DATABASE_SERVER"] = $dfsdetails["hostspec"];
            $this->parameter["DFS_DATABASE_USER"] = $dfsdetails["username"];
            $this->parameter["DFS_DATABASE_PASSWORD"] = $dfsdetails["password"];
            $this->parameter["DFS_MOUNT"] = $dfsdetails["mount"];
            $this->parameter["MEMCACHED"] = $dfsdetails["memcached"];
            $this->parameter["BUCKET"] = $dfsdetails["bucket"];
        }
        $this->parameter["PATH"] = "/sbin:/bin:/usr/sbin:/usr/bin";
        $this->parameter["HOME"] = "/home/" . CloudSDK::USER;
        $this->parameter["LANG"] = "en_US.UTF-8";
        $this->parameter["COMPOSER_NO_INTERACTION"] = "1";
    }
    public function createYAMLParametersFile()
    {
        $fs = new \Symfony\Component\Filesystem\Filesystem();
        
        $parameter = array();
        foreach( $this->parameter as $key => $var ){
            $parameter[strtolower(str_replace( "_", ".", $key ))] = $var;
        }
        $dumper = new \Symfony\Component\Yaml\Dumper();
        $yaml = $dumper->dump(array( "parameters" => $parameter ), 2);
        
        if (file_exists($this->dirtmp . "/ezpublish/config")){
            $dir = $this->dirtmp . "/ezpublish/config";
        }
        elseif (file_exists($this->dirtmp . "/app/config"))
        {
            $dir = $this->dirtmp . "/app/config";
        }
        if ($dir){
            $fs->dumpFile( $dir . "/parameters.yml", $yaml );
        }
    }
    public function createHTTPVariablesFile()
    {
        $fs = new \Symfony\Component\Filesystem\Filesystem();
        
        $varfile= "";
        foreach( $this->parameter as $key => $var ){
            $varfile .= "SetEnv    SYMFONY__" . str_replace( "_", "__", $key ) . " \"$var\"\n";
        }
        
        $file = "/etc/httpd/sites" . "/" . $this->name . ".variables.conf";
        $fs->dumpFile( $file, $varfile );
    }
    public function getVHost()
    {
        $vhost = new stdClass();
        $dir = CloudSDK::SITES_ROOT . '/' . (string) $this->name;
        if (isset($this->environment['docroot'])) {
            $dir .= '/' . (string) $this->environment['docroot'];
        }
        
        $vhost->dir = $dir;
        
        if (! file_exists($dir)) {
            mkdir($dir, 0777, true);
            chown($dir, CloudSDK::USER);
            chgrp($dir, CloudSDK::USER);
        }
        $vhost->name = $this->name;
        $vhost->hosts = array();
        foreach ($this->environment->xpath('hostname') as $host) {
            $vhost->hosts[] = (string) $host;
        }
        return $vhost;
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
        $fs = new \Symfony\Component\Filesystem\Filesystem();
        
        if ( $fs->exists($this->dirtmp) ) {
            $fs->remove($this->dirtmp);
        }

        if ( $fs->exists($this->dirtmp . ".new" ) ) {
            $fs->remove( $this->dir . ".new" );
        }

        $t = new ezcTemplate();
        $t->configuration = CloudSDK::$ezcTemplateConfiguration;
        $t->send->access_key = (string) CloudSDK::$config['access_key'];
        $t->send->secret_key = (string) CloudSDK::$config['secret_key'];

        file_put_contents("/home/ec2-user/.s3cfg", $t->process('s3cfg.ezt'));

        if (! file_exists($this->dir)) {
            ClusterTools::mkdir($this->dir, CloudSDK::USER, 0777);
        }
        chmod($this->dir, 0777);
        if (! file_exists($this->dirtmp)) {
            ClusterTools::mkdir($this->dirtmp, CloudSDK::USER, 0777);
        }
        chmod($this->dirtmp, 0777);
        if (isset($this->environment->bootstrap->script)){
            $bootstrap_script = (string)$this->environment->bootstrap->script[0];
        }

        if (! is_dir("/home/" . CloudSDK::USER . "/.composer")) {
            ClusterTools::mkdir("/home/" . CloudSDK::USER . "/.composer", CloudSDK::USER, 0755);
        }
        if (isset($dfsdetails['mount']) && ! is_dir("/home/" . CloudSDK::USER . "/.composer")) {
            ClusterTools::mkdir($dfsdetails['mount'], CloudSDK::USER, 0777);
        }
        if (CloudSDK::$config['github-token']) {
            file_put_contents("/home/" . CloudSDK::USER . "/.composer/config.json", '{ "config": { "github-oauth": { "github.com": "' . (string) CloudSDK::$config['github-token'] . '" } } }');
        }

        $script = (string) $this->environment["script"];
        chmod($this->dirtmp, 0777);
        chown($this->dirtmp, CloudSDK::USER);
        chgrp($this->dirtmp, CloudSDK::USER);

        //checkout & execute
        if (! empty($this->parameter["SCM"]) and empty($script) and empty( $bootstrap_script )) {
            $file = $this->dirtmp . "/build";
            if (strpos($this->parameter["SCM"], 'svn') !== false) {
                if (! is_dir($this->dirtmp . "/.svn")) {
                    $this->run("svn co --force --quiet --trust-server-cert --non-interactive --username ". $this->parameter["USER"]. " --password ". $this->parameter["PASSWORD"]. " " . $this->parameter["BRANCH"] . " " . $this->dirtmp);
                }
            } elseif (strpos($this->parameter["SCM"], 'git') !== false) {
                
                $this->run("/usr/bin/git " . join(" ", array(
                    "clone",
                    $this->parameter["SCM"],
                    "--branch",
                    $this->parameter["BRANCH"],
                    "--single-branch",
                    $this->dirtmp
                )), $this->parameter, $this->dirtmp);
            }
        }
        if (! empty($script) and empty($bootstrap_script)) {
            $file = $this->dirtmp . "/build";
            file_put_contents( $file, file_get_contents( $script ));
        }

        if (! empty($bootstrap_script)) {
            $script = $this->environment->bootstrap->script[0];
            $file = tempnam($this->dirtmp, "script_");
            $patterns = array();
            $replacements = array();
            foreach( $this->parameter as $key => $value ){
                $patterns[] = '/\[' . $key . '\]/';
                $replacements[] = $value;
            }
            $bootstrap_script = preg_replace($patterns, $replacements, ltrim((string) $bootstrap_script));
            file_put_contents($file, $bootstrap_script);
        }
        //store vars for later execution
        $varfile= "";
        foreach( $this->parameter as $key => $var ){
            $varfile .= "export $key=\"$var\"\n";
            $varfile .= "export SYMFONY__" . str_replace( "_", "__", $key ) . "=\"$var\"\n";
        }
        file_put_contents($this->dirtmp . "/variables.bash",$varfile);
        chmod( $this->dirtmp . "/variables.bash", 0755);

        chmod( $file, 0755);
        $this->run($file, $this->parameter, $this->dirtmp);
        if (file_exists($file)) {
            unlink($file);
        }
        $this->createYAMLParametersFile();
        $cachedirs = array( "/ezpublish/cache" ,"/app/cache" );
        foreach( $cachedirs as $cachedir ){
            if ( $fs->exists( $this->dirtmp . $cachedir ) ){
                $finder = new Finder();
                $finder->directories()->in( $this->dirtmp . $cachedir );
                $fs->remove( $finder );
            }
        }
        chmod($this->dirtmp, 0777);
        chown($this->dirtmp, CloudSDK::USER);
        chgrp($this->dirtmp, CloudSDK::USER);
        ClusterTools::mkdir($this->docroottmp, CloudSDK::USER, 0777);
        $fs->rename( $this->dir, $this->dir. ".new" );
        $fs->rename( $this->dirtmp, $this->dir );
        $fs->remove( $this->dir . ".new" );
    }

    function run($command, $env = array(), $wd = null)
    {
        $user = posix_getpwnam(CloudSDK::USER);
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
        $result = CloudSDK::$config->xpath($xp);
        if (is_array($result)) {
            foreach ($result as $environment) {
                $list[] = new self($environment['name']);
            }
        }
        return $list;
    }
}
