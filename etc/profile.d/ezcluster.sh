#!/bin/sh

if [ ! -d ~/.subversion ]; then
mkdir ~/.subversion
cat <<EOL > ~/.subversion/servers

EOL

cat <<EOL > ~/.subversion/config
[auth]
store-passwords = no
store-auth-creds = no
EOL
fi

if [ ! -d ~/.ssh ]; then
mkdir ~/.ssh  
chmod 700 ~/.ssh
fi

if [ ! -f ~/.ssh/authorized_keys ]; then
touch ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
fi

if [ -f ~/.awssecret ]; then
rm -Rf ~/.awssecret
fi

if [ -f /etc/ezcluster/ezcluster.xml ]; then
sed -n 's|^.*access_key="\([^"]*\)".*$|\1|p' /etc/ezcluster/ezcluster.xml > ~/.awssecret
sed -n 's|^.*secret_key="\([^"]*\)".*$|\1|p' /etc/ezcluster/ezcluster.xml >> ~/.awssecret
chmod 600 ~/.awssecret
fi

export EC2_PRIVATE_KEY="/etc/ezcluster/aws.pem"
export EC2_CERT="/etc/ezcluster/aws.cert"
