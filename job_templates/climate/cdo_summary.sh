#!/bin/bash
# Title: Climate data: time mean and summary (CDO)
# Category: Wind & climate
# Summary: Summarises a NetCDF or GRIB file (variables, grid, time steps, value ranges) and computes its mean over time, with CDO.
# Output: <name>.timmean.nc and <name>.cdo.txt next to each file
# Accepts: .nc, .nc4, .grb, .grib, .grib2

################################################
#
# CDO (Climate Data Operators, MPI for Meteorology).
# Try it on sample_temperature.nc in this folder -
# synthetic monthly near-surface temperatures on a
# 5-degree grid.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -o IN_BASENAME.timmean.nc IN_FOLDER_URLIN_BASENAME.timmean.nc
#GRIDFACTORY -o IN_BASENAME.cdo.txt IN_FOLDER_URLIN_BASENAME.cdo.txt
#GRIDFACTORY -r APPS/CLIMATE/CDO-2.0
#GRIDFACTORY -n CDO-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

{
	echo "== Contents"; cdo -s sinfon 'IN_FILENAME_RAW'
	echo; echo "== Values per time step"; cdo -s infon 'IN_FILENAME_RAW'
} > 'IN_BASENAME.cdo.txt' 2>&1
cdo -s timmean 'IN_FILENAME_RAW' 'IN_BASENAME.timmean.nc'
