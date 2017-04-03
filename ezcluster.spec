%global _commit fb0568670d1127233aefba3028b7a5b28cd16d31
%global shortcommit %(c=%{_commit}; echo ${c:0:7})

Name: ezcluster
Summary: The eZ Cluster of the xrow GmbH
Version: 2.2.9
Release: 11.%{shortcommit}%{?dist}
License: GPL
Group: Applications/Webservice

Vendor: xrow GmbH
Packager: Bjoern Dieding / xrow GmbH <bjoern@xrow.de>

URL:            https://github.com/xrowgmbh/ezcluster
Source0:        https://github.com/xrowgmbh/ezcluster/archive/%{_commit}.tar.gz

BuildRequires: php-cli
BuildRequires: epel-release
BuildRequires: composer
BuildRequires: mariadb
# mlocate will crawl /mnt/nas
Conflicts: mlocate
Conflicts: mod_ssl
Requires: httpd
Requires: nfs-utils nfs4-acl-tools sudo autofs
Requires: selinux-policy
Requires: cronie
Requires(pre): /usr/sbin/useradd
Requires(postun): /usr/sbin/userdel

BuildRoot: %{_tmppath}/%{name}
BuildArch: noarch
%description
A rapid web application setup tool

%prep
%autosetup -n %{name}-%{_commit}

%build
php /usr/bin/composer install --ignore-platform-reqs

%install

install -m 755 -d %{buildroot}%{_bindir}
install -m 755 -d %{buildroot}%{_datadir}/%{name}
cp -R * $RPM_BUILD_ROOT%{_datadir}/%{name}
install -m 777 -d %{buildroot}/var/www/sites
install -m 777 -d %{buildroot}/var/log/ezcluster
chmod +x $RPM_BUILD_ROOT%{_datadir}/%{name}/%{name}
ln -s ../..%{_datadir}/%{name}/%{name} %{buildroot}%{_bindir}/%{name} 
cp -R etc $RPM_BUILD_ROOT%{_sysconfdir}
install -m 777 -d %{buildroot}/var/www/html
cp html/index.php $RPM_BUILD_ROOT/var/www/html/index.php

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
%{_datadir}/*
%{_bindir}/*
%exclude %{_datadir}/bin
%attr(777, root, root) %{_sysconfdir}/httpd/sites
%attr(755, root, root) %{_datadir}/%{name}/bin/*
%attr(755, root, root) %{_datadir}/%{name}/%{name}
%attr(755, root, apache) /var/www/html/index.php
%attr(770, root, apache) /var/www/sites
%attr(777, root, root) /var/log/ezcluster
%attr(644, root, root) %{_sysconfdir}/systemd/system/ezcluster.service
%attr(440, root, root) %{_sysconfdir}/sudoers.d/ezcluster
%doc README.md

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
sed -i "s/^Defaults    requiretty/#Defaults    requiretty/g" /etc/sudoers

systemctl enable ezcluster.service

fi

rm -Rf /tmp/.compilation/

%preun

if [ "$1" -eq "0" ]; then
   sed -i "s/daily/weekly/g" /etc/logrotate.conf
   sed -i "s/compress/#compress/g" /etc/logrotate.conf   
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
