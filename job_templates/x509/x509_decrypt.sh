#!/bin/bash

################################################
#
# Decrypt a file encrypted to you with openssl.
# Notice: This template has the batch directive -v MY_SSL_DN,
# implying that it will only be picked up by worker pods
# you yourself start.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -o IN_BASENAME WORK_FOLDER_URL/output_files/IN_BASENAME
#GRIDFACTORY -r UTIL/OpenSSL
#GRIDFACTORY -n DECRYPT-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#GRIDFACTORY -v MY_SSL_DN
#
################################################

# Fetch my pricate key
curl --insecure HOME_SERVER_PRIVATE_URL/remote.php/getkey | jq -r .data.private_key > userkey_unenc.pem

# Decrypt
openssl pkeyutl -decrypt -inkey userkey_unenc.pem -in "IN_FILENAME_RAW" -out "IN_BASENAME"


