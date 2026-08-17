#!/bin/bash
# Daily backup of annotation-tool EAF files (predictions + human corrections)
# to the studioFiles (fuseblk) store. Plain dated full copies; 30-day retention.
set -euo pipefail
SRC_OUT=/web/annotation-tool/clusters/out/eaf
SRC_EDIT=/web/annotation-tool/clusters/edit/eaf
DEST=/web/gebarenoverleg_media/studioFiles/annotation-tool/segments
DAY=$(date +%F)
SNAP="$DEST/$DAY"
mkdir -p "$SNAP/out" "$SNAP/edit"
rsync -rt --delete "$SRC_OUT/"  "$SNAP/out/"
rsync -rt --delete "$SRC_EDIT/" "$SNAP/edit/"
# write a manifest + pointer (no symlinks on fuseblk)
echo "$DAY" > "$DEST/LATEST.txt"
printf '%s backup: out=%s edit=%s -> %s\n' "$(date '+%F %T')" \
  "$(ls "$SNAP/out" | wc -l)" "$(ls "$SNAP/edit" | wc -l)" "$SNAP" | tee -a "$DEST/backup.log"
# retention: drop snapshots older than 30 days
find "$DEST" -maxdepth 1 -type d -name '20[0-9][0-9]-[0-9][0-9]-[0-9][0-9]' -mtime +30 -exec rm -rf {} + 2>/dev/null || true
