#!/bin/sh
PWD=$(pwd)
mysqlauth=" -h ${DATABASE_SERVER} -u ${DATABASE_USER} -p${DATABASE_PASSWORD} "
source ./cache.sh
source ./clean.sh
rm -Rf ezpublish_legacy/settings/override/*
rm -Rf ezpublish_legacy/settings/siteaccess/*
rm -Rf ezpublish/config/ezpublish.yml
rm -Rf ezpublish/config/ezpublish_dev.yml
rm -Rf ezpublish/config/ezpublish_prod.yml
mysql ${mysqlauth} -e"drop database ${DATABASE_NAME}"
mysql ${mysqlauth} -e"create database ${DATABASE_NAME}"

find {ezpublish/{cache,logs,config,session},ezpublish_legacy/{design,extension,settings,var},web} -type d | sudo xargs chmod -R 777
find {ezpublish/{cache,logs,config,session},ezpublish_legacy/{design,extension,settings,var},web} -type f | sudo xargs chmod -R 666

sudo /etc/init.d/httpd restart
sudo /etc/init.d/varnish restart