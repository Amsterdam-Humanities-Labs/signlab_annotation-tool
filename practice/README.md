# Annotation Tool — SignRep Practice Variant

An interactive NGT (Sign Language of the Netherlands) **practice** app. It shows
you a random sign, you repeat it on webcam in a guided **4-second capture**, and
the SignRep spotter scores your attempt — advancing when your sign lands in the
**top-3**, and revealing the answer after 3 misses. Built on the v3 pipeline
(server-side ffmpeg conversion + V-JEPA 2 segmentation + SignRep spotting); see
`../v3/README.md` for the shared inference infrastructure.

This is **not** an annotation editor — there is no timeline editing, no tiers, no
EAF. It reuses only the webcam capture and the two inference clients.

**Live URL:** https://signcollect.nl/annotation-tool/practice/

## Requirements

- **Google Chrome or Microsoft Edge** (desktop), over **HTTPS** — needs
  `getUserMedia` (webcam) and the `data-state` / `:has()` styling these browsers
  support. A webcam is required.
- The inference servers must be reachable (see *Backend dependency*). The app is
  **online-only**: with no servers there is nothing to practise against.

## How it works

1. **Open** the app. On load it fetches the bundled gloss data and the spotter's
   `GET /vocab`, then builds the **target pool** = signs the spotter knows **∩**
   signs that have a Signbank demo video. Every target is therefore both
   *demonstrable* and *achievable*. If `/vocab` is unreachable the app shows a
   fatal banner and does not start (this is intentional — it refuses to serve
   unwinnable targets).
2. **Watch** — a random target sign loops in the left panel with its gloss label.
3. **Start** — a brief "Ready?…", then "**Sign!**" with a **4 → 0** countdown
   while your webcam records exactly 4 seconds (a cursor sweeps the timeline).
4. **Analyse** — the clip is uploaded, converted (25 fps), segmented, and the
   longest detected segment is spotted for its **top-10 NGT glosses**.
5. **Result** — detected segments animate onto the timeline; the spotted glosses
   are listed; your target turns **green if it is in the top-3**.
   - **Match** → streak +1, advance to the next sign.
   - **Miss** → **Retry**. After **3 attempts** the reference replays in slow
     motion ("reveal") and the app advances.
6. **Skip** any sign; **Stop** aborts an in-flight attempt. A scoreboard tracks
   your streak and matched/total counts.

The webcam preview is **mirrored for comfort**, but the recorded stream is
**un-mirrored** — the spotter needs an un-mirrored sign.

> **On "live":** inference is batch (upload a clip → get segments/glosses), so the
> timeline cannot update mid-sign. "Live" here means the recording cursor sweeps
> in real time during capture and detected segments animate in at the result.

## Inference pipeline (per attempt)

```
webcam 4s webm
   └─ POST → /sign-segmenter/upload  ──> {videoId}  (native ffmpeg → 25 fps mp4)
        ├─ POST {videoId} → /sign-segmenter/segment ──> [[start,end], …]
        │       └─ longest segment = the sign  (fallback: centred 2 s window)
        └─ GET /sign-segmenter/video/{videoId} ──> 25 fps mp4
                └─ POST → /sign-spotter/upload ──> {videoId}
                     └─ POST {videoId,start,end,topk:10} → /sign-spotter/spot ──> top-10 glosses
                          └─ target ∈ top-3 ?  →  match / miss
```

- **Segmenter** (`vjepa-sign-segmentation`): V-JEPA 2 + `ensemble50`, 50 fps.
- **Spotter** (`signrep-spotter`): SignRep embeddings, 25 fps-tuned.
- Both run warm on the GPU box (monsterfish) behind the Apache reverse-proxies
  `/sign-segmenter/` and `/sign-spotter/`. Localhost dev: `:8001` / `:8000`.

## Backend dependency: spotter `GET /vocab`

The target pool needs the spotter's gloss inventory. The spotter must expose:

```
GET /sign-spotter/vocab  →  { "glosses": ["HUIS", "BOOM", …] }
```

returning the model's class labels (the same inventory `/spot` ranks over). The
gloss casing/format must match `/spot`'s output so the top-3 comparison (which
normalizes via trim + lowercase) works. Without this endpoint the app cannot
start.

## Files in this directory (deployment)

Serve the directory as-is over HTTP/HTTPS. Required next to `index.html`:

| File | Purpose |
|------|---------|
| `index.html` | The entire app (HTML + CSS + JS, one file). |
| `practice-core.js` | Pure logic (target pool, top-3 match, segment pick, attempts) — ES module, unit-tested. |
| `practice-core.test.mjs` | `node --test` unit tests for `practice-core.js`. |
| `glosses_transformed.json` | Bundled gloss glossary (~11 MB) for gloss→Signbank-video lookup. |

No build step. ES-module imports and `getUserMedia` require **HTTP/HTTPS** (not
`file://`).

## Local preview & tests

```bash
cd annotation-tool/practice
node --test                 # run the pure-logic unit tests
python3 -m http.server 8799 # then open http://localhost:8799/ in Chrome/Edge
```

For local end-to-end use, point the spotter/segmenter dev servers at
`localhost:8000` / `localhost:8001` (the app auto-switches to these on
`localhost`), or run against the production proxies by serving from a non-local
host.
