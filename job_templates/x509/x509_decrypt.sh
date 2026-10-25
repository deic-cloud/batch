#!/bin/bash

################################################
#
# Decrypt a file encrypted to you with the
# x509_encrypt template.
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

# Fetch my private key
curl --insecure HOME_SERVER_PRIVATE_URL/index.php/apps/files_sharding/x509/key > userkey_unenc.pem

# Decrypt (files made with the x509_encrypt template)
openssl cms -decrypt -binary -inform DER -inkey userkey_unenc.pem -in "IN_FILENAME_RAW" -out "IN_BASENAME"
