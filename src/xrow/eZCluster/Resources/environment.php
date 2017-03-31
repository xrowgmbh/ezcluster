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
use Symfony\Component\Filesystem\Filesystem;
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
            $xp = "/aws/cluster/environment[ @name = '" . $name . "' ]";
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
        $this->parameters = array();
        foreach ( $this->environment->attributes() as $key => $value ){
            $this->parameters[strtoupper($key)] = (string) $value;
        }
        if (!isset($this->parameters["SYMFONY_ENV"])){
            $this->parameters["SYMFONY_ENV"] = "prod";
            $this->parameters["ENVIRONMENT"] = "prod";
            
        }
        else{
            $this->parameters["ENVIRONMENT"] = $this->parameters["SYMFONY_ENV"];
        }
        if ($this->parameters["SYMFONY_ENV"] === "dev" ){
            $this->parameters["USE_DEBUGGING"] = "1";
        }
        else {
            $this->parameters["USE_DEBUGGING"] = "0";
        }

        if (! empty($this->parameters["SCM"])) {
            if (strpos($this->parameters["SCM"], 'svn') !== false) {
                if (strpos($this->parameters["SCM"], "/", strlen($this->parameters["SCM"]) ) === false )
                {
                    $this->parameters["SCM"] .= "/";
                }
        
                if (! isset($this->environment['branch'])) {
                    $url = new \ezcUrl( $this->parameters["SCM"] );
                } else {
                    $url = new \ezcUrl( $this->parameters["SCM"] . (string) $this->environment['branch'] );
                }
                $this->parameters["SVN_USER"] = $url->user;
                $this->parameters["SVN_PASS"] = $url->pass;
                $url->user = null;
                $url->pass = null;
                $this->parameters["BRANCH"] = $url->buildUrl();
                if (strpos($this->parameters["BRANCH"], "/", strlen($this->parameters["BRANCH"]) ) === false )
                {
                    $this->parameters["BRANCH"] .= "/";
                }
                svn_auth_set_parameter(SVN_AUTH_PARAM_DONT_STORE_PASSWORDS, false);
                svn_auth_set_parameter(PHP_SVN_AUTH_PARAM_IGNORE_SSL_VERIFY_ERRORS, true);
                svn_auth_set_parameter(SVN_AUTH_PARAM_DEFAULT_USERNAME, $this->parameters["SVN_USER"]);
                svn_auth_set_parameter(SVN_AUTH_PARAM_DEFAULT_PASSWORD, $this->parameters["SVN_PASS"]);
                $this->parameters["REVISION"] = svn_log($this->parameters["BRANCH"],SVN_REVISION_HEAD, 1, 1)[0]['rev'];
        
            }elseif (strpos($this->parameters["SCM"], 'git') !== false) {
                $url = new \ezcUrl($this->parameters["SCM"]);
                $this->parameters["GIT_USER"] = $url->user;
                $this->parameters["GIT_PASSWORD"] = $url->pass;
                if (! isset($this->environment['branch'])) {
                    $this->parameters["BRANCH"] = "master";
                } else {
                    $this->parameters["BRANCH"] = $this->environment['branch'];
                }
                if ( $this->parameters["BRANCH"] ){
                    $git_rev = $this->run("/usr/bin/git ls-remote " . $this->parameters["SCM"] . " " . $this->parameters["BRANCH"]);
                }
                else {
                    $git_rev = $this->run("/usr/bin/git ls-remote " . $this->parameters["SCM"] . " HEAD");
                }
                list( $this->parameters["REVISION"] ) = preg_split("/[\s,]+/", $git_rev, -1 );
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
            }
            if (isset($db['memcached']))
                $dfsdetails['memcached'] = (string) $db['memcached'];
            if (isset($db['bucket']))
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
        $this->parameters["ENVIRONMENT_NAME"] = $this->name;
        if( isset( $dbdetails ) ){
            $this->parameters["DATABASE_NAME"] = $dbdetails["database"];
            $this->parameters["DATABASE_SERVER"] = $dbdetails["hostspec"];
            $this->parameters["DATABASE_HOST"] = $dbdetails["hostspec"];
            $this->parameters["DATABASE_USER"] = $dbdetails["username"];
            $this->parameters["DATABASE_DRIVER"] = "pdo_mysql";
            $this->parameters["DATABASE_PORT"] = "3306";
            $this->parameters["DATABASE_PASSWORD"] = $dbdetails["password"];
        }
        $this->parameters["AWS_KEY"] = (string) CloudSDK::$config['access_key'];
        $this->parameters["AWS_SECRETKEY"] = (string) CloudSDK::$config['secret_key'];
        $this->parameters["AWS_ACCOUNTID"] = (string) CloudSDK::$config['account_id'];
        $solr_master = ClusterNode::getSolrMasterHost();
        if (empty($solr_master)) {
            $solr = "http://localhost:8983/solr/#";
        } else {
            $solr = "http://" . $solr_master . ":8983/solr/#";
        }
        if (!isset( $this->parameters["SEARCH_ENGINE"] )){
            $this->parameters["SEARCH_ENGINE"] = "solr";
        }
        if (!isset( $this->parameters["SOLR_DSN"] )){
            $this->parameters["SOLR_DSN"] = $solr;
        }
        if (!isset( $this->parameters["SOLR_CORE"] )){
            $this->parameters["SOLR_CORE"] = $this->name;
        }
        if ( isset( $dfsdetails ) )
        {
            $this->parameters["DFS_TYPE"] = $dfsdetails["type"];
            $this->parameters["DFS_DATABASE_NAME"] = $dfsdetails["database"];
            $this->parameters["DFS_DATABASE_SERVER"] = $dfsdetails["hostspec"];
            $this->parameters["DFS_DATABASE_HOST"] = $dfsdetails["hostspec"];
            $this->parameters["DFS_DATABASE_USER"] = $dfsdetails["username"];
            $this->parameters["DFS_DATABASE_PASSWORD"] = $dfsdetails["password"];
            $this->parameters["DFS_MOUNT"] = $dfsdetails["mount"];
            if (isset($dfsdetails['memcached']))
                $this->parameters["MEMCACHED"] = $dfsdetails["memcached"];
            if (isset($dfsdetails['bucket']))
                $this->parameters["BUCKET"] = $dfsdetails["bucket"];
        }
        $this->parameters["PATH"] = CloudSDK::path();
        $this->parameters["HOME"] = "/home/" . CloudSDK::USER;
        $this->parameters["LANG"] = "en_US.UTF-8";
        $this->parameters["COMPOSER_NO_INTERACTION"] = "1";
        $this->parameters["SECRET"] = uniqid();
        $this->parameters["MAILER_TRANSPORT"] = "smtp";
        $this->parameters["MAILER_HOST"] = "127.0.0.1";
        $this->parameters["MAILER_USER"] = null;
        $this->parameters["MAILER_PASSWORD"] = null;
        $this->parameters["LOCALE"] = "en";
        $this->parameters["LOCALE_FALLBACK"] = $this->parameters["LOCALE"];
        $this->parameters["SECRET"] = md5( print_r( $this->parameters, true ) );
        foreach ( $this->parameters as $key => $value ){
            $this->parameters["SYMFONY__" . $key] = $value;
            $this->parameters["SYMFONY__" . str_replace( "_", "__",$key )] = $value;            
        }
    }
    public function createYAMLParametersFile()
    {
        $fs = new \Symfony\Component\Filesystem\Filesystem();

        $parameter = array();
        foreach( $this->parameters as $key => $var ){
            $parameter[strtolower(str_replace( "_", ".", $key ))] = $var;
            $parameter[strtolower( $key )] = $var;
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
        if ( isset( $dir ) && file_exists($dir)){
            $fs->dumpFile( $dir . "/parameters.yml", $yaml );
        }
    }
    public function createHTTPVariablesFile()
    {
        $fs = new \Symfony\Component\Filesystem\Filesystem();

        $varfile= "";
        foreach( $this->parameters as $key => $var ){
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
        $vhost->parameters = $this->parameters;
        $vhost->dir = $dir;

        if (! file_exists($dir)) {
            mkdir($dir, 0777, true);
            chown($dir, CloudSDK::USER);
            chgrp($dir, CloudSDK::GROUP);
        }
        $vhost->name = $this->name;
        $vhost->hosts = array();
        foreach ($this->environment->xpath('hostname') as $host) {
            $vhost->hosts[] = (string) $host;
        }
        if( isset( $this->environment->protection ) ){
           $vhost->protection["user"] = $this->environment->protection['user'];
           $vhost->protection["password"] = $this->environment->protection['password'];
        }
        else
        {
           $vhost->protection = null;
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
            if ( $fs->exists($this->dirtmp ) ) {
                throw new \Exception("Delete not successfull for " . $this->dirtmp);
            }
        }

        if ( $fs->exists($this->dir . ".new" ) ) {
            $fs->remove( $this->dir . ".new" );
            if ( $fs->exists($this->dir . ".new" ) ) {
                throw new \Exception("Delete not successfull for " . $this->dir);
            }
        }

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
            $composer_file = "/home/" . CloudSDK::USER . "/.composer/config.json";
            file_put_contents( $composer_file, '{ "config": { "github-oauth": { "github.com": "' . (string) CloudSDK::$config['github-token'] . '" } } }');  
            chmod( $composer_file, 0600);
            chown( $composer_file, CloudSDK::USER);
            chgrp( $composer_file, CloudSDK::GROUP);
        }

        $script = (string) $this->environment["script"];
        chmod($this->dirtmp, 0777);
        chown($this->dirtmp, CloudSDK::USER);
        chgrp($this->dirtmp, CloudSDK::GROUP);

        //checkout & execute
        if (! empty($this->parameters["SCM"]) and empty($script) and empty( $bootstrap_script )) {
            $file = $this->dirtmp . "/build";
            if (strpos($this->parameters["SCM"], 'svn') !== false) {
                if (! is_dir($this->dirtmp . "/.svn")) {
                    $this->run("svn co --force --quiet --trust-server-cert --non-interactive --username ". $this->parameters["SVN_USER"] . " --password ". $this->parameters["SVN_PASS"]. " " . $this->parameters["BRANCH"] . " " . $this->dirtmp);
                }
            } elseif (strpos($this->parameters["SCM"], 'git') !== false) {
                
                $this->run( "/usr/bin/git " . join(" ", array(
                    "clone",
                    $this->parameters["SCM"],
                    "--branch",
                    $this->parameters["BRANCH"],
                    "--single-branch",
                    $this->dirtmp
                )), $this->parameters, $this->dirtmp);
                $this->run( "/usr/bin/git " . join(" ", array(
                    "submodule",
                    "update",
                    "--init"
                )), $this->parameters, $this->dirtmp);

            } elseif (strpos($this->parameters["SCM"], 'file') !== false) {
                $path = str_replace ( "file://" , "" , $this->parameters["SCM"] );
                $this->run( "/usr/bin/cp " . join(" ", array(
                    "-R",
                    $path . "/.",
                    $this->dirtmp
                )), $this->parameters, $this->dirtmp);

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
            foreach( $this->parameters as $key => $value ){
                $patterns[] = '/\[' . $key . '\]/';
                $replacements[] = $value;
            }
            $bootstrap_script = preg_replace($patterns, $replacements, ltrim((string) $bootstrap_script));
            file_put_contents($file, $bootstrap_script);
        }
        //store vars for later execution
        $varfile = "#!/bin/bash\n";
        foreach( $this->parameters as $key => $var ){
            $varfile .= "export $key=\"$var\"\n";
        }
        $varfile .= "if [ $# -ne 0 ]\n";
        $varfile .= "then\n";
        $varfile .= "exec \"$@\"\n";
        $varfile .= "fi\n";

        file_put_contents($this->dirtmp . "/variables.bash",$varfile);
        chmod( $this->dirtmp . "/variables.bash", 0755);
        
        if (file_exists($file)) {
            chmod( $file, 0755);
            if(strpos(file_get_contents($file), "\r\n") !== false) {
                $f = file_get_contents($file);
                $f = str_replace("\r", "", $f);
                file_put_contents($file, $f);
                //throw new \RuntimeException( "Build file '$file' has windows linefeeds" );
            }
            $this->run($file, $this->parameters, $this->dirtmp);
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
        chgrp($this->dirtmp, CloudSDK::GROUP);
        ClusterTools::mkdir($this->docroottmp, CloudSDK::USER, 0777);
        
        try {
            $fs->rename( $this->dir, $this->dir. ".new" );
        }catch(\Exception $e){
            $fs->chmod( $this->dir . ".new", 0777, 0000, true );
            $fs->remove( $this->dir . ".new" );
            $fs->rename( $this->dir, $this->dir. ".new" );            
        }
        $fs->rename( $this->dirtmp, $this->dir );
        try {
            $fs->remove( $this->dir . ".new" );
        }catch(\Exception $e){
            $fs->chmod( $this->dir . ".new", 0777, 0000, true );
            $fs->remove( $this->dir . ".new" );
        }
    }

    function run($command, $env = array(), $wd = null)
    {
        $user = posix_getpwnam(CloudSDK::USER);
        $fs = new Filesystem();
        if ( $fs->exists( '/vagrant/id_rsa' ) ){
            $idfile = '/home/'. CloudSDK::USER . "/.ssh/id_rsa";
            $fs->copy('/vagrant/id_rsa', $idfile );
            $fs->chown( $idfile, CloudSDK::USER);
            $fs->chgrp( $idfile, CloudSDK::GROUP);
            $fs->chmod( $idfile, 0600);
        }
        posix_setgid($user['gid']);
        posix_setuid($user['uid']);
        $process = new Process($command);
        if ($wd) {
            $process->setWorkingDirectory($wd);
        }
        $process->setTimeout(24*3600);
        $process->setIdleTimeout(24*3600);
        if ( !empty($env) )
        {
            $env['PATH'] = CloudSDK::path();
            $process->setEnv($env);
        }
        $process->setPty(true); // https://github.com/composer/composer/issues/5044
        $process->run(function ($type, $buffer) {
            if (Process::ERR === $type) {
                echo $buffer;
            } else {
                echo $buffer;
            }
        });
        if (! $process->isSuccessful()) {
            $out = $process->getErrorOutput();
            throw new \RuntimeException( "Command '$command' failed with '" . $out . "'" );
        }
        
        $user = posix_getpwnam("root");
        posix_setgid($user['gid']);
        posix_setuid($user['uid']);

        return $process->getOutput();
    }

    static function getList()
    {
        $list = array();
        $result = CloudSDK::$config->xpath("/aws/cluster/environment");
        if (is_array($result)) {
            foreach ($result as $environment) {
                $list[] = new self($environment['name']);
            }
        }
        return $list;
    }
}
