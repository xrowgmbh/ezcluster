%global commit      00019506c309226f08abb407f784fb405fc04fc7
%global shortcommit %(c=%{commit}; echo ${c:0:7})

Name: ezcluster
Summary: The eZ Cluster of the xrow GmbH
Version: 2.0
Release: 0.%{shortcommit}%{?dist}.1
License: GPL
Group: Applications/Webservice

Distribution: Linux
Vendor: xrow GmbH
Packager: Bjoern Dieding / xrow GmbH <bjoern@xrow.de>

URL:            https://github.com/xrowgmbh/ezcluster
Source0:        https://github.com/xrowgmbh/ezcluster/archive/%{commit}.tar.gz

BuildRequires: libxslt git
Requires: yum
Requires: mariadb-server mariadb
# mlocate will crawl /mnt/nas
Conflicts: mlocate
Conflicts: mod_ssl
Requires: httpd haproxy nfs-utils nfs4-acl-tools sudo autofs
Requires: selinux-policy yum-cron
Requires: libxslt-devel
Requires: varnish >= 4.0
Requires(pre): /usr/sbin/useradd
Requires(postun): /usr/sbin/userdel

BuildRoot: %{_tmppath}/%{name}
BuildArch: noarch
%description
A rapid app setup tools

%prep
%autosetup -n %{name}-%{commit}
mkdir -p $RPM_BUILD_ROOT%{_datadir}/%{name}
cp -R * $RPM_BUILD_ROOT%{_datadir}/%{name}/.

%install
#rm -rf $RPM_BUILD_ROOT
#git clone git@github.com:xrowgmbh/ezcluster.git $RPM_BUILD_ROOT%{_datadir}/%{name}
#git --git-dir $RPM_BUILD_ROOT%{_datadir}/ezcluster/.git config core.filemode false
#find $RPM_BUILD_ROOT%{_datadir}/ezcluster -name ".keep" -delete
cp -R $RPM_BUILD_ROOT%{_datadir}/ezcluster/etc $RPM_BUILD_ROOT%{_sysconfdir}


#/usr/bin/composer update -d $RPM_BUILD_ROOT%{_datadir}/%{name}
#for f in $RPM_BUILD_ROOT%{_datadir}/ezcluster/schema/*.xsd
#do
#	xsltproc --stringparam title "eZ Cluster XML Schema" \
#                 --output $f.html $RPM_BUILD_ROOT%{_datadir}/ezcluster/build/xs3p/xs3p.xsl $f
#done

mkdir -p $RPM_BUILD_ROOT/var/www/sites

mkdir $RPM_BUILD_ROOT%{_bindir}
cp $RPM_BUILD_ROOT%{_datadir}/%{name}/%{name} $RPM_BUILD_ROOT%{_bindir}/%{name}

chmod +x $RPM_BUILD_ROOT%{_datadir}/%{name}/%{name}
chmod +x $RPM_BUILD_ROOT%{_bindir}/%{name}

%files
%defattr(644,root,root,755)
%{_sysconfdir}/httpd/conf.d/xrow.conf
%{_sysconfdir}/httpd/conf.d/%{name}.conf
%{_sysconfdir}/logrotate.d/%{name}
%{_sysconfdir}/profile.d/%{name}.sh
%{_sysconfdir}/ezcluster/%{name}.xml.dist
%{_sysconfdir}/httpd/sites/environment.conf
%{_sysconfdir}/cloud/cloud.cfg.d/%{name}.cfg
%dir %{_sysconfdir}/httpd/sites    
%{_datadir}/%{name}/*
%{_datadir}/%{name}/.git*
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