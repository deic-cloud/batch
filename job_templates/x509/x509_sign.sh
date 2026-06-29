#!/bin/bash

################################################
#
# Generate a file signature with openssl using your
# ScienceData RSA private key.
# Notice: This template has the batch directive -v MY_SSL_DN,
# implying that it will only be picked up by worker pods
# you yourself start.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -o IN_FILENAME.sig WORK_FOLDER_URL/output_files/IN_FILENAME.sig
#GRIDFACTORY -r UTIL/OpenSSL
#GRIDFACTORY -n SIGN-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#GRIDFACTORY -v MY_SSL_DN
#
################################################

# Fetch my private key
curl --insecure HOME_SERVER_PRIVATE_URL/remote.php/getkey | jq -r .data.private_key > userkey_unenc.pem

# Sign
openssl dgst -sha256 -sign userkey_unenc.pem -out "IN_FILENAME.sig" "IN_FILENAME_RAW"
