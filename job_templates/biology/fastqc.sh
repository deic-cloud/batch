#!/bin/bash
# Title: Sequencing quality report (FastQC)
# Category: Biology
# Summary: Quality-control report for a sequencing file (FASTQ, BAM or SAM): base qualities, GC content, duplication, adapter content.
# Output: <name>.fastqc.html next to each file
# Featured: 4
# Accepts: .fastq, .fq, .fastq.gz, .fq.gz, .bam, .sam

################################################
#
# FastQC (Babraham Bioinformatics) on one sequencing
# file per job. Select several files in Files and
# choose Process to get one report for each.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -o IN_FILENAME.fastqc.html IN_FOLDER_URLIN_FILENAME.fastqc.html
#GRIDFACTORY -r APPS/BIO/FastQC-0.12.1
#GRIDFACTORY -n FASTQC-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

mkdir -p fastqc_out
fastqc --quiet --outdir fastqc_out 'IN_FILENAME_RAW'
mv fastqc_out/*_fastqc.html 'IN_FILENAME.fastqc.html'
