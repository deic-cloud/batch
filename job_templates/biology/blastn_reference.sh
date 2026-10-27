#!/bin/bash
# Title: Search sequences against a reference (BLAST)
# Category: Biology
# Summary: Finds where the DNA sequences of a FASTA file match reference.fasta in the same folder, with NCBI BLAST+ (blastn).
# Output: <name>.blast.tsv (a table of hits) next to each file
# Accepts: .fasta, .fa, .fna, .fas

################################################
#
# NCBI BLAST+ blastn: each sequence in the selected
# FASTA file is compared with reference.fasta, which
# must be in the same folder. Try it on
# query_sequences.fasta in this folder.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -i IN_FOLDER_URLreference.fasta
#GRIDFACTORY -o IN_FILENAME.blast.tsv IN_FOLDER_URLIN_FILENAME.blast.tsv
#GRIDFACTORY -r APPS/BIO/BLAST-2.17.0
#GRIDFACTORY -n BLAST-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

FIELDS="qseqid sseqid pident length mismatch gapopen qstart qend sstart send evalue bitscore"
{
	echo "$FIELDS" | tr ' ' '\t'
	blastn -query 'IN_FILENAME_RAW' -subject reference.fasta -outfmt "6 $FIELDS"
} > 'IN_FILENAME.blast.tsv'
