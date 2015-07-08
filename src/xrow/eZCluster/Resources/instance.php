<?php
namespace xrow\eZCluster\Resources;

use xrow\eZCluster\Abstracts;
use xrow\eZCluster\CloudInfo;
use xrow\eZCluster\CloudSDK;

class instance extends Abstracts\xrowEC2Resource
{

    function __construct($id = null)
    {
        if ($id === null) {
            $this->id = CloudInfo::getInstanceID();
            if ($this->describe() === null) {
                throw new Exception('can`t find instance');
            }
        } else {
            $this->id = $id;
            if ($this->describe() === null) {
                throw new Exception('can`t find instance');
            }
        }
    }

    static public function byName($name)
    {
        return self::getInstance((string) $name);
    }

    public static function getInstance($id = null)
    {
        $class = get_called_class();
        return new $class($id);
    }
    public static function current()
    {
        $parts = explode(".", php_uname("n"), 2);
        $host = $parts[0];
        
        $result = CloudSDK::$config->xpath("/aws/cluster/instance[@name='" . $host . "']");
        
        if (isset($result[0])) {
            return new self( (string) $host );
        } else {
            // utofix if there is just one instance
            $result = CloudSDK::$config->xpath("/aws/cluster/instance");
            if (count($result) == 1) {
                if (! isset($result[0]['name'])) {
                    return new self( "localhost" );
                }
                return new self( (string) $result[0]['name'] );
            }
        }
        throw new \Exception('Host "' . $host . '" not known in ezcluster.xml');
    }
    function name()
    {
        $parts = explode(".", php_uname("n"), 2);
        $host = $parts[0];
        
        $result = CloudSDK::$config->xpath("/aws/cluster/instance[@name='" . $host . "']");
        
        if (isset($result[0])) {
            return (string) $host;
        } else {
            // utofix if there is just one instance
            $result = CloudSDK::$config->xpath("/aws/cluster/instance");
            if (count($result) == 1) {
                if (! isset($result[0]['name'])) {
                    return "localhost";
                }
                return (string) $result[0]['name'];
            }
            
            throw new \Exception('Host "' . $host . '" not known in ezcluster.xml');
        }
        return $host;
    }

    function ip()
    {
        if (isset($this->describe()->privateIpAddress)) {
            return (string) $this->describe()->privateIpAddress;
        }
        return gethostbyname(gethostname());
    }

    final function start()
    {
        $response = CloudSDK::factory()->start_instances($this->id);
        
        if (! $response->isOK()) {
            throw new xrowAWSException($response);
        }
    }

    final function reboot()
    {
        $response = CloudSDK::factory()->reboot_instances($this->id);
        
        if (! $response->isOK()) {
            throw new xrowAWSException($response);
        }
    }

    final function stop()
    {
        $response = CloudSDK::factory()->stop_instances($this->id);
        
        if (! $response->isOK()) {
            throw new xrowAWSException($response);
        }
    }

    final function terminate()
    {
        $response = CloudSDK::factory()->terminate_instances($this->id);
        
        if (! $response->isOK()) {
            throw new xrowAWSException($response);
        }
    }

    function describe()
    {
        return array();
    }
}
