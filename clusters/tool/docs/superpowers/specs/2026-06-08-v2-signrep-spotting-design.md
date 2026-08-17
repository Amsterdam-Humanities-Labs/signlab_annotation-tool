# annotation-tool v2 — SignRep gloss spotting per segment

**Date:** 2026-06-08
**Status:** Approved design, pending implementation plan

## Goal

In a new `v2/` copy of the annotation tool, run the SignRep gloss spotter
(`/home/gomer/signrep-spotter`) on each timeline segment. When a segment box is
created, show a loading spinner under it; when inference finishes, show the
**top-10** glosses in a dropdown panel under that box. Clicking a result fills
the box's annotation text.

## Decisions (from brainstorming)

- **Video delivery:** upload the whole (already 25fps-normalized) video **once**
  to the inference server; per-segment requests reference it by id.
- **Backend:** a **persistent warm** HTTP inference server that loads the
  `Spotter` model once at startup.
- **Result click:** clicking a top-10 gloss **sets the box text and keeps the
  list open**. No top-1 auto-fill — nothing overwrites the user's text unless
  they click.
- **Trigger:** auto-run **once on box creation** (end committed) + a manual
  **re-run (↻)** button per box. Move/resize do **not** auto-refire.
- **Display:** **top-10 only** (gloss + score).
- **Deployment:** v2 tool is served from `https://signcollect.nl/annotation-tool/v2/`.
  Because that is HTTPS, the inference endpoint must be **same-origin HTTPS**
  (no `http://localhost` — mixed-content/cross-origin blocked). The Python
  inference server runs on a localhost port and Apache **reverse-proxies** a
  public HTTPS path to it — mirroring the existing `wss://signcollect.nl/ISS_Server/ws`
  and `https://signcollect.nl/getHandshapes.php` patterns.

## Layout / file moves

- Copy the **entire** current `/web/annotation-tool/` tree into
  `/web/annotation-tool/v2/` (`index.html`, `mod.js`, `glosses_transformed.json`,
  `README.md`, `vendor/`, `docs/`, `temp/`). The original is untouched; all v2
  changes happen under `v2/`.
- The inference server is a **new file in `/home/gomer/signrep-spotter/`**
  (`infer_server.py`) so it can import `spotter.py` and run in that venv (torch /
  sltk / model weights live there). v2 contains no Python; it only makes HTTP
  calls.

## Inference server — `signrep-spotter/infer_server.py`

Stdlib `http.server` (same style as the existing `serve.py`). Loads
`Spotter(device=...)` **once at startup** and keeps it warm. CORS headers so the
browser can call it (and an `OPTIONS` preflight handler). Requests are
**serialized** behind a lock (model is CPU-bound / not thread-safe); concurrent
segment requests queue.

Endpoints:

- `POST /upload` — body is the normalized mp4 bytes (the browser's `videoBlob`).
  Saves to a temp path keyed by a content hash; returns `{ "videoId": "<hash>" }`.
  Idempotent: re-uploading the same bytes returns the same id without rewriting.
  Called **once** per loaded video.
- `POST /spot` — `{ videoId, start, end, topk: 10 }`. Looks up the saved video
  path, runs `Spotter.spot_segments(path, [(start, end, None)], topk=10)`, returns
  `{ "glosses": [ { "rank", "gloss", "score" }, ... ] }`. Unknown `videoId`
  (e.g. server restarted, temp cleared) → `409` with `{ "error": "no_video" }`
  so the client knows to re-upload.
- `GET /health` — `{ "ok": true, "model": "fine-tuned"|"base" }` for the client
  to detect availability.

Run target: bind `127.0.0.1:<port>`; kept alive via systemd/screen; Apache
proxies `https://signcollect.nl/sign-spotter/` (or similar path) → this port.

## v2 frontend wiring — `v2/index.html`

- Config constant `INFER_BASE`. On localhost dev → `http://localhost:8000`; on
  signcollect.nl → the same-origin HTTPS proxy path (e.g.
  `https://signcollect.nl/sign-spotter`). Chosen with the existing `isLocalhost`
  check.
- If the server is unreachable, the feature **fails quietly** (matches how Smart
  Search / Signbank preview degrade) — the rest of the tool keeps working.
- **Upload once:** after the video is loaded/normalized, `POST /upload` with the
  blob; store the returned `videoId` in global state. Track upload status so spot
  requests that arrive before upload completes wait for it.
- **Per-box state:** extend each `subtitles[]` object with:
  - `spotState`: `'idle' | 'loading' | 'done' | 'error'`
  - `spotResults`: array of `{ rank, gloss, score }` (top-10)
  These are runtime-only (not persisted to the EAF).
- **Trigger:** when a new box's end is committed in `timelineAddClick()`, set
  `spotState='loading'` and fire `/spot`. Each box renders a small **↻ re-run**
  button that re-fires on demand. Move/resize do not auto-refire (but a moved box
  is stale — its ↻ lets the user refresh).
- **Rendering** (in `renderAll()`, a panel absolutely positioned under the box at
  `top: TIER_HEIGHT`, `left: 0`, anchored to the box, `z-index` above siblings):
  - `loading` → CSS spinner ("loading circle") under the box.
  - `done` → dropdown panel listing the **top-10** as `gloss — score`,
    scrollable, click-to-fill.
  - `error` → "spotting failed ↻" with retry.
- **Click behavior:** clicking a gloss sets `sub.text = gloss`, marks the box
  committed, re-renders the box content, and **keeps the dropdown open**.

## Data flow

```
video loaded ──▶ POST /upload (blob) ──▶ videoId stored
new box end committed
   └▶ sub.spotState='loading'; renderAll() → spinner under box
       └▶ POST /spot {videoId, start, end, topk:10}
           ├─ ok    → sub.spotState='done'; sub.spotResults=glosses; renderAll() → top-10 panel
           │           click gloss → sub.text=gloss (list stays open)
           ├─ 409    → re-upload, then retry /spot
           └─ fail   → sub.spotState='error'; renderAll() → retry ↻
```

## Error handling

- Upload pending/failed → spot requests queue until `videoId` exists or surface
  `error` with retry.
- Server down / fetch throws → quiet `error` state per box; ↻ retries.
- `409 no_video` (server restarted / temp pruned) → client re-uploads then retries.
- One in-flight `/spot` per box; re-run supersedes the prior request for that box.
- Inference is slow (~1–20 s/segment on a loaded CPU box). The spinner is the
  honest signal; no artificial timeout that would discard a slow-but-valid result
  (a long ceiling, e.g. 120 s, guards against a truly hung request).

## Out of scope (YAGNI)

- Automatic sign-boundary detection (the spotter takes boundaries in, not out).
- Persisting spot results into the EAF.
- Batch "spot all segments" button (per-box on-create + manual ↻ covers the ask).
- Auto-rerun on move/resize.
