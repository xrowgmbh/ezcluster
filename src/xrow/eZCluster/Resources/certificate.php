<?php
namespace xrow\eZCluster\Resources;

use xrow\eZCluster\Abstracts;

class certificate extends Abstracts\xrowEC2Resource
{
    function __construct( $id )
    {   
        if ( ! $id )
        {
            throw new Exception( "Certificate '$id' not avialable." );
        }

        $this->id = $id;
    }

    public function delete()
    {
        unlink( "/etc/ssl/certs/".$this->id.".pem" );
    }

    static public function create( $name, $certificate_body, $private_key, $crt_chain = null )
    {
        file_put_contents( "/etc/ssl/certs/".$name.".pem", $certificate_body . "\n" . $private_key ."\n". $crt_chain );
        $cert = new self( $name );
        return $cert;
    }
}