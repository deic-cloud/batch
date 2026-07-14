#!/bin/bash
#
# Simple test job: run "wc" on an input text file and write the counts to
# <name>.wc.txt in your work folder's output_files. Pick an input file,
# submit, then look in Batch/output_files. A quick end-to-end check.
#
#GRIDFACTORY -n WORDCOUNT-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -o IN_BASENAME.wc.txt WORK_FOLDER_URL/output_files/IN_BASENAME.wc.txt

wc IN_FILENAME > IN_BASENAME.wc.txt
