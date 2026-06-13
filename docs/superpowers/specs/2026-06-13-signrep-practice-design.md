# SignRep Practice — Interactive Sign-Repetition Web App

**Date:** 2026-06-13
**Branch:** `signrep-practice` (off `v2-signrep-spotting`)
**Directory:** `practice/` (fork of `webcam/`)
**Status:** Design approved, pending spec review.

## 1. Purpose

An interactive NGT (Sign Language of the Netherlands) practice app. It shows the
user a random sign to imitate, the user performs it on webcam in a guided
4-second capture, and the app reports whether the gloss spotter recognized the
sign — advancing on a match and revealing the answer after three misses. It is a
practice/learning loop built on top of the existing v3/webcam inference pipeline
(server-side ffmpeg conversion, V-JEPA segmentation, SignRep spotting).

This is **not** an annotation editor. The timeline, EAF save/load, tiers, and
autosave of the parent variants are removed; only the camera capture and the two
inference integrations are reused.

## 2. Scope & deployment

- New variant directory **`practice/`**, forked from **`webcam/`** — chosen
  because it already contains `getUserMedia` capture + `MediaRecorder`, the
  server-convert pipeline, and both inference clients.
- New git branch **`signrep-practice`**.
- Plain static files, no build step, served over HTTP/HTTPS (same as siblings).
  Local preview: `python3 -m http.server` from the directory.
- One small **backend change** to the spotter (see §6).

## 3. The practice loop (state machine)

```
WATCH ─▶ READY ─▶ RECORD ─▶ ANALYZE ─▶ RESULT ─▶ (next | retry | reveal)
  ▲                                                   │
  └───────────────────── next target ◀────────────────┘
```

| State | Duration | Behavior |
|-------|----------|----------|
| **WATCH** | until Start | Reference Signbank clip of the target sign loops; gloss name shown; webcam preview live but idle. "Watch, then sign." Buttons: **Start**, **Skip**. |
| **READY** | ~1 s | "Ready?…" beat; arm `MediaRecorder`. |
| **RECORD** | exactly 4 s | Big "**Sign!**" + on-screen countdown **4 → 0**; `MediaRecorder` captures; a recording cursor sweeps the timeline strip. |
| **ANALYZE** | pipeline latency | "Analyzing…" animation while the clip runs the pipeline (§4). |
| **RESULT** | until user / auto | Detected segment(s) animate onto the timeline; top-10 spotted glosses listed; the target row turns **green if it is in the top-3**. Match → SUCCESS; miss → see §5. |

### Failure handling (§5 detail)
- **Match** (target ∈ top-3): celebrate, increment streak/score, advance to next
  random target.
- **Miss**: increment the attempt counter for this sign.
  - **Attempts < 3** → Retry: return to RECORD (reference clip still available to
    re-watch).
  - **Attempts == 3** → **REVEAL**: replay the reference clip slowly, mark the
    sign "revealed" (counts as not-matched in the score), reset streak,
    auto-advance to the next target.

## 4. Per-attempt inference pipeline

Reuses the `webcam/` code paths verbatim (`serverConvert`, `segInfer`,
`inferUpload`, `inferSpot`):

1. **Capture** — `MediaRecorder` produces a ~4 s webm (50 fps ideal).
2. **Convert** — `serverConvert(webm)` → `{ segVideoId, file: 25fps mp4 }`
   (native ffmpeg on the GPU box; ffmpeg.wasm fallback offline/oversize).
3. **Segment** — `segInfer(segVideoId)` → `[{start,end}]` → render on the
   timeline strip.
4. **Select sign segment** — choose the **longest** detected segment as the sign.
   Fallback when no segment is returned: a centered ~2 s window of the clip
   (e.g. `start = clipLen/2 − 1s`, `end = clipLen/2 + 1s`, clamped).
5. **Spot** — spotter `/upload` the mp4 → `/spot { videoId, start, end, topk:10 }`
   → `[{rank,gloss,score}]`.
6. **Match test** — `target gloss ∈ top-3 glosses`, compared after
   normalization (trim, lowercase; same normalization used to build the target
   pool in §5).

This deliberately exercises **both** servers (segmenter + spotter) and produces
the live timeline.

### Note on "live"
With batch inference the timeline cannot update *mid-sign*. "Live" is delivered
as: a recording cursor that sweeps in real time during the 4 s capture, and
detected segments that animate onto the timeline at RESULT. No frame-streaming
endpoint is implied or required.

## 5. Target selection

On load:

1. `GET {SPOTTER_BASE}/vocab` → the spotter's gloss label set (new endpoint, §6).
2. Intersect with `glosses_transformed.json` entries that have a non-empty
   Signbank `Video` URL (via the existing `glossSignbankVideo` lookup).
3. The intersection is the **target pool**. Pick uniformly at random, avoiding
   the last *N* targets (e.g. N=10) to prevent immediate repeats; reshuffle/cycle
   when exhausted.

This guarantees every target is both **demonstrable** (has a reference video) and
**achievable** (the spotter can return it). If `/vocab` is unreachable, the app
degrades to a clear error state (practice can't start) rather than silently
serving unwinnable targets — the vocab is load-critical, unlike the parent
variants' fail-quiet optional features.

## 6. Backend change (spotter)

Add one route to `infer_server.py` (signrep-spotter, on monsterfish):

```
GET /vocab  →  200  { "glosses": ["HUIS", "BOOM", ...] }
```

Returns the model's gloss label list (the same inventory `/spot` ranks over).
Deployed via the existing rsync-models-and-restart flow for the spotter unit.
Reverse-proxy (`/sign-spotter/`) and CORS already cover GET like `/spot`.
No segmenter changes. (See memory: *Updating spotter models on monsterfish*,
*Inference servers: Expect 100-continue hang* — GET avoids the latter.)

## 7. UI / UX

Clean, modern, large-type, color-coded by state, with smooth transitions. Built
with the frontend-design skill at implementation time. Rough layout:

```
┌───────────────────────────┬───────────────────────────┐
│  TARGET SIGN              │   YOUR WEBCAM             │
│  [looping Signbank video] │   [live preview]         │
│   gloss: "HUIS"           │   ④③②①  "Sign!"          │
├───────────────────────────┴───────────────────────────┤
│  timeline ▏▓▓▓▓ detected segment ▏      (cursor →)     │
├────────────────────────────────────────────────────────┤
│  Spotted: 1.HUIS ✓  2.…  3.…       Streak 4 · 12/15    │
│           [ Start ]   [ Skip ]   [ Retry ]             │
└────────────────────────────────────────────────────────┘
```

- **Target panel** — looping Signbank reference clip + gloss label.
- **Webcam panel** — live preview, **mirrored for the user's comfort** but the
  recorded stream is **un-mirrored** (inference needs un-mirrored, matching the
  webcam variant). Countdown overlay 4→0 and "Sign!" cue.
- **Timeline strip** — recording cursor during capture; detected segments
  animate in at RESULT, sign segment highlighted.
- **Results row** — top-10 spotted glosses with scores; target highlighted green
  on a top-3 match.
- **Scoreboard** — running streak and matched/revealed counts.
- **Controls** — Start, Skip, Retry, Stop session.

## 8. Defaults

- **3 attempts** then reveal.
- **Endless** session with a running scoreboard (streak, matched, revealed) and a
  Stop button — not a fixed N-sign round.
- Webcam preview mirrored (CSS), recording un-mirrored.
- Match threshold = **top-3**.
- Recording window = **exactly 4 s**.

## 9. Out of scope (YAGNI)

- No EAF/timeline editor, tiers, or autosave (removed from the fork).
- No accounts, persistence of scores across sessions, or leaderboards.
- No true frame-streaming inference (no new WebSocket/chunked endpoints).
- No curated lesson plans or difficulty tiers (pool is random over the
  achievable vocabulary).

## 10. Risks / open items

- **/vocab content** must match the model's actual class labels and normalize to
  the same form as `/spot` outputs and the Signbank gloss keys — otherwise the
  intersection is empty or targets mismatch. Verify label casing/format when the
  endpoint is added.
- **Per-attempt latency** (upload + server convert + segment + spot) is several
  seconds; the ANALYZE state must mask it gracefully. Acceptable for practice;
  not a hard real-time path.
- **Segmenter on a 4 s clip** may return zero segments for short/quiet signs —
  handled by the centered-window fallback (§4 step 4).
```
