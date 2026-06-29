#!/bin/bash

################################################
#
# Transcode mp4 file to 360p.
# using the helper script of the ScienceData
# MediaCMS installation.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -o IN_BASENAME.out.mp4 WORK_FOLDER_URL/output_files/IN_BASENAME_360p.mp4
#GRIDFACTORY -r UTIL/MpegUtils-1.0
#GRIDFACTORY -n transcode_360p-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

# Grab python helper from MediaCMS

nvidia-smi -L
if [[ "$?" == "0" ]]; then
  helper="helpers-hvenc"
else
  helper="helpers"
fi

curl -o helper.py -L https://raw.githubusercontent.com/deic-dk/mediacms/refs/heads/main/files/$helper.py
sed -i -E 's|(from django.conf import settings)|import settings|' helper.py

# Generate settings

cat <<"EOF"> settings.py
BASE_DIR = "."
FFMPEG_COMMAND = "ffmpeg"
FFPROBE_COMMAND = "ffprobe"
FRIENDLY_TOKEN_LEN = 8
MEDIA_ROOT = "."
PORTAL_WORKFLOW = "public"
TEMP_DIRECTORY = "."

media_file = "IN_FILENAME_RAW"
output_filename = "IN_BASENAME.out.mp4"

resolution = 360 # 240, 360, 480, 720, 1080, 1440, 2160

codec = "h264" # "h265", "hevc", "vp9"
pass_file = "passfile"
chunk = False

helper = "HELPER"

EOF

sed -i "s|HELPER|$helper|" settings.py

# Run ffmpeg

python3 -c "
import json
import subprocess
import settings
import helper
from helper import media_file_info, produce_ffmpeg_commands
media_info = media_file_info(settings.media_file)
ffmpeg_command = produce_ffmpeg_commands(settings.media_file, json.dumps(media_info), settings.resolution, settings.codec, settings.output_filename, settings.pass_file, settings.chunk)
if settings.helper == 'helpers-hvenc':
    for i in range(len(ffmpeg_command[0])):
        if ffmpeg_command[0][i] == '-crf':
            del ffmpeg_command[0][i]
            del ffmpeg_command[0][i]
            break
subprocess.run(ffmpeg_command[0])
"


