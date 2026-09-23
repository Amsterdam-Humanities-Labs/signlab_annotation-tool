# Annotation tool v3
A browser editor for annotating NGT video on a timeline with several tiers. It saves ELAN EAF files.

There is no database and no login. Annotations live in local files.
When you drop a video, a GPU server splits it into signs (V-JEPA 2) and suggests glosses for each sign (SignRep).

Live: https://signcollect.nl/annotation-tool/ (redirects to `v3/`)

## Requirements
Chrome or Edge on a desktop. Autosave uses the [File System Access API](https://developer.mozilla.org/docs/Web/API/File_System_API), which Firefox and Safari lack. In those browsers the rest works, but you must export by hand.

## Using the tool
1. Open https://signcollect.nl/annotation-tool/.
2. Drop a video (`.mp4`) on the page, or use the file picker. Drop an `.eaf` with it, or on its own, to load existing annotations.
   - The browser decodes frames locally, so you can step frame by frame. Long videos take longer to load.
   - For conversion and the AI features the video is uploaded to the signcollect.nl servers. The limit is 3 minutes and 200 MB. Uploads are deleted within 24 hours.
   - If the video has no annotations yet, automatic segmentation fills the timeline with sign segments. Each segment then gets gloss suggestions.
3. Tiers. You start with one tier (`Tier 1`). Click **+ Tier** to add one. Double-click a tier name to rename it. Click **×** to delete it with its annotations. One tier always remains. Dropping an `.eaf` replaces the tiers with the ones in the file.
4. Annotate. Add a box on the timeline and type the text. Drag a box to move it, drag its edges to resize it, and drag it up or down to move it to another tier.
5. Autosave. On the first change the browser asks for a folder (for example the app's `temp/` folder). After that the tool writes `<video-name>.eaf` there about one second after each change. It writes only the `.eaf`, never the video. The browser remembers the folder (IndexedDB) across reloads.
6. Restore. When you reopen the page and the folder holds a saved `.eaf`, the tool offers to restore it. Drop the video again, unless it sits in the same folder with a matching name. Then it loads by itself.

## Online features
These need a connection. Without one they fail without an error and the rest keeps working.
- Handshape search: posts a video frame to `/zin/getHandshapes.php` on the serving host.
- Gloss video: plays Signbank videos from `https://signcollect.nl/zin/getGlossVideo.php`.
- Gloss list search: reads `/signbank_data/glosses_transformed.json`, or `/glosses_transformed.json` if that fails.
- Automatic segmentation (V-JEPA 2): runs when you drop a new video. Uses `https://signcollect.nl/sign-segmenter/`.
- Gloss spotting (SignRep): when you create a segment, the tool asks for the 10 most likely NGT glosses. They appear in a list under the box. Click one to fill the annotation, or click ↻ to run it again. Uses `https://signcollect.nl/sign-spotter/`.

## Video pipeline
Since 2026-06-12 the server converts video with ffmpeg on the GPU machine. Before that the browser did it with ffmpeg.wasm.
```
drop video -- needs converting? (fps != 25 or larger than 1080p)
   | no                                  | yes
   |                                     +- POST original --> /sign-segmenter/upload   (returns videoId)
   |                                     |     +- ffmpeg -r 25 (fit 1080p) --> GET /video/{videoId}
   |                                     |     +- ffmpeg -r 50 (on demand, cached)
   v                                     v
25 fps copy in the browser -- frame decode (timeline) + /sign-spotter (gloss spotting)
automatic segmentation -- POST {videoId} --> /sign-segmenter/segment -- V-JEPA ensemble50 at 50 fps
```
- Segmenter (`vjepa-sign-segmentation`): V-JEPA 2 ViT-L with the `ensemble50` profile (BiLSTM-50 and MS-TCN-50 combined, 50 fps). Boundary F1 is 0.871 on Zin-in-NGT. It segments the stored original, so high-fps video keeps its detail.
- Spotter (`signrep-spotter`): SignRep embeddings, tuned for 25 fps. It gets the 25 fps browser copy.
- Fallback: the browser converts with ffmpeg.wasm when offline, when the original is over 190 MB, or when the videoId has expired (24 hours). On a 404 the tool uploads the video again.
- Both servers run on the GPU machine (monsterfish). Apache on the core server forwards `/sign-segmenter/` and `/sign-spotter/` to them over SSH tunnels. Apache limits uploads to 200 MB. Video may be at most 180 seconds long.

## Files
Serve the whole folder over HTTP or HTTPS. `file://` does not work for the File System Access API or the ES module import. There is no build step.

| File | Purpose |
|---|---|
| `index.html` | The whole app (HTML, CSS and JS) |
| `mod.js` | WebCodecs MP4 frame decoder, imported by `index.html` |
| `temp/` | Suggested folder for autosaved `.eaf` files |

Local preview, from the repo root:
```bash
python3 -m http.server 8799   # open http://localhost:8799/ in Chrome or Edge
```

## EAF format
- Loading: reads the `TIME_ORDER`/`TIME_SLOT` times and the `ALIGNABLE_ANNOTATION`s of each `<TIER>`. A tier without time-aligned annotations loads empty.
- Saving: writes ELAN EAF 3.0 with a `MEDIA_DESCRIPTOR` for the video file name, one set of time slots without duplicates, and one `<TIER>` per timeline row. ELAN opens the result, and this tool reads it back.
