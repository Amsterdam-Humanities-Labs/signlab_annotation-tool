# Annotation tool, webcam version
A copy of the v3 editor that starts from a webcam recording instead of a dropped file.

Live: https://signcollect.nl/annotation-tool/webcam/

Everything else works as in v3: tiers, annotating, autosave, restore, online features, files and the EAF format. See `../v3/README.md`. This file lists only the differences.

## Using it
1. Open https://signcollect.nl/annotation-tool/webcam/ in Chrome or Edge on a desktop.
2. Click **● Record**, sign, and stop. Watch the preview and click **Use this**. You can also drop a video (`.mp4`) or an `.eaf` as in v3.
3. The recording is converted, loaded on the timeline, segmented and given gloss suggestions, the same as a dropped video in v3.

## Differences from v3
- The camera asks for 50 fps (`frameRate: { ideal: 50 }`). That matches the segmenter's `ensemble50` profile. A 30 fps camera still works; the server then repeats frames up to 50 fps.
- The original recording (webm) is uploaded once to `/sign-segmenter/upload`. The server converts it with ffmpeg and returns a 25 fps, 1080p copy for the timeline and the spotter. Segmentation runs on the stored original through its `videoId`.
- ffmpeg.wasm in the browser is the fallback when offline or when the file is too large.
- Gloss spotting decodes the video again for each segment. On a CPU this is slow for long videos, so it works best for short clips.

## Local preview
From the repo root:
```bash
python3 -m http.server 8799   # open http://localhost:8799/webcam/ in Chrome or Edge
```
