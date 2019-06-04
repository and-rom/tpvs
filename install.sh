#!/bin/bash
TMPFILE=`mktemp`
PWD=`pwd`
wget -qO $TMPFILE "https://php-download.com"$(wget -qO - "https://php-download.com/downloads/download?vendor=tumblr&package=tumblr&version=0.4.0.0&downloadType=REQUIRE" | sed -ne "s/^.*window.location.href = '\(.*\)';.*$/\1/p")
unzip -qq -d $PWD $TMPFILE "*.php" -x "index.php"
rm $TMPFILE

mkdir -p ./js
wget -qP ./js/ "https://code.jquery.com/jquery-3.3.1.min.js"

wget -qP ./js/ "https://raw.githubusercontent.com/js-cookie/js-cookie/master/src/js.cookie.js"
