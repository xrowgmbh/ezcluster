<?php
namespace xrow\eZCluster\Resources;

use xrow\eZCluster\Abstracts;
use xrow\eZCluster;

class lb extends Abstracts\xrowEC2Resource
{

    function __construct( $id )
    {
        $this->id = $id;
    }

    public function deregister( instance $instance )
    {
        return true;
    }

    public function register( instance $instance )
    {
    	
    }
    public static function current()
    {
        $result = eZCluster\CloudSDK::$config->xpath( "/aws/cluster/instance[@name='" . instance::current()->name() . "']/parent::*" );
        if ( isset( $result[0]['lb'] ) )
        {
            return new lb( (string) $result[0]['lb'] );
        }
        throw new \Exception('LB for "' . instance::current()->name() . '" not known in ezcluster.xml');
    }
    public function setCertificate( certificate $cert )
    {

    }

    static public function exists( $name )
    {
        try
        {
            $lb = new lb( $name );
            return true;
        }
        catch ( \Exception $e )
        {
            return false;
        }
    }

    static public function create( $name = null, $availability_zones = null, certificate $cert = null )
    {

    }
}

