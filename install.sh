#!/bin/bash
TMPFILE=`mktemp`
PWD=`pwd`
wget -qO $TMPFILE "https://php-download.com"$(wget -qO - "https://php-download.com/downloads/?version=0.4.0.0&package=tumblr&vendor=tumblr&download_type=REQUIRE" | sed -ne "s/^.*window.location.href = '\(.*\)';.*$/\1/p")
unzip -qq -d $PWD $TMPFILE "*.php" -x "index.php"
rm $TMPFILE

mkdir -p ./js
wget -qP ./js/ "https://code.jquery.com/jquery-3.3.1.min.js"
