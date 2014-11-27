<?php
namespace xrow\eZCluster\Abstracts;

use xrow\eZCluster\xrowAWSSDK;

abstract class xrowEC2Resource
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

    public function setTag( $name = false, $value = null )
    {
        if ( ! $name )
        {
            throw new Exception( "Tag name can't be empty" );
        }
        $response = xrowAWSSDK::factory()->describe_tags( array( 
            'Filter' => array( 
                array( 
                    'Name' => 'resource-id' , 
                    'Value' => $this->id 
                ) 
            ) 
        ) );
        if ( ! $response->isOK() )
        {
            throw new xrowAWSException( $response );
        }
        $tags = array();
        foreach ( $response->body->tagSet as $set )
        {
            if ( (string) $set->item->key )
            {
                $tags[] = array( 
                    'Key' => (string) $set->item->key , 
                    'Value' => (string) $set->item->value 
                );
            }
        }
        $tags[] = array( 
            'Key' => $name , 
            'Value' => $value 
        );
        $response = xrowAWSSDK::factory()->create_tags( $this->id, $tags );
        if ( ! $response->isOK() )
        {
            throw new xrowAWSException( $response );
        }
    }

    public function getTag( $name )
    {
        throw new \Exception("bla");
        $response = xrowAWSSDK::factory()->describe_tags( array( 
            'Filter' => array( 
                array( 
                    'Name' => 'resource-id' , 
                    'Value' => $this->id 
                ) , 
                array( 
                    'Name' => 'key' , 
                    'Value' => $name 
                ) 
            ) 
        ) );
        if ( ! $response->isOK() )
        {
            throw new xrowAWSException( $response );
        }
        $value = (string) $response->body->tagSet->item->value;
        return $value;
    }

    static public function factory()
    {
        return  new static;
    }
}