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

grep_output="$(grep -i 'service\@xrow.de' ~/.ssh/authorized_keys)"
if [ -z "$grep_output" ]; then
echo "ssh-rsa AAAAB3NzaC1yc2EAAAABIwAAAIEAmKtOFjv/OLjzPP7VyjndOJJvxfzOIEfhJ+FXhiUVTOFFdTMXV2si0rqL3I8ot2mwM8bpeqvQr5zfng0CPOxl8ydkPsRY2qflyKWO19/nV3R/R5z29P+DgyQgfAiK5gbh2mMgdRkLn0MmE2GULKu7OGPUXIgRJpUTBVziySMAcSU= service@xrow.de" >> ~/.ssh/authorized_keys
fi

if [ -f ~/.awssecret ]; then
rm ~/.awssecret
fi

if [ -f /etc/ezcluster/ezcluster.xml ]; then
sed -n 's|^.*access_key="\([^"]*\)".*$|\1|p' /etc/ezcluster/ezcluster.xml > ~/.awssecret
sed -n 's|^.*secret_key="\([^"]*\)".*$|\1|p' /etc/ezcluster/ezcluster.xml >> ~/.awssecret
chmod 600 ~/.awssecret
fi

export EC2_PRIVATE_KEY="/etc/ezcluster/aws.pem"
export EC2_CERT="/etc/ezcluster/aws.cert"