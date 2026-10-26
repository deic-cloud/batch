#!/bin/bash
# Title: Wind farm energy production (PyWake)
# Category: Wind & climate
# Summary: Annual energy production and wake losses of a wind farm layout (CSV of turbine positions x,y in metres) with DTU Wind's PyWake, on the Horns Rev 1 site with Vestas V80 turbines.
# Output: <name>.aep.txt and <name>.wakemap.png next to each layout
# Featured: 7

################################################
#
# PyWake (DTU Wind) on one layout file per job:
# one turbine per line, "x,y" in metres (a header
# line is allowed). Try it on hornsrev1_layout.csv
# in this folder - the real Horns Rev 1 wind farm.
# Several layouts selected in Files -> Process give
# one result each, e.g. to compare designs.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -o IN_BASENAME.aep.txt IN_FOLDER_URLIN_BASENAME.aep.txt
#GRIDFACTORY -o IN_BASENAME.wakemap.png IN_FOLDER_URLIN_BASENAME.wakemap.png
#GRIDFACTORY -r APPS/WIND/PyWake-2.6.20
#GRIDFACTORY -n PYWAKE-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

pywake_aep 'IN_FILENAME_RAW' 'IN_BASENAME.aep.txt' 'IN_BASENAME.wakemap.png'
