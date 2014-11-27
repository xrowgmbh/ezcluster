<?php
namespace xrow\eZCluster\Abstracts;

abstract class xrowResource
{
    public $id;

    function __construct( $id = null )
    {
        if ( $id )
        {
            $this->id = $id;
        }
        else
        {
            throw new Exception( "Recource ID not given" );
        }
    }

    public function __toString()
    {
        return $this->id;
    }
}