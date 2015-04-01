<?php
namespace xrow\eZCluster\Resources;

use xrow\eZCluster\Resources;
use xrow\eZCluster\Abstracts;
use xrow\eZCluster;
use \ezcDbFactory;
use Ssh;

class db extends Abstracts\xrowEC2Resource
{

    function __construct($id)
    {
        $this->id = $id;
    }

    function host()
    {
        return (string) $this->id;
    }

    public static function getDatabaseSettings($rds = true)
    {
        $settings = array(
            'wait_timeout' => 60, // Threads will clean up faster, crons might loose conenction to DFS, also see 'interactive_timeout' of mysql
            'table_open_cache' => 4000, // "Tuning MySQL for eZ Publish" on share.ez.no
            // not neededfor 5.6 'max_connections' => 400 , # Amount of Nodes x Requests x 2 for NFS
            'key_buffer_size' => '300M', // "Tuning MySQL for eZ Publish" on share.ez.no
            'sort_buffer_size' => '2M', // Abe changed 2010-07-30 following "Tuning MySQL for eZ Publish" on share.ez.no
            'max_allowed_packet' => '16M', // Harmless, only allocated if needed
            'thread_stack' => '256KB', // Value for 64 BIT Systems
            'thread_cache_size' => '8', // Higher value for site with many connections ?? Bitte Pr�fen ob nciht h�herer Wert ProSieben 250
            'myisam-recover' => 'BACKUP', // Full safty
            'query_cache_type' => '1', // "Tuning MySQL for eZ Publish" on share.ez.no
            'query_cache_limit' => '4M', // Unsure, if this settings is optimal
            'query_cache_size' => '64M', // Too small? Abe, 2010-07-30, following mysqltuner.pl recommendations (updated 2010-12-28 following tuning-primer.sh)
            'join_buffer_size' => '8M', // was 128K? (Recommendation: > 128.0K, or always use indexes with joins)
            'tmp_table_size' => '800M', // was 16M (?)
            'max_heap_table_size' => '800M', // was 16M (?)
            'innodb_buffer_pool_size' => '{DBInstanceClassMemory*8/10}', // depending on size of data in innodb tables, 80% of free memory. Example value '3G'
            // ill not enable due backwards compatibility
            // nnodb_log_file_size=256M # The default value is rather small, do not change the value, if you change the value the server will not restart
            'innodb_flush_log_at_trx_commit' => '2', // should have better performance
            'innodb_thread_concurrency' => '0', // Lets try not to limit
            'innodb_flush_method' => 'O_DIRECT', // Looks like the best setting
            'innodb_file_per_table' => 1,
            'interactive_timeout' => '60', // If thread start getting too many, the database server may crash. Whole cluster fails.
            'wait_timeout' => '60'
        );
        if (eZCluster\ClusterNode::$config) {
            $result = eZCluster\ClusterNode::$config->xpath("/aws/cluster[ @lb = '" . Resources\lb::current() . "' ]/database-setting");
    
            if ($result !== false) {
                foreach ($result as $key => $setting) {
                    $settings[(string) $setting['name']] = trim((string) $setting);
                }
            }
        }
        if (! $rds) {
            unset($settings['innodb_buffer_pool_size']);
            // nset( $settings['key_buffer_size'] );
            // nset( $settings['max_heap_table_size'] );
            // nset( $settings['tmp_table_size'] );
            // settings['innodb_buffer_pool_size'] = '200M';
        }
        if (eZCluster\ClusterNode::getRAMDiskSize()) {
            $settings['tmpdir'] = '/var/mysql.tmp';
        }
        $rset = array();
        foreach ($settings as $key => $setting) {
            $tmp = new \stdClass();
            $tmp->key = $key;
            $tmp->value = $setting;
            $rset[] = $tmp;
        }
        return $rset;
    }
    static public function exists($name)
    {
        try {
            $db = new db($name);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    function describe()
    {}

    static public function create($name = null, $availability_zones = null, certificate $cert = null)
    {}

    static public function createParameter(array $settings, $name = 'xrow', $availability_zones = null, certificate $cert = null)
    {}

    static public function migrateDatabase($dsnsource, $dsntarget, $session, $where = false)
    {
        if (! $session) {
            $source = self::translateDSN($dsnsource);
            $target = self::translateDSN($dsntarget);
            $dump = "mysqldump --compress --default-character-set=utf8 --opt --single-transaction --add-drop-table --add-drop-database -h " . $source['hostspec'] . " -u " . $source['username'];
            if (! empty($source['password'])) {
                $dump .= " -p'" . $source['password'];
            }
            $dump .= "' --databases " . $source['database'] . " | gzip > /var/tmp/dump.sql.gz";
            system($dump);
            $insert = "gunzip < /var/tmp/dump.sql.gz | mysql --compress -h" . $target['hostspec'] . " -u " . $target['username'];
            if (! empty($target['password'])) {
                $insert .= " -p'" . $target['password'] . "'";
            }
            if ( $where )
            {
                $dump .= " --where=\"$where\"";
            }
            system($insert);
            unlink("/var/tmp/dump.sql.gz");
        } else {
            $exec = $session->getExec();
            $source = db::translateDSN($dsnsource);
            
            $dump = "mysqldump --compress --default-character-set=utf8 --opt --single-transaction --add-drop-table --add-drop-database -h " . $source['hostspec'];
            if (!empty($source['username'])) {
                $dump .= " -u " . $source['username'];
            }
            if (!empty($source['password'])) {
                $dump .= " -p'" . $source['password']."'";
            }
            if ( $where )
            {
                $dump .= " --where=\"$where\"";
            }
            $dump .= " --databases " . $source['database'] . " | gzip > /var/tmp/dump.sql.gz";
            $exec->run($dump);
            $sftp = $session->getSftp();
            $sftp->receive("/var/tmp/dump.sql.gz", "/var/tmp/dump.sql.gz");
            $sftp->unlink("/var/tmp/dump.sql.gz");
            $target = db::translateDSN($dsntarget);
            
            $insert = "gunzip < /var/tmp/dump.sql.gz | mysql --compress -h" . $target['hostspec'];
            if (!empty($target['username'])) {
                $insert .= " -u " . $target['username'];
            }if (!empty($target['password'])) {
                $insert .= " -p'" . $target['password'] . "'";
            }
            echo "$insert";
            system($insert);
            unlink("/var/tmp/dump.sql.gz");
        }
    }

    public static function initDB($dsn, $dbmaster)
    {
        $dbmaster->query("SET NAMES utf8");
        $rows = $dbmaster->query('SHOW DATABASES');
        $dbs_exists = array();
        foreach ($rows as $row) {
            if (! in_array($row, array(
                'mysql',
                'information_schema'
            ))) {
                $dbs_exists[] = $row['database'];
            }
        }
        
        $dbdetails = ezcDbFactory::parseDSN($dsn);

        if (! in_array($dbdetails['database'], $dbs_exists)) {
            $dbmaster->query('CREATE DATABASE IF NOT EXISTS ' . $dbdetails['database'] . ' CHARACTER SET utf8 COLLATE utf8_general_ci');
        }

        //test if user has access else grant
        try {
            $grants = $dbmaster->query('SHOW GRANTS FOR ' . $dbdetails['username']);
        } catch (\Exception $e) {
            $grants = false;
        }
        if( !$grants )
        {
            $grant = 'GRANT ALL ON ' . $dbdetails['database'] . '.* TO ' . $dbdetails['username'] . "@'%' IDENTIFIED BY '" . $dbdetails['password'] . "'";
            $dbmaster->query($grant);
            $grant = 'GRANT ALL ON ' . $dbdetails['database'] . '.* TO ' . $dbdetails['username'] . "@'localhost' IDENTIFIED BY '" . $dbdetails['password'] . "'";
            $dbmaster->query($grant);
        }
        // test DB
        if ( $dbdetails['hostspec'] == "localhost" ){
            $db = ezcDbFactory::create($dbdetails);
            $db->query('SHOW TABLES');
        }
    }

    public static function translateDSN($dsn)
    {
        $dbdetails = ezcDbFactory::parseDSN($dsn);
        if ($dbdetails['hostspec'] === 'localhost') {
            return $dbdetails;
        }
        if (db::exists($dbdetails['hostspec'])) {
            $db = new db($dbdetails['hostspec']);
            $dbdetails['hostspec'] = $db->host();
            return $dbdetails;
        }
        $server = ClusterNode::byName($dbdetails['hostspec']);
        if ($server) {
            $dbdetails['hostspec'] = $server->ip();
            return $dbdetails;
        }
        return $dbdetails;
    }
}
