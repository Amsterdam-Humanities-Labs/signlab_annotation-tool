#!/bin/bash
# Daily backup of annotation-tool EAF files (predictions + human corrections)
# to the studioFiles (fuseblk) store. Plain dated full copies; 30-day retention.
set -euo pipefail
# Install root. Same resolution the PHP side does, minus the parts bash
# has no way to reach: SC_WEB_ROOT from the environment, else /web.
SC_WEB_ROOT=${SC_WEB_ROOT:-/web}
SRC_OUT="$SC_WEB_ROOT/annotation-tool/clusters/out/eaf"
# Human corrections live outside the checkout (see edit/data.php).
SRC_DATA="$SC_WEB_ROOT/annotation_data/clusters"
SRC_EDIT="$SRC_DATA/eaf"
DEST="$SC_WEB_ROOT/gebarenoverleg_media/studioFiles/annotation-tool/segments"
DAY=$(date +%F)
SNAP="$DEST/$DAY"
mkdir -p "$SNAP/out" "$SNAP/edit"
rsync -rt --delete "$SRC_OUT/"  "$SNAP/out/"
if [ -d "$SRC_EDIT" ]; then rsync -rt --delete "$SRC_EDIT/" "$SNAP/edit/"; fi
for f in status.json merge_decisions.json; do
  if [ -f "$SRC_DATA/$f" ]; then cp -p "$SRC_DATA/$f" "$SNAP/$f"; fi
done
# write a manifest + pointer (no symlinks on fuseblk)
echo "$DAY" > "$DEST/LATEST.txt"
printf '%s backup: out=%s edit=%s -> %s\n' "$(date '+%F %T')" \
  "$(ls "$SNAP/out" | wc -l)" "$(ls "$SNAP/edit" | wc -l)" "$SNAP" | tee -a "$DEST/backup.log"
# retention: drop snapshots older than 30 days
find "$DEST" -maxdepth 1 -type d -name '20[0-9][0-9]-[0-9][0-9]-[0-9][0-9]' -mtime +30 -exec rm -rf {} + 2>/dev/null || true
