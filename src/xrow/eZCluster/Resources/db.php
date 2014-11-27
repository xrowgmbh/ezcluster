<?php
namespace xrow\eZCluster\Resources;

use xrow\eZCluster\Abstracts;
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

    static public function migrateDatabase($dsnsource, $dsntarget, $session)
    {
        if (! $session) {
            $source = self::translateDSN($dsnsource);
            $target = self::translateDSN($dsntarget);
            $dump = "mysqldump --compress --default-character-set=utf8 --opt --single-transaction --add-drop-table --add-drop-database -h " . $source['hostspec'] . " -u " . $source['username'];
            if (! empty($source['password'])) {
                $dump .= " -p'" . $source['password'];
            }
            $dump .= "' --databases " . $source['database'] . " | gzip > /tmp/dump.sql.gz";
            system($dump);
            $insert = "gunzip < /tmp/dump.sql.gz | mysql --compress -h" . $target['hostspec'] . " -u " . $target['username'];
            if (! empty($target['password'])) {
                $insert .= " -p'" . $target['password'] . "'";
            }
            system($insert);
            unlink("/tmp/dump.sql.gz");
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
            $dump .= " --databases " . $source['database'] . " | gzip > /tmp/dump.sql.gz";
            $exec->run($dump);
            $sftp = $session->getSftp();
            $sftp->receive("/tmp/dump.sql.gz", "/tmp/dump.sql.gz");
            $sftp->unlink("/tmp/dump.sql.gz");
            $target = db::translateDSN($dsntarget);
            
            $insert = "gunzip < /tmp/dump.sql.gz | mysql --compress -h" . $target['hostspec'];
            if (!empty($target['username'])) {
                $insert .= " -u " . $target['username'];
            }if (!empty($target['password'])) {
                $insert .= " -p'" . $target['password'] . "'";
            }
            echo "$insert";
            system($insert);
            unlink("/tmp/dump.sql.gz");
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
        
        try {
            //test if user has access else grant
            $grants = $dbmaster->query('SHOW GRANTS FOR ' . $dbdetails['username']);
            $db = ezcDbFactory::create($dbdetails);
            $db->query('SHOW TABLES');

        } catch (\Exception $e) {
            $grant = 'GRANT ALL ON ' . $dbdetails['database'] . '.* TO ' . $dbdetails['username'] . "@'%' IDENTIFIED BY '" . $dbdetails['password'] . "'";
            $dbmaster->query($grant);
            $grant = 'GRANT ALL ON ' . $dbdetails['database'] . '.* TO ' . $dbdetails['username'] . "@'localhost' IDENTIFIED BY '" . $dbdetails['password'] . "'";
            $dbmaster->query($grant);
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
