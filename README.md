Build Packages


# renew travis token

yum -y install ruby-devel
gem install travis

/usr/local/bin/travis login --org
/usr/local/bin/travis encrypt-file ~/.config/copr .copr.enc

# Install package from copr

yum install -y dnf dnf-plugins-core
dnf install copr-cli 
dnf copr enable xrow/repository
Fails with:
No such command: copr. Please use /bin/dnf --help
It could be a DNF plugin command.
