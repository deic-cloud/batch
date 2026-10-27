#!/bin/bash
# Title: Molecules to 3D structures (Open Babel)
# Category: Chemistry
# Summary: Generates 3D coordinates for molecules given as SMILES, SDF, MOL or MOL2, with Open Babel.
# Output: <name>.3d.sdf next to each file
# Accepts: .smi, .smiles, .sdf, .mol, .mol2

################################################
#
# Open Babel: every molecule in the file gets 3D
# coordinates (--gen3d), written as SDF. Try it on
# molecules.smi in this folder (caffeine, aspirin,
# ibuprofen, paracetamol).
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -o IN_BASENAME.3d.sdf IN_FOLDER_URLIN_BASENAME.3d.sdf
#GRIDFACTORY -r APPS/CHEM/OpenBabel-3.1
#GRIDFACTORY -n OBABEL3D-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

obabel 'IN_FILENAME_RAW' -O 'IN_BASENAME.3d.sdf' --gen3d
