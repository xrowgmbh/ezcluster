<?php
namespace xrow\eZCluster\Abstracts;

abstract class xrowCloudInfoBase
{
    public static $kernels = array();
    public static function factory()
    {
    	return false;
    }
    public static function getInstanceID()
    {
    	return false;
    }
}