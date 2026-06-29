#!/bin/bash

################################################
#
# Verify a file signature with openssl.
# The input file is the file from which the
# signature has been generated. Next to this
# file, the file containing the signature must be
# present and carry the same name plus the extension
# ".sig". Also, the public certificate corresponding
# to the key used to sign the file must be present
# next to the file - and carry the name usercert.pem.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -r UTIL/OpenSSL
#GRIDFACTORY -n VERIFY-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

# This would get the current user's usercert.pem - we need the one
# corresponding to the key that signed the file.
#curl --insecure HOME_SERVER_PRIVATE_URL/remote.php/getcert?user=SD_USER | jq -r .data.certificate > usercert.pem

# Fetch the signature file
curl --insecure "IN_FILE_URL.sig" > "IN_FILENAME_RAW.sig"

# Fetch the public certificate
curl --insecure "IN_FOLDER_URL/usercert.pem" > "usercert.pem"

# Extract public key
openssl x509 -pubkey -in usercert.pem -nocert > pubkey.pem

# Verify signature
openssl dgst -sha256 -verify pubkey.pem -signature "IN_FILENAME_RAW.sig" "IN_FILENAME_RAW"

