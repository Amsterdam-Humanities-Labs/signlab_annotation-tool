# Standalone Annotation Tool — Design Spec

**Date:** 2026-06-07
**Location:** `/web/annotation-tool/`
**Source:** forked from `/web/zin/subBeta4.html` (3483-line single-file sign-language annotation editor)

## Goal

A standalone, browser-only sign-language video annotation editor. The user drops in
a video (and optionally an EAF file), edits multi-tier annotations on a timeline, and
the work autosaves as an EAF file to a user-chosen folder. No PHP, no database, no
signcollect API for core functionality.

## Architecture

Single self-contained HTML app. All `getZinnen.php` server calls are removed. Two
*optional* signcollect calls remain (function only when online + reachable):
handshape smart-search (`getHandshapes.php`) and the Signbank video preview.

```
/web/annotation-tool/
  index.html                  ← the app (forked from subBeta4.html)
  mod.js                      ← copied verbatim from /web/zin/mod.js (WebCodecs frame decoder)
  glosses_transformed.json    ← copied from /web/glosses_transformed.json (bundled gloss data, ~11MB)
  temp/                       ← default suggested working folder for autosaved EAF
```

## Drop-in flow

- A drop zone accepts a video file (`.mp4`) and optionally an EAF (`.eaf`). File type
  detected by extension; drop order-independent (either or both).
- Video → `URL.createObjectURL(file)` → existing `mod.js` frame-extraction pipeline
  (unchanged). Base filename derived from the dropped video name; this replaces the old
  `?filename=` URL param everywhere it was read.
- EAF → parsed client-side via new `parseEAF()`.

## Tiers — dynamic (was hardcoded 3)

Today: fixed `tierVisibility = {0:true,1:true,2:true}`, a hardcoded names array
`["Nederlands","Gebaar-voor-Gebaar","Signbank ID glossen"]`, and each subtitle carries
`tier: 0|1|2`. Replace with a dynamic tiers model:

```js
tiers = [{ id, name }]                              // dynamic, 1..N
subtitles = [{ id, text, start, end, tierId }]      // tierId replaces numeric tier index
```

- Start state: one empty tier ("Tier 1").
- UI to add / rename / delete tiers.
- Dropping an EAF replaces tiers with the file's `<TIER>` elements (one timeline row per
  tier); annotations are mapped onto their tiers.
- Timeline rendering, `TIER_HEIGHT` row layout, subtitle-box `top` positioning, and tier
  visibility become driven by the `tiers` array length instead of the constant 3.

## EAF I/O (new, client-side — replaces server generation)

- `parseEAF(xmlString)`: read `TIME_ORDER`/`TIME_SLOT` (ms), iterate `<TIER>`, read each
  `ALIGNABLE_ANNOTATION` (slot refs → start/end seconds, `ANNOTATION_VALUE` → text) →
  produce `tiers[]` + `subtitles[]`.
- `buildEAF()`: generate valid ELAN EAF XML — `TIME_ORDER` with deduplicated time slots,
  one `<TIER>` per tier, `MEDIA_DESCRIPTOR` referencing the dropped video filename.
  Replaces the old server-side `saveSubtitlesAndEAFFiles` EAF generator.

## Autosave — File System Access API

- First save calls `showDirectoryPicker()` (suggested default: the `temp/` folder). The
  granted `FileSystemDirectoryHandle` is cached in IndexedDB so it survives reloads
  (browser re-prompts for permission once per session).
- Keep the existing 1-second debounce (`uploadSubtitles` → `performUpload`), but
  `performUpload` now writes `<videobasename>.eaf` into the chosen folder via the file
  handle instead of POSTing to PHP.
- Only the `.eaf` is written. The video stays the local file the user dropped (not
  re-copied into `temp/`).
- On reload, if a cached folder handle + a matching `.eaf` exist, offer to restore the
  session. Video must be re-dropped (browsers can't silently reopen it); if the video
  sits in the same chosen folder it can be read back via the directory handle.
- Chrome/Edge only (File System Access API). Show a clear message on unsupported browsers.

## Removed

- Entire status bar: HTML `#statusBar` (lines 374–407), `saveStatus()`, and its 4 change
  listeners (lines 3371–3392).
- `userProtect.js` (auth), all `getZinnen.php` calls (`fetchZinArrayByFilename`,
  `fetchSubtitles`, `verifySubtitles`, `saveSubtitlesAndEAFFiles`, status saves), the
  `beforeunload` sendBeacon save, and the `verifySubtitlesOnDisk` integrity check.

## Kept as-is

- Canvas frame rendering, zoom/scroll timeline, drag/resize subtitle boxes,
  contenteditable text editing, gloss glossary dropdown (now from bundled JSON), toasts,
  keyboard shortcuts, Bootstrap/jQuery styling.
- Handshape smart-search and Signbank preview UI, still pointing at `signcollect.nl`
  (degrade gracefully when offline/unreachable).

## Out of scope

- Multi-video sentences (the old `video_count >= 2` path).
- Server-side SRT export / disk verification.
- User authentication.
