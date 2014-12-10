#!/bin/sh
PWD=$(pwd)
if [ ! ${DATABASE_PASSWORD} ]; then              
    mysqlauth=" -h ${DATABASE_SERVER} -u ${DATABASE_USER} "                       
else
    mysqlauth=" -h ${DATABASE_SERVER} -u ${DATABASE_USER} -p${DATABASE_PASSWORD} " 
fi
rm -Rf ezpublish_legacy/settings/override ezpublish_legacy/settings/siteaccess ezpublish_legacy/var/ezdemo_site/log ezpublish_legacy/var/ezdemo_site/cache

if [ ! ${VERSION} ]; then
  wget --no-check-certificate -O dump.zip https://github.com/xrowgmbh/xrowvagrant-demodata/archive/master.zip
  unzip -o -d $PWD dump.zip
  cp -r xrowvagrant-demodata-master/* .
  rm -Rf xrowvagrant-demodata-master
else
  wget --no-check-certificate -O dump.zip https://github.com/xrowgmbh/xrowvagrant-demodata/archive/${VERSION}.zip
  unzip -o -d $PWD dump.zip
  cp -r xrowvagrant-demodata-${VERSION}/* .
  rm -Rf xrowvagrant-demodata-${VERSION}
fi

rm -Rf ezpublish_legacy/var/ezdemo_site/log/ ezpublish_legacy/var/ezdemo_site/cache/
mysql ${mysqlauth} -e"CREATE DATABASE IF NOT EXISTS ${DATABASE_NAME}"
mysql ${mysqlauth} ${DATABASE_NAME} < ezpublish_legacy/var/ezdemo_site/dump.sql
rm -Rf dump.zip

cd ezpublish_legacy && php bin/php/ezpgenerateautoloads.php && cd ..
source /usr/share/ezcluster/bin/tools/fixpermissions.sh
# Bug https://project.issues.ez.no/IssueView.php?Id=12514&activeItem=1
echo "For solr indexing run:"
echo "php ezpublish/console ezpublish:legacy:script --env=dev -n extension/ezfind/bin/php/updatesearchindexsolr.php"