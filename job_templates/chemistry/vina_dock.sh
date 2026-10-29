#!/bin/bash
# Title: Dock a molecule to a protein (AutoDock Vina)
# Category: Chemistry
# Summary: Docks a ligand (SDF, MOL or PDBQT) into receptor.pdbqt, within vina_box.txt, with AutoDock Vina.
# Output: <name>.docked.pdbqt (poses) and <name>.vina.txt (scores) next to each ligand
# Featured: 6
# Accepts: .sdf, .mol, .pdbqt

################################################
#
# AutoDock Vina (Scripps Research); ligands in SDF
# or MOL are prepared with meeko first. The protein
# (receptor.pdbqt) and the search box (vina_box.txt)
# must be in the same folder as the ligand.
# Try it on imatinib.sdf in this folder: the
# protein-drug complex 1IEP (Abl kinase with
# imatinib), from AutoDock Vina's own examples.
# Several ligands selected = one docking each.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -i IN_FOLDER_URLreceptor.pdbqt
#GRIDFACTORY -i IN_FOLDER_URLvina_box.txt
#GRIDFACTORY -o IN_BASENAME.docked.pdbqt IN_FOLDER_URLIN_BASENAME.docked.pdbqt
#GRIDFACTORY -o IN_BASENAME.vina.txt IN_FOLDER_URLIN_BASENAME.vina.txt
#GRIDFACTORY -r APPS/CHEM/Vina-1.2.7
#GRIDFACTORY -n VINA-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

vina_dock 'IN_FILENAME_RAW' receptor.pdbqt vina_box.txt 'IN_BASENAME.docked.pdbqt' 'IN_BASENAME.vina.txt'
