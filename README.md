Build Packages


# renew travis token

yum -y install ruby-devel
gem install travis
yum -y install copr

/usr/local/bin/travis login --org
/usr/local/bin/travis encrypt-file ~/.config/copr .copr.enc

