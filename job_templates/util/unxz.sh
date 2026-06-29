#!/bin/bash

################################################
#
# # Simple example job template: uncompress file with XZ.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -o IN_BASENAME WORK_FOLDER_URL/output_files/IN_BASENAME
#GRIDFACTORY -r UTIL/XZ
#GRIDFACTORY -n UNXZ-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

unxz 'IN_FILENAME_RAW'

if [[ "IN_BASENAME_RAW" != "IN_BASENAME" ]]; then
  mv 'IN_BASENAME_RAW' 'IN_BASENAME'
fi
