#!/bin/sh
PWD=$(pwd)

rm -Rf ezpublish_legacy/var/cache/*
rm -Rf ezpublish/cache/*
rm -Rf ezpublish_legacy/var/ezdemo_site/cache/*
rm -Rf ezpublish_legacy/var/storage/packages/*
rm -Rf ezpublish_legacy/settings/override/*.php~
rm -Rf ezpublish_legacy/settings/siteaccess/*/*.php~
rm -Rf web/var/cache/*
if [ -e ezpublish/console ]; then 
 php ezpublish/console ezpublish:legacy:script bin/php/ezcache.php --allow-root-user --clear-all
 php ezpublish/console cache:clear
fi
