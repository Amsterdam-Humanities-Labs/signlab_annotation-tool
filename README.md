# Annotation Tool — Standalone Sign-Language Annotation Editor

A self-contained, browser-only editor for annotating sign-language videos on a
multi-tier timeline and saving the result as an ELAN **EAF** file. No server, no
database, no login — you drop in a video (and optionally an EAF) and work locally.

**Live URL:** https://signcollect.nl/annotation-tool/

## Requirements

- **Google Chrome or Microsoft Edge** (desktop). The autosave feature uses the
  [File System Access API](https://developer.mozilla.org/docs/Web/API/File_System_API),
  which Firefox and Safari do not support. Everything else works in any modern browser,
  but without autosave you'd have to export manually.

## Using the tool

1. **Open** https://signcollect.nl/annotation-tool/
2. **Drop a video** (`.mp4`) onto the page — or click the file picker. You can drop an
   **`.eaf`** file at the same time (or on its own) to load existing annotations.
   - The video is read locally in your browser; it is **not** uploaded anywhere.
   - Frames are decoded for precise frame-by-frame scrubbing (long videos take longer to load).
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
- **Gloss glossary search** — works fully offline (the gloss data is bundled in
  `glosses_transformed.json`).

## Files in this directory (deployment)

Serve the whole directory as-is. The app needs these next to `index.html`:

| File | Purpose |
|------|---------|
| `index.html` | The entire application (HTML + CSS + JS in one file). |
| `mod.js` | WebCodecs MP4 frame decoder (ES module imported by `index.html`). |
| `glosses_transformed.json` | Bundled gloss glossary (~11 MB) for offline gloss search. |
| `temp/` | Suggested default working folder for autosaved `.eaf` files. |
| `docs/` | Design spec and implementation plan (not required at runtime). |

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
