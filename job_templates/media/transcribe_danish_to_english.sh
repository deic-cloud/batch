#!/bin/bash
# Title: Danish speech to English subtitles
# Category: Media
# Summary: English subtitles for a Danish recording: transcribed and translated with OpenAI Whisper.
# Output: a .srt subtitle file next to each recording
# Accepts: audio/*, video/*

################################################
#
# Transcribe audio of mp4 video or m4a file
# using the OpenAI Whisper.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -o IN_BASENAME.srt IN_FOLDER_URL/IN_BASENAME.srt
#GRIDFACTORY -r UTIL/WhisperUtil
#GRIDFACTORY -n whisper-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

language=Danish task=translate whisper.sh 'IN_FILENAME_RAW'
if [[ "IN_FILENAME_RAW" != "IN_FILENAME" ]]; then
  mv 'IN_BASENAME_RAW.srt' 'IN_BASENAME.srt'
fi
