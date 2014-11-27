<?php
namespace xrow\eZCluster;

use xrow\eZCluster\Abstracts;

class CloudInfo extends Abstracts\xrowCloudInfoBase
{
    public static $kernels = array();
    public static function getInstanceID()
    {
    	return "localhost";
    }
}