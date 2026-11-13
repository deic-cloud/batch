#!/bin/bash
# Title: Danish speech to English subtitles
# Category: Media
# Summary: English subtitles for a Danish recording: transcribed and translated with OpenAI Whisper.
# Output: subtitle files next to each recording: .srt and .vtt (WebVTT, the format web players and the <track> element read)
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
#GRIDFACTORY -o IN_BASENAME.vtt IN_FOLDER_URL/IN_BASENAME.vtt
#GRIDFACTORY -r UTIL/WhisperUtil
#GRIDFACTORY -n whisper-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

language=Danish task=translate whisper.sh 'IN_FILENAME_RAW'
if [[ "IN_FILENAME_RAW" != "IN_FILENAME" ]]; then
  mv 'IN_BASENAME_RAW.srt' 'IN_BASENAME.srt'
fi

# WebVTT for web players: a header line, and a dot instead of a comma
# before the milliseconds (the SRT cue numbers are valid VTT cue ids)
{ printf 'WEBVTT\n\n'; sed -E 's/([0-9]{2}:[0-9]{2}:[0-9]{2}),([0-9]{3})/\1.\2/g' 'IN_BASENAME.srt'; } > 'IN_BASENAME.vtt'
