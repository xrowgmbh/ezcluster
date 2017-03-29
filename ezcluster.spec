%global commit      917a5e1a5df2e0e1e74da341dfed57c18dbe6c5d
%global shortcommit %(c=%{commit}; echo ${c:0:7})

Name: ezcluster
Summary: The eZ Cluster of the xrow GmbH
Version: 2.0
Release: 0.%{shortcommit}%{?dist}.1
License: GPL
Group: Applications/Webservice

Vendor: xrow GmbH
Packager: Bjoern Dieding / xrow GmbH <bjoern@xrow.de>

URL:            https://github.com/xrowgmbh/ezcluster
Source0:        https://github.com/xrowgmbh/ezcluster/archive/%{commit}.tar.gz

BuildRequires: centos-release-scl
BuildRequires: scl-utils
BuildRequires: rh-php56 rh-php56-php-cli rh-php56-php-common
BuildRequires: epel-release
BuildRequires: composer
# mlocate will crawl /mnt/nas
Conflicts: mlocate
Conflicts: mod_ssl
Requires: httpd nfs-utils nfs4-acl-tools sudo autofs
Requires: selinux-policy
Requires(pre): /usr/sbin/useradd
Requires(postun): /usr/sbin/userdel

BuildRoot: %{_tmppath}/%{name}
BuildArch: noarch
%description
A rapid web application setup tool

%prep
%autosetup -n %{name}-%{commit}

%build
scl enable rh-php56 bash
composer install

%install

install -m 755 -d %{buildroot}%{_bindir}
install -m 755 -d %{buildroot}%{_datadir}/%{name}
cp -R * $RPM_BUILD_ROOT%{_datadir}/%{name}
install -m 777 -d %{buildroot}/var/www/sites
chmod +x $RPM_BUILD_ROOT%{_datadir}/%{name}/%{name}
ln -s ../%{name} %{buildroot}%{_bindir}/%{name}
cp -R etc $RPM_BUILD_ROOT%{_sysconfdir}

%files
%defattr(644,root,root,755)
%{_sysconfdir}/*
%dir %{_sysconfdir}/httpd/sites    
%{_datadir}/*
%{_bindir}/*
%exclude %{_datadir}/bin
%attr(755, root, root) %{_datadir}/%{name}/bin/*
%attr(777, root, root) /var/www/sites
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
