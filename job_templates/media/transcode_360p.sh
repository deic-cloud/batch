#!/bin/bash
# Title: Convert video to 360p
# Category: Media
# Summary: Re-encode a video to 360p H.264 (starts playing at once) - small files for slow connections.
# Output: _<name>_360p.mp4 next to the video (the underscore marks it as a converted copy - delete it any time)
# Accepts: video/*

################################################
#
# Transcode a video to 360p H.264/AAC mp4 with ffmpeg:
# never upscaled, aspect ratio kept, the index at
# the start of the file (-movflags +faststart) so a
# browser starts playing before it has all of it.
# NVIDIA GPU encoding (h264_nvenc) when the worker
# has a GPU, otherwise libx264.
#
################################################
#
# Batch system directives
#
#GRIDFACTORY -i IN_FILE_URL
#GRIDFACTORY -o _IN_BASENAME_360p.mp4 IN_FOLDER_URL_IN_BASENAME_360p.mp4
#GRIDFACTORY -r UTIL/MpegUtils-1.0
#GRIDFACTORY -n transcode_360p-IN_FILENAME
#GRIDFACTORY -s MY_SSL_DN
#
################################################

height=360
in='IN_FILENAME_RAW'
out='_IN_BASENAME_360p.mp4'

# Common options: scale to the height (never up), even width, the most
# compatible pixel format, first video + first audio stream (if any),
# AAC stereo audio, index first.
common=(-map 0:v:0 -map '0:a:0?' -vf "scale=-2:'min($height,ih)'" -pix_fmt yuv420p
        -c:a aac -b:a 128k -ac 2 -movflags +faststart)

encode() {
  ffmpeg -hide_banner -nostdin -y -i "$in" "${common[@]}" "$@" "$out"
}

if nvidia-smi -L >/dev/null 2>&1 && ffmpeg -hide_banner -encoders 2>/dev/null | grep -q h264_nvenc \
   && encode -c:v h264_nvenc -preset p5 -rc vbr -cq 23 -b:v 0; then
  echo "encoded with h264_nvenc (GPU)"
else
  encode -c:v libx264 -preset medium -crf 23 && echo "encoded with libx264 (CPU)"
fi
