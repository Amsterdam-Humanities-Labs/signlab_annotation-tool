# Annotation Tool v3 — Sign-Language Annotation Editor

A browser-based editor for annotating sign-language videos on a multi-tier
timeline and saving the result as an ELAN **EAF** file. No database, no login —
annotations live in local files. Dropping a video also triggers the AI
pipeline: automatic sign segmentation (V-JEPA 2) and per-segment gloss spotting
(SignRep), both served by warm GPU inference servers (see *Video pipeline*).

**Live URL:** https://signcollect.nl/annotation-tool/ (redirects to `v3/`)

## Requirements

- **Google Chrome or Microsoft Edge** (desktop). The autosave feature uses the
  [File System Access API](https://developer.mozilla.org/docs/Web/API/File_System_API),
  which Firefox and Safari do not support. Everything else works in any modern browser,
  but without autosave you'd have to export manually.

## Using the tool

1. **Open** https://signcollect.nl/annotation-tool/
2. **Drop a video** (`.mp4`) onto the page — or click the file picker. You can drop an
   **`.eaf`** file at the same time (or on its own) to load existing annotations.
   - Frames are decoded locally for precise frame-by-frame scrubbing (long videos take
     longer to load). For format conversion and the AI features the video **is**
     uploaded to the `signcollect.nl` inference servers — uploads are capped at
     3 minutes / 200 MB and auto-deleted (24 h at the latest). See *Video pipeline*.
   - If the video has no annotations yet, **auto-segmentation** runs on load and fills
     the timeline with detected sign segments; each segment is then auto-spotted.
3. **Tiers (timelines).**
   - Start with one tier (`Tier 1`). Use **+ Tier** to add more.
   - **Double-click** a tier's name chip to rename it; click **×** to delete it
     (deleting removes that tier's annotations; at least one tier always remains).
   - Dropping an `.eaf` replaces the tiers with the ones found in the file — one
     timeline row per `<TIER>`.
4. **Annotate.** Add annotation boxes on the timeline, type text, drag to move, drag the
   edges to resize, and drag vertically to move a box between tiers.
5. **Autosave (the first save asks for a folder).**
   - On the first change, the browser asks you to **pick a folder** to save into
     (suggest the app's `temp/` folder or any working folder you like).
   - From then on, the tool writes **`<video-name>.eaf`** into that folder roughly
     1 second after each change. Only the `.eaf` is written — your video stays the
     original file you dropped.
   - The chosen folder is remembered (via the browser's IndexedDB) so it survives reloads.
6. **Restore a session.** When you reopen the page, if a saved `.eaf` exists in the
   remembered folder you'll be asked whether to **restore** it. The video must be
   re-dropped — unless it sits in that same folder with a matching name, in which case
   it is reloaded automatically.

## Optional online features

These only work when the page is online and reachable from `signcollect.nl` (which it is,
when served from the live URL). If offline, they fail quietly and the rest of the tool
keeps working:

- **Smart search** (handshape recognition) — posts a frame to `signcollect.nl`.
- **Signbank video preview** — previews gloss videos from Signbank/Signcollect.
- **Gloss glossary search** — reads `/signbank_data/glosses_transformed.json`
  (falls back to `/glosses_transformed.json`) from the serving site.
- **Auto-segmentation** (V-JEPA 2) — on a fresh video drop, the segmenter fills the
  timeline with detected sign segments. Reaches `https://signcollect.nl/sign-segmenter/`.
- **Gloss spotting** (SignRep) — when a segment is created, the tool uploads the
  (25 fps) video once and asks the inference server for the **top-10 NGT glosses**
  per segment, shown in a dropdown under the box (click to fill the annotation; ↻ to
  re-run). Reaches `https://signcollect.nl/sign-spotter/`. Fails quietly when offline.

## Video pipeline (conversion + AI inference)

Since 2026-06-12 video conversion happens **server-side** (native ffmpeg on the GPU
box) instead of in-browser ffmpeg.wasm — one upload, two derivatives:

```
drop video ── needs converting? (fps ≠ 25 or > 1080p)
   │ no                                  │ yes
   │                                     ├─ POST original ──> /sign-segmenter/upload   (→ {videoId})
   │                                     │     ├─ ffmpeg -r 25 (fit 1080p) ──> GET /video/{videoId}
   │                                     │     └─ ffmpeg -r 50 (lazy, cached)──┐
   ▼                                     ▼                                     │
25 fps browser copy ── frame decode (timeline) + /sign-spotter (gloss spotting)│
                                                                               ▼
auto-segmentation ── POST {videoId} ──> /sign-segmenter/segment ── V-JEPA ensemble50 @ 50 fps
```

- **Segmenter** (`vjepa-sign-segmentation`): V-JEPA 2 ViT-L backbone + the
  `ensemble50` profile (BiLSTM-50 × MS-TCN-50 probability ensemble, tuned decode,
  50 fps — boundary F1 0.871 on Zin-in-NGT). Segments the stored *original*, so
  high-fps sources keep their temporal resolution.
- **Spotter** (`signrep-spotter`): SignRep embeddings, 25 fps-tuned; receives the
  25 fps browser copy, unchanged by the server-convert pipeline.
- **Fallbacks**: in-browser ffmpeg.wasm conversion remains for offline use, originals
  over 190 MB, or an expired videoId (the store has a 24 h TTL; the tool transparently
  re-uploads bytes on a 404).
- **Infrastructure**: both servers run warm on the GPU box (monsterfish), reached via
  Apache reverse-proxies (`/sign-segmenter/`, `/sign-spotter/`) over SSH tunnels.
  Upload caps: 200 MB (Apache `LimitRequestBody`) and 180 s video duration.

## Files in this directory (deployment)

Serve the whole directory as-is. The app needs these next to `index.html`:

| File | Purpose |
|------|---------|
| `index.html` | The entire application (HTML + CSS + JS in one file). |
| `mod.js` | WebCodecs MP4 frame decoder (ES module imported by `index.html`). |
| `temp/` | Suggested default working folder for autosaved `.eaf` files. |

No build step is required — it is plain static files. Just make sure the directory is
served over **HTTP/HTTPS** (the File System Access API and the ES-module import do not
work from a `file://` URL).

## Local preview

```bash
cd annotation-tool
python3 -m http.server 8799
# then open http://localhost:8799/ in Chrome or Edge
```

## EAF format notes

- **Loading:** reads `TIME_ORDER`/`TIME_SLOT` times and each `<TIER>`'s
  `ALIGNABLE_ANNOTATION`s. Tiers with no time-aligned annotations load as empty tiers.
- **Saving:** generates ELAN **EAF 3.0** XML with a `MEDIA_DESCRIPTOR` pointing at the
  video filename, deduplicated time slots, and one `<TIER>` per timeline. The output
  re-opens cleanly in ELAN and round-trips back into this tool.
