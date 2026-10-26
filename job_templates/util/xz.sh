#!/bin/bash
# Title: Compress a file (xz)
# Category: Utilities
# Summary: Compress a file with xz.
# Output: <name>.xz in Batch/output_files

################################################
#
# Simple example job template: compress file with XZ.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -o IN_FILENAME.xz WORK_FOLDER_URL/output_files/IN_FILENAME.xz
#GRIDFACTORY -r UTIL/XZ
#GRIDFACTORY -n XZ-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

xz 'IN_FILENAME_RAW'

if [[ "IN_FILENAME_RAW" != "IN_FILENAME" ]]; then
  mv 'IN_FILENAME_RAW.xz' 'IN_FILENAME.xz'
fi