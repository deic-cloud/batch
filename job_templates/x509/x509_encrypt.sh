#!/bin/bash
# Title: Encrypt a file to your certificate
# Category: Signing & encryption
# Summary: Encrypts a file so only your private key can decrypt it (any size).
# Output: <name>.enc in Batch/output_files

################################################
#
# Encrypt a file with openssl to your ScienceData
# X.509 certificate (CMS/S-MIME envelope: AES-256
# for the data, your RSA key for the AES key).
# Files of any size can be encrypted.
# Your certificate is written into the job when you
# submit it, so any worker can run this job; only
# your private key can decrypt the result (see the
# x509_decrypt template).
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -o IN_FILENAME.enc WORK_FOLDER_URL/output_files/IN_FILENAME.enc
#GRIDFACTORY -r UTIL/OpenSSL
#GRIDFACTORY -n ENCRYPT-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

# Your public certificate
cat > usercert.pem <<'CERT'
MY_SSL_CERT
CERT

# Encrypt
openssl cms -encrypt -binary -aes256 -outform DER -in "IN_FILENAME_RAW" -out "IN_FILENAME.enc" usercert.pem
