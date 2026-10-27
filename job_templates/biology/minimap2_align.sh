#!/bin/bash
# Title: Align reads to a reference (minimap2)
# Category: Biology
# Summary: Aligns sequencing reads (FASTQ) to the reference genome reference.fasta in the same folder, with minimap2.
# Output: <name>.sam next to each reads file
# Accepts: .fastq, .fq, .fastq.gz, .fq.gz

################################################
#
# minimap2 (Heng Li) aligns the reads of one file
# per job to reference.fasta, which must be in the
# same folder as the reads. Try it on
# sample_reads.fastq.gz in this folder.
#
# PRESET: sr = short reads (Illumina),
# map-ont = Oxford Nanopore, map-hifi = PacBio HiFi.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -i IN_FOLDER_URLreference.fasta
#GRIDFACTORY -o IN_FILENAME.sam IN_FOLDER_URLIN_FILENAME.sam
#GRIDFACTORY -r APPS/BIO/minimap2-2.31
#GRIDFACTORY -n MINIMAP2-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

PRESET=sr

minimap2 -a -x $PRESET -t "$(nproc)" reference.fasta 'IN_FILENAME_RAW' > 'IN_FILENAME.sam'
