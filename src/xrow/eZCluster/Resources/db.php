<?php
namespace xrow\eZCluster\Resources;

use xrow\eZCluster\Resources;
use xrow\eZCluster\Abstracts;
use xrow\eZCluster;
use Ssh;
use \ezcDbFactory;
use \ezcDbInstance;

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

    static public function migrateDatabase($dsnsource, $dsntarget, $session, $where = false)
    {
        $source = self::translateDSN($dsnsource);
        if (! $session) {
            $target = self::translateDSN($dsntarget);
            $dumpname = "/var/tmp/dump." . $source['database'] .".sql.gz";
            $dump = "mysqldump --compress --default-character-set=utf8 --opt --single-transaction --add-drop-table --add-drop-database -h " . $source['hostspec'] . " -u " . $source['username'];
            if (! empty($source['password'])) {
                $dump .= " -p'" . $source['password'];
            }
            if (! empty($source['port'])) {
                $dump .= " --port=" . $source['port'];
            }
            $dump .= "' --databases " . $source['database'] . " | gzip > " . $dumpname;
            system($dump);
            $insert = "gunzip < " . $dumpname . " | mysql --compress -h" . $target['hostspec'] . " -u " . $target['username'];
            if (! empty($target['password'])) {
                $insert .= " -p'" . $target['password'] . "'";
            }
            if ( $where )
            {
                $dump .= " --where=\"$where\"";
            }
            system($insert);
            unlink( $dumpname );
        } else {
            $exec = $session->getExec();
            $dumpname = "/var/tmp/dump." . $source['database'] .".sql.gz";
            $dump = "mysqldump --compress --default-character-set=utf8 --opt --single-transaction --add-drop-table --add-drop-database -h " . $source['hostspec'];
            if (!empty($source['username'])) {
                $dump .= " -u " . $source['username'];
            }
            if (!empty($source['password'])) {
                $dump .= " -p'" . $source['password']."'";
            }
            if (! empty($source['port'])) {
                $dump .= " --port=" . $source['port'];
            }
            if ( $where )
            {
                $dump .= " --where=\"$where\"";
            }
            $dump .= " --databases " . $source['database'] . " | gzip > " . $dumpname;
            # Test for mysqldump on target server
            try {
                $exec->run("mysqldump --help");
            } catch (Exception $e) {
                echo "There was a problem executing mysqldump on target server";
                throw $e;
            }
            $exec->run($dump);
            $sftp = $session->getSftp();
            $sftp->receive( $dumpname, $dumpname);
            $sftp->unlink( $dumpname );
            $target = db::translateDSN($dsntarget);
            
            $insert = "gunzip < ".$dumpname." | mysql --compress -h" . $target['hostspec'];
            if (!empty($target['username'])) {
                $insert .= " -u " . $target['username'];
            }if (!empty($target['password'])) {
                $insert .= " -p'" . $target['password'] . "'";
            }
            echo "$insert";
            system($insert);
            unlink( $dumpname );
        }
    }

    public static function initDB($dsn)
    {
        $dbdetails= ezcDbFactory::parseDSN($dsn);
        try {
            $dbmaster = ezcDbFactory::create($dbdetails);
        } catch (\Exception $e) {
            
            $rootdsn = 'mysql://root@localhost';
            $rootdetails = ezcDbFactory::parseDSN($rootdsn);
            try {
                $dbroot = ezcDbFactory::create($rootdetails);
            } catch (\Exception $e) {
                return false;
            }
            $dbdetails = ezcDbFactory::parseDSN($dsn);
            $dbdetails['database'] = str_replace( ".", "_", $dbdetails['database']);
            if ( !isset( $GLOBALS["database"]["users"][$dbdetails['username']] ) ){
                $GLOBALS["database"]["users"][$dbdetails['username']] = $dbdetails['password'];
            }
            if( $GLOBALS["database"]["users"][$dbdetails['username']] != $dbdetails['password'] ){
                throw new \Exception( "Database user " . $dbdetails['username'] . " with different password found." );
            }
            
            //test if user has access else grant
            try {
                $grants = $dbmaster->query('SHOW GRANTS FOR ' . $dbdetails['username']);
            } catch (\Exception $e) {
                $grants = false;
            }
            if ( is_object( $grants ) and $grants->rowCount() > 0 ){
                foreach( $grants->fetchAll() as $grant ){
                    $match = false;
                    if ( isset( $grant['grants for ' .  $dbdetails['username'] . '@%'] ) and $grant['grants for ' .  $dbdetails['username'] . '@%'] == "GRANT ALL PRIVILIEGES ON `" . $dbdetails['database'] . "`.* TO " . $dbdetails['username'] . "@'%'" ){
                        $match = true;
                    }
                    $grants = false;
                }
                if (!$match){
                    $grants = false;
                }
            }
            if( $grants === false or ( is_object( $grants ) and $grants->rowCount() === 0 ) )
            {
                ezcDbInstance::set($dbroot);
                $grant = 'GRANT ALL ON ' . $dbdetails['database'] . '.* TO ' . $dbdetails['username'] . "@'%' IDENTIFIED BY '" . $dbdetails['password'] . "'";
                $dbroot->query($grant);
                $grant = 'GRANT ALL ON ' . $dbdetails['database'] . '.* TO ' . $dbdetails['username'] . "@'localhost' IDENTIFIED BY '" . $dbdetails['password'] . "'";
                $dbroot->query($grant);
            }

            $dbroot->query("SET NAMES utf8");
            $rows = $dbroot->query('SHOW DATABASES');
            $dbs_exists = array();
            foreach ($rows as $row) {
                if (! in_array($row, array(
                    'mysql',
                    'information_schema'
                ))) {
                    $dbs_exists[] = $row['database'];
                }
            }
            if (! in_array($dbdetails['database'], $dbs_exists)) {
                $dbroot->query('CREATE DATABASE IF NOT EXISTS ' . $dbdetails['database'] . ' CHARACTER SET utf8 COLLATE utf8_general_ci');
            }
        }
        try {
            $dbmaster = ezcDbFactory::create($dbdetails);
        } catch (Exception $e) {
            echo "can`t connect to $dsn";
            return false;
        }

        ezcDbInstance::set($dbmaster);
        
        // test DB
        if ( $dbdetails['hostspec'] == "localhost" ){
            $db = ezcDbFactory::create($dbdetails);
            $db->query('SHOW TABLES');
        }

    }

    public static function translateDSN($dsn)
    {
        $dbdetails = ezcDbFactory::parseDSN($dsn);
        $dbdetails['database'] = str_replace( ".", "_", $dbdetails['database']);
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
