#!/bin/bash

################################################
#
# Sign a PDF file using your ScienceData RSA private key.
# The signed file will be delivered back next to your
# input file - with ".sig" appended to the basename.
# E.g. an input file "myfile.pdf" will result in a file
# "myfile.signed.pdf" delivered next to the input file.
# Notice: This template has the batch directive -v MY_SSL_DN,
# implying that it will only be picked up by worker pods
# you yourself start.
# Notice also: The signature will not be visible when
# previewing on ScienceData, but will be visible
# when downloading and opening the signed pdf with
# a full-featured PDF reader.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -r UTIL/PDFSign
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -o IN_BASENAME.signed.pdf IN_FOLDER_URL/IN_BASENAME.signed.pdf
#GRIDFACTORY -n PDFSIGN-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#GRIDFACTORY -v MY_SSL_DN
#
################################################

# Fetch my private key
curl --insecure HOME_SERVER_PRIVATE_URL/remote.php/getkey | jq -r .data.private_key > userkey_unenc.pem

# Fetch public certificate
curl --insecure HOME_SERVER_PRIVATE_URL/remote.php/getcert?user=SD_USER | jq -r .data.certificate > usercert.pem

# Fetch ScienceData signature image
curl -O https://sciencedata.dk/themes/deic_theme_oc7/core/img/sciencedata_signature.png

# Sign
java -jar $OPENPDFSIGN_JAR --page -1 --image sciencedata_signature.png --hint "Check the validity of this signature at sciencedata.dk" --input IN_FILENAME_RAW --output IN_BASENAME.signed.pdf --certificate usercert.pem --key userkey_unenc.pem

