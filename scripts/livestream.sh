#!/usr/bin/env bash
# Live Audio Stream Service Script
source /etc/birdnet/birdnet.conf

# Read the logging level from the configuration option
LOGGING_LEVEL="${LogLevel_LiveAudioStreamService}"
# If empty for some reason default to log level of error
[ -z $LOGGING_LEVEL ] && LOGGING_LEVEL='error'
# Additionally if we're at debug or info level then allow printing of script commands and variables
if [ "$LOGGING_LEVEL" == "info" ] || [ "$LOGGING_LEVEL" == "debug" ];then
  # Enable printing of commands/variables etc to terminal for debugging
  set -x
fi

FILTERS=()
if [[ "${AUDIO_HIGHPASS_FREQ:-0}" =~ ^[0-9]+$ ]] && [ "${AUDIO_HIGHPASS_FREQ:-0}" -gt 0 ]; then
  FILTERS+=("highpass=f=${AUDIO_HIGHPASS_FREQ}")
fi
NOTCH_HARMONICS="${AUDIO_NOTCH_HARMONICS:-2}"
if [[ "${AUDIO_NOTCH_BASE_FREQ:-0}" =~ ^[0-9]+$ ]] && [[ "${NOTCH_HARMONICS}" =~ ^[0-9]+$ ]] \
  && [ "${AUDIO_NOTCH_BASE_FREQ:-0}" -gt 0 ] && [ "${NOTCH_HARMONICS}" -gt 0 ]; then
  for ((i=1; i<=NOTCH_HARMONICS; i++)); do
    notch_freq=$((AUDIO_NOTCH_BASE_FREQ * i))
    FILTERS+=("bandreject=f=${notch_freq}:width_type=h:width=8")
  done
fi
if [ "$ACTIVATE_FREQSHIFT_IN_LIVESTREAM" == "true" ]; then
  FILTERS+=("rubberband=pitch=${FREQSHIFT_LO}/${FREQSHIFT_HI}")
fi
FILTER_OPT=()
if [ "${#FILTERS[@]}" -gt 0 ]; then
  FILTER_JOINED=$(IFS=,; echo "${FILTERS[*]}")
  FILTER_OPT=(-af "${FILTER_JOINED}")
fi

if [ -z ${REC_CARD} ];then
  echo "Stream not supported"
elif [[ ! -z ${RTSP_STREAM} ]];then
  # Explode the RSPT steam setting into an array so we can count the number we have
  RSTP_STREAMS_EXPLODED_ARRAY=(${RTSP_STREAM//,/ })

  # If for some reason the RTSP_STREAM_TO_LIVESTREAM is not set, then init it to 0 to use the first stream
  if [[ -z ${RTSP_STREAM_TO_LIVESTREAM} ]];then
    RTSP_STREAM_TO_LIVESTREAM=0
  fi

  # Get the RSTP stream at the specified array index
  SELECTED_RSTP_STREAM=${RSTP_STREAMS_EXPLODED_ARRAY[RTSP_STREAM_TO_LIVESTREAM]}

  # If for some reason the RTSP stream url is null
  if [[ -z ${SELECTED_RSTP_STREAM} ]];then
    # Try select the first stream
    SELECTED_RSTP_STREAM=${RSTP_STREAMS_EXPLODED_ARRAY[0]}
  fi

  ffmpeg -nostdin -loglevel $LOGGING_LEVEL -ac ${CHANNELS} -i ${SELECTED_RSTP_STREAM} -acodec libmp3lame \
    -b:a 320k -ac ${CHANNELS} -content_type 'audio/mpeg' \
    "${FILTER_OPT[@]}" \
    -f mp3 icecast://source:${ICE_PWD}@localhost:8000/stream
else
	ffmpeg -nostdin -loglevel $LOGGING_LEVEL -ac ${CHANNELS} -f alsa -i ${REC_CARD} -acodec libmp3lame \
    -b:a 320k -ac ${CHANNELS} -content_type 'audio/mpeg' \
    "${FILTER_OPT[@]}" \
    -f mp3 icecast://source:${ICE_PWD}@localhost:8000/stream
fi
