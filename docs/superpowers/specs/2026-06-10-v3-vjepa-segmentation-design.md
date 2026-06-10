# v3 — V-JEPA Auto-Segmentation + SignRep Spotting

**Date:** 2026-06-10
**Branch:** `v2-signrep-spotting`
**Status:** Approved (design)

## Goal

When a user drops a video into the annotation tool, automatically segment it into
sign boundaries using the V-JEPA sign segmentor (RGB-only, no HaMeR/pose required),
show a loading bar while it runs, populate the timeline with the resulting segments,
and then auto-spot each segment with the existing SignRep gloss spotter.

The segmentation model runs on **monsterfish** (GPU) and is reached from the browser
over HTTPS via an Apache reverse-proxy → SSH reverse tunnel, exactly mirroring the
existing spotter deployment.

This is delivered as a new `v3/` folder (copy of `v2/`), and `/annotation-tool/` is
repointed to redirect to `v3/`. `v2/` is left intact as the spotter-only snapshot.

## Context / what already exists

- **vjepa segmentor** at `/home/gomer/vjepa-sign-segmentation/`. CLI:
  `python -m vjepa_seg.infer VIDEO -o out.json --device cuda` →
  `{"video":…, "fps":25.0, "segments":[[start,end],…]}`. Loads a ~1.2 GB V-JEPA2
  backbone (`facebook/vjepa2-vitl-fpc64-256`, cached in `~/.cache/huggingface/`) plus
  a ~2 MB MS-TCN head. `run(video, device, models_dir, out)` in
  `vjepa_seg/infer.py` is the reusable entry point; torch/cv2/transformers are
  imported lazily inside it.
- **Spotter deployment (the pattern to mirror):** warm `infer_server.py` on
  monsterfish `127.0.0.1:8000` (`--device cuda --dict coverage`), systemd `--user`
  service, SSH reverse tunnel monsterfish:8000 → signcollect:8000, Apache
  reverse-proxy `/sign-spotter/` → `127.0.0.1:8000`. Browser reaches it at
  `https://signcollect.nl/sign-spotter`.
- **v2 frontend** (`/web/annotation-tool/v2/index.html`) already has:
  - `replaceTier2Subtitles(segments)` — wipes tier-2 and loads a `[{start,end,text}]`
    list onto the timeline (backs up originals, shows a Revert button).
  - `segmentationProgressModal` + `updateSegmentationProgress(pct, stage, msg)` —
    a Bootstrap progress modal (currently driven by the old ISS flow).
  - `runSpot(sub)` — per-segment gloss spotting with spinner→top-10 panel; the
    manual-create path already auto-spots new segments
    (`subtitles.slice(beforeLen).filter(s => !s.masterId).forEach(s => runSpot(s))`).
  - `INFER_BASE` (localhost dev vs `https://signcollect.nl/sign-spotter`),
    `ensureVideoUploaded()`, `videoBlob` (the normalized 25 fps mp4).
  - An old **`autoSegmentBtn`** wired to a HaMeR/ISS WebSocket segmentor that needs a
    precomputed `.hamer` file and a `filename` URL param — only usable when launched
    from signcollect with a known file, **not** for an arbitrary dropped video. This
    path is being **repurposed** to the new vjepa segmentor.

## Decisions (locked)

| Decision | Choice |
|---|---|
| Tool location | New `v3/` folder (copy of `v2/`); repoint `/annotation-tool/` redirect to `v3/` |
| Auto-segment trigger | On a fresh video drop, **only if tier-2 has no segments yet** |
| Old HaMeR/ISS button | **Repurpose** `autoSegmentBtn` to call the vjepa segmentor; drop the WebSocket path |
| Backend topology | **Separate** warm server (port 8001) + 2nd SSH tunnel + Apache `/sign-segmenter/` |
| Progress display | **Indeterminate** animated bar + stage label + elapsed-seconds counter (no fake %) |

## Architecture

```
browser (v3/index.html)
  │  POST videoBlob (normalized 25 fps mp4)
  ▼
https://signcollect.nl/sign-segmenter/segment
  │  Apache reverse-proxy
  ▼
signcollect 127.0.0.1:8001
  │  SSH reverse tunnel
  ▼
monsterfish 127.0.0.1:8001  ──  infer_server (vjepa)  --device cuda
  │  warm V-JEPA2 backbone + MS-TCN head (loaded once)
  ▼
{ fps: 25.0, segments: [[s,e], …] }
```

The spotter (`/sign-spotter/`, port 8000) is unchanged and runs alongside.

## Component 1 — warm V-JEPA segmentation server

New file `/home/gomer/vjepa-sign-segmentation/infer_server.py` (not git-tracked; the
repo is saved-in-place like signrep-spotter). Mirrors the spotter server's shape:

- **Module state:** `SEGMENTER = None`, `VIDEO_DIR = tempfile.mkdtemp(...)`,
  `SEG_LOCK = threading.Lock()`, `HERE`, `DEVICE = "cpu"`.
- **`FakeSegmenter`** — no torch; `segment(video_path)` returns a fixed deterministic
  list, e.g. `[[0.48, 1.32], [1.80, 2.68]]`, and a `fake = True` marker. Used by tests
  and `--fake`.
- **`load_real_segmenter(device, models_dir)`** — lazily constructs a wrapper that
  loads the V-JEPA2 backbone + MS-TCN head **once** in its constructor and exposes
  `.segment(video_path) -> [[s,e], …]`. Heavy imports (torch/cv2/transformers,
  `vjepa_seg.*`) happen lazily so the module top stays stdlib-only (tests never trigger
  them). See Implementation note below — `infer.run()` reloads the backbone on every
  call, so the wrapper does not call `run()`; it reproduces `run()`'s per-video body
  against the already-loaded backbone + head.
- **Endpoints:**
  - `GET /health` → `{"ok": true, "model": "vjepa_seg", "device": DEVICE, "fake": bool}`.
    `fake` is true when `SEGMENTER is None or isinstance(SEGMENTER, FakeSegmenter)` —
    so an operator can tell a stray `--fake` server from the real one (same rationale
    as the spotter's `fake` flag).
  - `POST /segment` → body = raw mp4 bytes. Writes to a temp file (sha256[:16] dedup,
    like the spotter's `/upload`), runs `SEGMENTER.segment(path)` under `SEG_LOCK`,
    returns `{"fps": 25.0, "segments": [[s,e], …]}`. `400` on empty body.
  - `OPTIONS` / CORS headers identical to the spotter (`Access-Control-Allow-Origin: *`).
- **CLI:** `--device cpu|cuda` (default cpu), `--port` (default 8001), `--host`
  (default 127.0.0.1), `--models-dir` (default `models`), `--fake`.

**Implementation note (warm backbone):** `vjepa_seg.infer.run()` loads the backbone
on every call. To keep the server warm, the real wrapper loads the backbone + head
**once** in its constructor (using `load_backbone`, `RegionMSTCN` + checkpoint from
`vjepa_seg.extract` / `vjepa_seg.model`) and its `.segment()` reproduces the body of
`run()` (read frames → extract tokens → head → upsample → label_fixes →
bio_to_segments) without reloading. This keeps per-request latency to just the
per-video extraction.

### Test — `/home/gomer/vjepa-sign-segmentation/test_infer_server.py`

Stdlib `unittest`, no torch. Inject `FakeSegmenter` into the module global, start the
server on an ephemeral port, and assert:
- `test_health_ok` — `/health` returns `ok:true`, `model:"vjepa_seg"`, and `fake:true`.
- `test_segment_fake` — `POST /segment` with bytes returns the fixed segment list and
  `fps: 25.0`.
- `test_segment_empty_body` — `POST /segment` with no body → `400`.

## Component 2 — deployment (mirror the spotter)

- `deploy/vjepa-segment.service` — systemd `--user` unit, `ExecStart` runs the server
  on `127.0.0.1:8001` with `--device cuda`. Linger already enabled on monsterfish.
- **Second SSH reverse tunnel:** monsterfish `127.0.0.1:8001` → signcollect
  `127.0.0.1:8001`, added alongside the existing 8000 tunnel (same
  `Restart=always`/autossh mechanism already in place for the spotter).
- `deploy/apache-sign-segmenter.conf` — `ProxyPass /sign-segmenter/ http://127.0.0.1:8001/`
  + matching `ProxyPassReverse`, sibling of the existing `/sign-spotter/` block.
- `deploy/install-segment.sh` — convenience installer mirroring the spotter's
  `install-inference.sh` (venv bootstrap, model download, service install).

All deploy/server interactions to monsterfish go through `rtk proxy ssh …` with inline
single-quoted scripts (the rtk hook rewrites tokens like `pip`/`curl` inside ssh
payloads, and `rtk proxy` does not forward stdin).

## Component 3 — frontend (`v3/`)

1. **Create `v3/`** as a copy of `v2/` (`index.html`, `mod.js`,
   `glosses_transformed.json`, `vendor/`).
2. **`SEG_BASE`** sibling of `INFER_BASE`:
   `(localhost|127.0.0.1) ? 'http://localhost:8001' : 'https://signcollect.nl/sign-segmenter'`.
3. **`segInfer(blob)`** — `POST SEG_BASE + '/segment'`, body = blob; returns
   `{fps, segments}`. Maps `segments:[[s,e],…]` → `[{start:s, end:e, text:''}]`.
4. **`runSegmentation()`**:
   - Guard: no-op if already running, or if `SEG_BASE` is falsy.
   - Show `segmentationProgressModal` as an **indeterminate** bar: striped+animated at
     100% width, stage label "Segmenting…", and an elapsed-seconds counter ticking via
     `setInterval`. `updateSegmentationProgress` is reused but driven with a fixed
     visual (no fabricated percentages).
   - `await segInfer(videoBlob)`.
   - On success: `replaceTier2Subtitles(segments)` → `renderAll()` → hide modal → clear
     the elapsed timer → auto-spot every new tier-2 segment (reuse the existing
     `forEach(s => runSpot(s))` pattern).
   - On error: clear timer, hide modal, `showToast(...)`, leave timeline empty.
   - `finally`: reset the in-progress flag and clear the interval.
5. **Auto-trigger on drop:** in the video-load `onFinish` (where
   `ensureVideoUploaded()` is already called), after `renderAll()`, call
   `runSegmentation()` **only when tier-2 currently has zero subtitles** (so a drop
   accompanied by an EAF, which pre-populates segments, is left untouched).
6. **Repurpose `autoSegmentBtn`:** replace its click handler (the HaMeR/ISS WebSocket
   code) with a call to `runSegmentation()`, serving as the manual re-run. Remove the
   now-dead ISS WebSocket / HaMeR / `parseVTTToSegments`-via-WebSocket code that only
   the old handler used. (`replaceTier2Subtitles`, `revertToOriginalSegments`, the
   modal, and `updateSegmentationProgress` are kept — they are reused.)
7. **Redirect:** change `/web/annotation-tool/index.html` to redirect `./v2/` → `./v3/`
   (meta-refresh + `location.replace`), preserving query/hash. Keep the v1/v2 links.

## Order of operations on a fresh drop

```
prepareAndLoadVideo(file)         normalize to 25 fps, cache
  → start(file) → decode frames
  → onFinish:
      ensureVideoUploaded()        pre-upload to spotter (existing)
      updateTimelineWidth(); renderAll()
      if (tier-2 has 0 segments):
        runSegmentation()
          → modal (indeterminate)
          → POST videoBlob → /sign-segmenter/segment
          → replaceTier2Subtitles(segments); renderAll(); hide modal
          → runSpot(each segment)   spinner → top-10 per box (existing)
```

## Error handling

- **Segmenter unreachable / 5xx / network error:** hide modal, toast, leave timeline
  empty; user can drop an EAF or press the (repurposed) Auto-Segment button to retry.
- **Zero segments returned:** hide modal, toast "No segments detected", leave empty.
- **Concurrent trigger:** `isSegmentationInProgress` guard (already present) prevents
  overlap between the auto-trigger and the manual button.
- **Spotter failures per segment:** unchanged — each box shows its own error state via
  the existing `buildSpotPanel`.

## Testing

- **Server:** stdlib `unittest` (health, fake-segment, empty-body) — runnable without
  torch in CI/dev.
- **Live smoke:** `curl https://signcollect.nl/sign-segmenter/health` →
  `{ok:true, model:"vjepa_seg", device:"cuda", fake:false}`; segment the repo's
  `example.mp4` and confirm a plausible segment list.
- **Frontend:** drop a short clip, confirm loading bar → timeline fills → each box
  spots; drop a clip + EAF, confirm segmentation is skipped.

## Out of scope (YAGNI)

- Real streaming/SSE progress percentages (indeterminate bar is sufficient).
- Re-segmenting on segment move/resize (manual button only, like the spotter's re-run).
- Sharing one video upload between the spotter and segmenter servers (they are separate
  processes; each receives the small normalized mp4 directly).
