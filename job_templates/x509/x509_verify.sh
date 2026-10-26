#!/bin/bash
# Title: Verify a file's signature
# Category: Signing & encryption
# Summary: Checks a file against its .sig and the signer's usercert.pem in the same folder.
# Output: the result in the job's output (stdout)

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
#GRIDFACTORY -i IN_FILE_URL.sig
#GRIDFACTORY -i IN_FOLDER_URLusercert.pem
#GRIDFACTORY -r UTIL/OpenSSL
#GRIDFACTORY -n VERIFY-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

# The signature file and the signer's certificate are staged in next to
# the input file by the -i directives above.

# Extract public key
openssl x509 -pubkey -in usercert.pem -nocert > pubkey.pem

# Verify signature
openssl dgst -sha256 -verify pubkey.pem -signature "IN_FILENAME_RAW.sig" "IN_FILENAME_RAW"

