#!/bin/bash

mkdir doc 2> /dev/null

echo "If you get an error here, you might not have phpdoc installed"

phpdoc -f mcquery.class.php -t doc