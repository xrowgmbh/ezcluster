Name: ezcluster
Summary: The eZ Cluster of the xrow GmbH
Version: 2.0
Release: 83
License: GPL
Group: Applications/Webservice
URL: http://packages.xrow.com/redhat
Distribution: Linux
Vendor: xrow GmbH
Packager: Bjoern Dieding / xrow GmbH <bjoern@xrow.de>
BuildRequires: libxslt git
Requires: yum
Requires: mariadb-server mariadb
# mlocate will crawl /mnt/nas
Conflicts: mod_ssl
Requires: httpd haproxy nfs-utils nfs4-acl-tools sudo autofs
Requires: selinux-policy yum-cron
Requires: libxslt-devel
Requires: varnish >= 4.0
Requires(pre): /usr/sbin/useradd
Requires(postun): /usr/sbin/userdel
# Wait till composer package exists
# BuildRequires: /usr/bin/composer

BuildRoot: %{_tmppath}/%{name}-root
BuildArch: noarch
%description

%install
rm -rf $RPM_BUILD_ROOT

git clone git@github.com:xrowgmbh/ezcluster.git $RPM_BUILD_ROOT%{_datadir}/ezcluster
git --git-dir $RPM_BUILD_ROOT%{_datadir}/ezcluster/.git config core.filemode false
find $RPM_BUILD_ROOT%{_datadir}/ezcluster -name ".keep" -delete
cp -R $RPM_BUILD_ROOT%{_datadir}/ezcluster/etc $RPM_BUILD_ROOT%{_sysconfdir}
git --git-dir $RPM_BUILD_ROOT%{_datadir}/ezcluster/.git stash

/usr/bin/composer update -d $RPM_BUILD_ROOT%{_datadir}/ezcluster
#for f in $RPM_BUILD_ROOT%{_datadir}/ezcluster/schema/*.xsd
#do
#	xsltproc --stringparam title "eZ Cluster XML Schema" \
#                 --output $f.html $RPM_BUILD_ROOT%{_datadir}/ezcluster/build/xs3p/xs3p.xsl $f
#done

#del unneeded
rm -Rf $RPM_BUILD_ROOT%{_datadir}/ezcluster/drafts
rm -Rf $RPM_BUILD_ROOT%{_datadir}/ezcluster/build

mkdir -p $RPM_BUILD_ROOT/var/www/sites
mkdir -p $RPM_BUILD_ROOT/mnt/storage
mkdir -p $RPM_BUILD_ROOT/mnt/nas

mkdir $RPM_BUILD_ROOT%{_bindir}
cp $RPM_BUILD_ROOT%{_datadir}/ezcluster/ezcluster $RPM_BUILD_ROOT%{_bindir}/ezcluster

chmod +x $RPM_BUILD_ROOT%{_datadir}/ezcluster/ezcluster
chmod +x $RPM_BUILD_ROOT%{_bindir}/ezcluster

%files
%defattr(644,root,root,755)
%{_sysconfdir}/httpd/conf.d/xrow.conf
%{_sysconfdir}/httpd/conf.d/ezcluster.conf
%{_sysconfdir}/logrotate.d/ezcluster
%{_sysconfdir}/profile.d/ezcluster.sh
%{_sysconfdir}/ezcluster/ezcluster.xml.dist
%{_sysconfdir}/httpd/sites/environment.conf
%{_sysconfdir}/cloud/cloud.cfg.d/ezcluster.cfg
%dir %{_sysconfdir}/httpd/sites    
%{_datadir}/ezcluster/*
%{_datadir}/ezcluster/.git*
%attr(755, root, root) %{_bindir}/*
%attr(755, root, root) %{_datadir}/ezcluster/bin/tools/*
%attr(755, root, root) %{_datadir}/ezcluster/bin/ezcluster
%attr(777, root, root) /var/www/sites
%attr(644, root, root) %{_sysconfdir}/systemd/system/ezcluster.service
%attr(440, root, root) %{_sysconfdir}/sudoers.d/ezcluster
%dir /mnt/nas
%dir /mnt/storage

%pre

# delete obsolete group
grep "^ec2-user:" /etc/group &> /dev/null
if [ $? -eq "0" ]; then
    groupdel ec2-user
fi
grep "^ec2-user:" /etc/passwd &> /dev/null
if [ $? -ne "0" ]; then
    useradd -m -u 222 -g apache -c "Cloud Default User" ec2-user
fi
usermod -g apache ec2-user

%post

if [ "$1" -eq "1" ]; then

#logrotate
sed -i "s/weekly/daily/g" /etc/logrotate.conf
sed -i "s/#compress/compress/g" /etc/logrotate.conf

#sed -i "s/PACKAGE_SETUP=yes/PACKAGE_SETUP=no/g" /etc/sysconfig/cloud-init

sed -i "s/^Defaults    requiretty/#Defaults    requiretty/g" /etc/sudoers

systemctl enable ezcluster.service

fi

rm -Rf /tmp/.compilation/

%preun

if [ "$1" -eq "0" ]; then
   sed -i "s/locking_type = 3/locking_type = 1/g" /etc/lvm/lvm.conf
   sed -i "s/daily/weekly/g" /etc/logrotate.conf
   sed -i "s/compress/#compress/g" /etc/logrotate.conf   
   sed -i "s/0.0.0.0/127.0.0.1/g" /usr/share/ezfind/etc/jetty.xml
   sed -i "s/^LogFormat \"%{X-Forwarded-For}i/LogFormat \"%h/g" /etc/httpd/conf/httpd.conf
   sed -i "s/^#Defaults    requiretty/Defaults    requiretty/g" /etc/sudoers
   systemctl stop ezcluster.service
   systemctl disable ezcluster.service
fi


%postun
 
if [ "$1" -eq "0" ]; then
   /usr/sbin/userdel ec2-user
fi
rm -Rf /tmp/.compilation/

%clean
rm -rf $RPM_BUILD_ROOT