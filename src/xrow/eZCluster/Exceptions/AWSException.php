<?php

namespace xrow\eZCluster\Exceptions;

class AWSException extends \Exception
{
    public function __construct( CFResponse $response )
    {
        $text = '';
        foreach( $response->body->Errors as $error)
        {
            $text .= (string) $error->Error->Type . "::" . (string) $error->Error->Code . ": " . (string) $error->Error->Message . "\n";
        }
        parent::__construct( $text );
    }

    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
