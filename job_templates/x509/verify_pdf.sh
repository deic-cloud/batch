#!/bin/bash
# Title: Check the signatures in a PDF
# Category: Signing & encryption
# Summary: Lists the signatures in a PDF and whether they are valid.
# Output: the result in the job's output (stdout)
# Accepts: application/pdf, .pdf

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

pdfsig 'IN_FILENAME_RAW'
