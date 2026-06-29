#!/bin/bash

################################################
#
# Verify a signed PDF.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -r UTIL/PDFSign
#GRIDFACTORY -n PDFVERIFY-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

pdfsig IN_FILENAME_RAW
