# SignRep Practice Web App — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an interactive NGT sign-repetition practice web app: show a random sign, capture a guided 4-second webcam repetition, segment + spot it, and advance on a top-3 match (reveal after 3 misses).

**Architecture:** A new static single-file app in `practice/`, forked in spirit from `webcam/` but rebuilt without the annotation editor. Pure logic (target pool, match test, segment pick, attempt/score state) lives in a DOM-free ES module `practice-core.js` with `node --test` coverage. The UI/state-machine and the inference clients (server-side ffmpeg convert → V-JEPA segment → SignRep spot, all copied from `webcam/index.html`) live in `index.html`. One backend addition: a `GET /vocab` route on the spotter so the app can build the achievable target pool.

**Tech Stack:** Plain HTML/CSS/ES modules (no build step), `MediaRecorder`/`getUserMedia`, `fetch`/`XHR` to the `signcollect.nl` inference proxies, `node:test` for unit tests, Python `http.server` for local preview. Backend: the spotter's `infer_server.py` (Python `http.server`) on monsterfish + Apache reverse proxy.

**Spec:** `docs/superpowers/specs/2026-06-13-signrep-practice-design.md`

---

## File structure

```
practice/
  index.html               # UI, state machine, inference clients, camera capture
  practice-core.js         # pure logic (ES module, no DOM/network) — unit-tested
  practice-core.test.mjs   # node:test unit tests for practice-core
  glosses_transformed.json # Signbank gloss→video lookup (copied from webcam/)
  README.md                # variant docs
```

Deliberately **omitted** from the fork (YAGNI per spec §9): the EAF/timeline
editor, tiers, autosave, `mod.js` frame decoder (no frame scrubbing needed), and
the `vendor/` ffmpeg.wasm offline fallback (practice is useless offline — it
needs the inference servers — so server-side convert only; a convert failure is a
plain error state).

**Reused inference functions** (copied verbatim from `webcam/index.html`, then
trimmed of wasm-fallback branches): `serverConvert` (`webcam/index.html:648-675`),
`segInfer` (`681-701`), `inferUpload` (`627-631`), `inferSpot` (`633-642`),
`glossSignbankVideo` (`747-751`). The `INFER_BASE`/`SEG_BASE` host-switch
(`617-623`) is copied as-is (localhost:8000 / :8001 for dev, signcollect proxies
in prod).

---

## Task 0: Branch + scaffold the practice/ directory

**Files:**
- Create branch `signrep-practice`
- Create: `practice/glosses_transformed.json` (copy)
- Create: `practice/README.md`

- [ ] **Step 1: Create the branch off the current branch**

Run:
```bash
cd /web/annotation-tool
git checkout -b signrep-practice
```
Expected: `Switched to a new branch 'signrep-practice'`

- [ ] **Step 2: Scaffold the directory and copy the gloss data**

Run:
```bash
mkdir -p /web/annotation-tool/practice
cp /web/annotation-tool/webcam/glosses_transformed.json /web/annotation-tool/practice/glosses_transformed.json
ls -la /web/annotation-tool/practice
```
Expected: `glosses_transformed.json` present (~11 MB).

- [ ] **Step 3: Write a minimal README placeholder**

Create `practice/README.md`:
```markdown
# Annotation Tool — SignRep Practice Variant

Interactive NGT sign-repetition practice. Shows a random sign, you repeat it on
webcam in a guided 4-second capture, and the SignRep spotter scores your
attempt — advancing on a top-3 match, revealing after 3 misses. Built on the v3
server-side conversion + V-JEPA segmentation + SignRep spotting pipeline (see
`../v3/README.md`).

**Live URL:** https://signcollect.nl/annotation-tool/practice/

(Full docs added at the end of implementation.)
```

- [ ] **Step 4: Commit**

```bash
cd /web/annotation-tool
git add practice/glosses_transformed.json practice/README.md
git commit -m "practice: scaffold directory + copy gloss data"
```

---

## Task 1: Pure logic module `practice-core.js` (TDD)

**Files:**
- Create: `practice/practice-core.js`
- Test: `practice/practice-core.test.mjs`

This module is DOM-free and network-free so it is unit-testable under `node --test`.

- [ ] **Step 1: Write the failing tests**

Create `practice/practice-core.test.mjs`:
```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  normalizeGloss, buildTargetPool, isTopKMatch,
  pickSignSegment, pickNextTarget, applyResult, MAX_ATTEMPTS,
} from './practice-core.js';

test('normalizeGloss trims and lowercases', () => {
  assert.equal(normalizeGloss('  HUIS '), 'huis');
  assert.equal(normalizeGloss(null), '');
});

test('buildTargetPool keeps only vocab glosses that have a Signbank video', () => {
  const entries = [
    { 'Annotation ID Gloss: Dutch': 'HUIS', Video: 'huis.mp4' },
    { 'Annotation ID Gloss: Dutch': 'BOOM', Video: '' },        // no video
    { 'Annotation ID Gloss: Dutch': 'AUTO', Video: 'auto.mp4' },
  ];
  const vocab = ['HUIS', 'BOOM', 'ONBEKEND'];                   // BOOM no video, ONBEKEND not in entries
  const pool = buildTargetPool(vocab, entries);
  assert.deepEqual(pool, [{ gloss: 'HUIS', video: 'huis.mp4' }]);
});

test('isTopKMatch matches within k, case-insensitively', () => {
  const spotted = [{ gloss: 'BOOM' }, { gloss: 'huis' }, { gloss: 'AUTO' }, { gloss: 'FIETS' }];
  assert.equal(isTopKMatch('HUIS', spotted, 3), true);
  assert.equal(isTopKMatch('FIETS', spotted, 3), false);       // rank 4, outside top-3
  assert.equal(isTopKMatch('HUIS', ['BOOM', 'HUIS'], 3), true); // accepts string[] too
});

test('pickSignSegment picks the longest segment', () => {
  const segs = [{ start: 0.2, end: 0.5 }, { start: 1.0, end: 2.4 }, { start: 3.0, end: 3.3 }];
  assert.deepEqual(pickSignSegment(segs, 4), { start: 1.0, end: 2.4 });
});

test('pickSignSegment falls back to a centered window when no segments', () => {
  assert.deepEqual(pickSignSegment([], 4, 2), { start: 1, end: 3 });
  assert.deepEqual(pickSignSegment([], 1, 2), { start: 0, end: 1 }); // clamped to clip
});

test('pickNextTarget avoids recent picks, deterministic via rng', () => {
  const pool = [{ gloss: 'A', video: 'a' }, { gloss: 'B', video: 'b' }, { gloss: 'C', video: 'c' }];
  // recent excludes A and B -> only C remains, rng=0 -> C
  assert.equal(pickNextTarget(pool, ['A', 'b'], () => 0).gloss, 'C');
  // all recent -> falls back to full pool
  assert.equal(pickNextTarget(pool, ['A', 'B', 'C'], () => 0).gloss, 'A');
  assert.equal(pickNextTarget([], [], () => 0), null);
});

test('applyResult drives match/retry/reveal', () => {
  assert.deepEqual(applyResult({ attempts: 0 }, true), { outcome: 'match', attempts: 1 });
  assert.deepEqual(applyResult({ attempts: 0 }, false), { outcome: 'retry', attempts: 1 });
  assert.deepEqual(applyResult({ attempts: 1 }, false), { outcome: 'retry', attempts: 2 });
  assert.deepEqual(applyResult({ attempts: 2 }, false), { outcome: 'reveal', attempts: 3 });
  assert.equal(MAX_ATTEMPTS, 3);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:
```bash
cd /web/annotation-tool/practice && node --test
```
Expected: FAIL — cannot find module `./practice-core.js` (or export errors).

- [ ] **Step 3: Implement `practice-core.js`**

Create `practice/practice-core.js`:
```js
// Pure practice logic — no DOM, no network. Unit-tested in practice-core.test.mjs.

export const MAX_ATTEMPTS = 3;

export function normalizeGloss(g) {
  return String(g == null ? '' : g).trim().toLowerCase();
}

// vocab: string[] of glosses the spotter knows (/vocab).
// glossEntries: parsed glosses_transformed.json (array of Signbank records).
// Returns [{gloss, video}] for glosses that are BOTH in vocab AND have a video,
// preserving vocab order and de-duplicating by normalized gloss.
export function buildTargetPool(vocab, glossEntries) {
  const byNorm = new Map();
  for (const e of glossEntries) {
    const name = e['Annotation ID Gloss: Dutch'];
    const video = e.Video;
    if (!name || !video) continue;
    byNorm.set(normalizeGloss(name), { gloss: name, video });
  }
  const pool = [];
  const seen = new Set();
  for (const v of vocab) {
    const key = normalizeGloss(v);
    if (seen.has(key)) continue;
    const hit = byNorm.get(key);
    if (hit) { pool.push(hit); seen.add(key); }
  }
  return pool;
}

// Is targetGloss within the first k spotted entries? `spotted` items may be
// {gloss,...} objects or plain strings.
export function isTopKMatch(targetGloss, spotted, k = 3) {
  const t = normalizeGloss(targetGloss);
  return spotted.slice(0, k).some(s => normalizeGloss(s && s.gloss != null ? s.gloss : s) === t);
}

// The sign = the longest detected segment; fall back to a centered window of
// width `fallbackWidth` (clamped to the clip) when nothing is detected.
export function pickSignSegment(segments, clipDuration, fallbackWidth = 2) {
  if (Array.isArray(segments) && segments.length) {
    return segments.reduce((a, b) => ((b.end - b.start) > (a.end - a.start) ? b : a));
  }
  const mid = clipDuration / 2;
  const half = fallbackWidth / 2;
  return { start: Math.max(0, mid - half), end: Math.min(clipDuration, mid + half) };
}

// Pick a random target from pool, avoiding glosses in `recent`. rng() ∈ [0,1).
export function pickNextTarget(pool, recent, rng) {
  if (!pool.length) return null;
  const avoid = new Set((recent || []).map(normalizeGloss));
  let candidates = pool.filter(p => !avoid.has(normalizeGloss(p.gloss)));
  if (!candidates.length) candidates = pool;
  return candidates[Math.floor(rng() * candidates.length)];
}

// Given current per-sign state and whether this attempt matched, decide outcome.
export function applyResult(state, matched) {
  const attempts = state.attempts + 1;
  if (matched) return { outcome: 'match', attempts };
  if (attempts >= MAX_ATTEMPTS) return { outcome: 'reveal', attempts };
  return { outcome: 'retry', attempts };
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run:
```bash
cd /web/annotation-tool/practice && node --test
```
Expected: PASS — all tests green.

- [ ] **Step 5: Commit**

```bash
cd /web/annotation-tool
git add practice/practice-core.js practice/practice-core.test.mjs
git commit -m "practice: pure logic module (target pool, match, segment, attempts) + tests"
```

---

## Task 2: Spotter `GET /vocab` endpoint + Apache proxy

**Files (on monsterfish, signrep-spotter repo — not in this repo):**
- Modify: `infer_server.py` (the spotter HTTP handler)
- Verify/Modify: Apache vhost for `/sign-spotter/`

> Requires access to monsterfish per memory *Production inference on monsterfish*
> and *Updating spotter models on monsterfish*. Apply, then restart the `--user`
> spotter unit. Use **GET** to avoid the HTTP/1.0 100-continue hang noted in
> memory *Inference servers: Expect 100-continue hang*.

- [ ] **Step 1: Add the route to the request handler**

In `infer_server.py`, locate the `do_GET` handler (alongside the existing
`/video/...` or health routes) and add, before the 404 fallthrough:
```python
elif self.path == '/vocab' or self.path.startswith('/vocab?'):
    # GLOSS_LABELS is the model's ordered class list (the same inventory /spot
    # ranks over). Adjust the name to match this module's label variable.
    body = json.dumps({'glosses': list(GLOSS_LABELS)}).encode('utf-8')
    self.send_response(200)
    self.send_header('Content-Type', 'application/json')
    self.send_header('Content-Length', str(len(body)))
    self.send_header('Access-Control-Allow-Origin', '*')
    self.end_headers()
    self.wfile.write(body)
    return
```
If the handler shares a CORS preflight branch for other routes, ensure `/vocab`
is covered the same way as `/spot`.

- [ ] **Step 2: Restart the spotter unit and verify locally on monsterfish**

Run (on monsterfish):
```bash
systemctl --user restart <spotter-unit>     # name per the spotter unit file
curl -s http://localhost:8000/vocab | head -c 300; echo
```
Expected: JSON `{"glosses": ["...", ...]}` with a non-empty list.

- [ ] **Step 3: Verify the Apache proxy forwards `/vocab` (adjust only if needed)**

The `/sign-spotter/` vhost should proxy the whole prefix (e.g.
`ProxyPass /sign-spotter/ http://127.0.0.1:8000/`), which already covers
`/vocab`. Verify from a machine that can reach the proxy:
```bash
curl -s https://signcollect.nl/sign-spotter/vocab | head -c 300; echo
```
Expected: the same JSON. **If** it 404s or is blocked by a per-path
`<Location>`/`ProxyPass` allowlist, add `/sign-spotter/vocab` alongside the
existing `/sign-spotter/spot` and `/sign-spotter/upload` entries, then
`apachectl configtest && systemctl reload apache2` (or `httpd`). Re-run the curl
to confirm 200.

- [ ] **Step 4: Record the outcome (no repo commit here)**

This task changes server-side files outside this repo; there is nothing to commit
in `annotation-tool`. Note in the PR/description that `/vocab` is live and the
vhost was verified/adjusted.

---

## Task 3: `index.html` skeleton — boot, vocab fetch, target pool, camera

**Files:**
- Create: `practice/index.html`

- [ ] **Step 1: Create the page shell with the inference client functions**

Create `practice/index.html`. Start with the document, the host-switch constants,
and the inference clients copied from `webcam/index.html` (trim wasm-fallback
branches from `serverConvert`/`segInfer` — keep only the server path; on failure
they throw and the caller shows an error):

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>SignRep Practice</title>
  <style>/* styles added in Task 6 */</style>
</head>
<body>
  <main id="app">
    <section id="targetPanel">
      <video id="refVideo" loop muted playsinline></video>
      <div id="refGloss">—</div>
    </section>
    <section id="camPanel">
      <video id="camPreview" autoplay muted playsinline></video>
      <div id="overlay" aria-live="polite"></div>
    </section>
    <section id="timelineStrip"><div id="cursor"></div><div id="segLayer"></div></section>
    <section id="resultsRow">
      <ol id="spotList"></ol>
      <div id="scoreboard">Streak 0 · 0/0</div>
    </section>
    <section id="controls">
      <button id="startBtn" type="button">Start</button>
      <button id="skipBtn" type="button">Skip</button>
      <button id="retryBtn" type="button" hidden>Retry</button>
      <button id="stopBtn" type="button">Stop</button>
    </section>
    <div id="errorBar" hidden></div>
  </main>

  <script type="module">
    import {
      buildTargetPool, isTopKMatch, pickSignSegment, pickNextTarget,
      applyResult, normalizeGloss, MAX_ATTEMPTS,
    } from './practice-core.js';

    // ---- Inference servers (copied from webcam/index.html:617-623) ----
    const isLocal = location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    const INFER_BASE = isLocal ? 'http://localhost:8000' : 'https://signcollect.nl/sign-spotter';
    const SEG_BASE   = isLocal ? 'http://localhost:8001' : 'https://signcollect.nl/sign-segmenter';

    // ---- Inference clients (copied from webcam/index.html, server path only) ----
    async function inferUpload(blob) {                                   // :627-631
      const r = await fetch(INFER_BASE + '/upload', { method: 'POST', body: blob });
      if (!r.ok) throw new Error('upload_failed');
      return (await r.json()).videoId;
    }
    async function inferSpot(videoId, start, end) {                      // :633-642
      const r = await fetch(INFER_BASE + '/spot', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ videoId, start, end, topk: 10 }),
      });
      if (r.status === 409) throw new Error('no_video');
      if (!r.ok) throw new Error('spot_failed');
      return (await r.json()).glosses || [];
    }
    // serverConvert: upload original webm to the segmenter, get back {videoId, mp4}.
    // (copied from webcam/index.html:648-675, convert-modal calls removed)
    async function serverConvert(file) {
      const videoId = await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', SEG_BASE + '/upload');
        xhr.responseType = 'json';
        xhr.timeout = 290000;
        xhr.onload = () => (xhr.status === 200 && xhr.response && xhr.response.videoId)
          ? resolve(xhr.response.videoId) : reject(new Error('upload HTTP ' + xhr.status));
        xhr.onerror = () => reject(new Error('upload network error'));
        xhr.ontimeout = () => reject(new Error('upload timeout'));
        xhr.send(file);
      });
      const r = await fetch(SEG_BASE + '/video/' + videoId);
      if (!r.ok) throw new Error('video download HTTP ' + r.status);
      const buf = await r.arrayBuffer();
      return { videoId, file: new File([buf], 'attempt.mp4', { type: 'video/mp4' }) };
    }
    // segInfer by videoId → [{start,end}] (copied from webcam/index.html:681-701,
    // videoId path only; throws on failure).
    async function segInfer(videoId) {
      const r = await fetch(SEG_BASE + '/segment', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ videoId }),
      });
      if (!r.ok) throw new Error('segment HTTP ' + r.status);
      const data = await r.json();
      const segs = Array.isArray(data.segments) ? data.segments : [];
      return segs.map(([s, e]) => ({ start: s, end: e }));
    }

    // ---- Boot: load glosses + vocab, build target pool ----
    let GLOSS_ENTRIES = [], TARGET_POOL = [], recent = [];
    const errorBar = document.getElementById('errorBar');
    function fatal(msg) { errorBar.hidden = false; errorBar.textContent = msg; }

    async function boot() {
      try {
        const [entries, vocabRes] = await Promise.all([
          fetch('glosses_transformed.json').then(r => r.json()),
          fetch(INFER_BASE + '/vocab').then(r => { if (!r.ok) throw new Error('vocab ' + r.status); return r.json(); }),
        ]);
        GLOSS_ENTRIES = entries;
        TARGET_POOL = buildTargetPool(vocabRes.glosses || [], entries);
        if (!TARGET_POOL.length) { fatal('No practiceable signs (vocab ∩ Signbank video is empty).'); return; }
        await initCamera();
        // Task 4 wires the state machine start here.
      } catch (e) {
        fatal('Cannot start practice: ' + e.message + '. The spotter /vocab endpoint must be reachable.');
      }
    }

    // ---- Camera (copied pattern from webcam/index.html:3794-3818) ----
    let camStream = null;
    async function initCamera() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia)
        throw new Error('No webcam access (getUserMedia). Use Chrome or Edge over HTTPS.');
      camStream = await navigator.mediaDevices.getUserMedia({
        video: { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 50 } },
        audio: false,
      });
      document.getElementById('camPreview').srcObject = camStream;
    }

    boot();
  </script>
</body>
</html>
```

- [ ] **Step 2: Verify the page boots and builds a pool (manual, browser)**

Pre-req: spotter/segmenter reachable at the localhost dev ports (or temporarily
point `INFER_BASE`/`SEG_BASE` at the prod proxies for a read-only `/vocab` check).
Run:
```bash
cd /web/annotation-tool/practice && python3 -m http.server 8799
```
Open `http://localhost:8799/` in Chrome. Expected: no fatal error bar; the camera
prompt appears and the preview shows your webcam. In DevTools console run
`TARGET_POOL?.length` is not accessible (module scope) — instead confirm via a
temporary `console.log(TARGET_POOL.length)` in `boot()` that it is > 0, then
remove the log. If `/vocab` is unreachable you should see the fatal message — that
is the intended load-critical behavior (spec §5).

- [ ] **Step 3: Commit**

```bash
cd /web/annotation-tool
git add practice/index.html
git commit -m "practice: page shell, inference clients, vocab-driven target pool, camera"
```

---

## Task 4: The practice state machine (WATCH → READY → RECORD → ANALYZE → RESULT)

**Files:**
- Modify: `practice/index.html` (extend the module script)

- [ ] **Step 1: Add the state machine, 4s recorder, and round flow**

Append inside the `<script type="module">`, after `initCamera`:
```js
    // ---- DOM refs ----
    const refVideo = document.getElementById('refVideo');
    const refGloss = document.getElementById('refGloss');
    const overlay  = document.getElementById('overlay');
    const cursor   = document.getElementById('cursor');
    const segLayer = document.getElementById('segLayer');
    const spotList = document.getElementById('spotList');
    const scoreboard = document.getElementById('scoreboard');
    const startBtn = document.getElementById('startBtn');
    const skipBtn  = document.getElementById('skipBtn');
    const retryBtn = document.getElementById('retryBtn');
    const stopBtn  = document.getElementById('stopBtn');

    const RECORD_MS = 4000, MIME = pickMime();
    let current = null;          // {gloss, video}
    let attempts = 0;
    let score = { matched: 0, revealed: 0, streak: 0 };
    let running = false;

    function pickMime() {
      for (const m of ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'])
        if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m)) return m;
      return '';
    }
    const sleep = (ms) => new Promise(res => { let t0; const tick = (t) => { if (t0 == null) t0 = t; (t - t0 >= ms) ? res() : requestAnimationFrame(tick); }; requestAnimationFrame(tick); });

    function setOverlay(html) { overlay.innerHTML = html; }
    function renderScore() { scoreboard.textContent = `Streak ${score.streak} · ${score.matched}/${score.matched + score.revealed}`; }

    // WATCH: show the reference clip, wait for Start.
    async function nextRound() {
      attempts = 0;
      current = pickNextTarget(TARGET_POOL, recent, Math.random);
      recent = [current.gloss, ...recent].slice(0, 10);
      refGloss.textContent = current.gloss;
      refVideo.src = current.video; refVideo.play().catch(() => {});
      segLayer.innerHTML = ''; spotList.innerHTML = '';
      setOverlay('Watch, then press <b>Start</b>');
      retryBtn.hidden = true; startBtn.hidden = false;
    }

    async function runAttempt() {
      startBtn.hidden = true; retryBtn.hidden = true;
      setOverlay('Ready?…'); await sleep(900);                 // READY
      const blob = await recordClip();                          // RECORD (4s)
      setOverlay('Analyzing…');                                 // ANALYZE
      try {
        const { spotted, segments, clipDur } = await analyze(blob);
        showResult(spotted, segments, clipDur);                 // RESULT
      } catch (e) {
        setOverlay('Analysis failed — Retry'); retryBtn.hidden = false;
      }
    }

    // RECORD exactly RECORD_MS, animating the cursor across the strip.
    function recordClip() {
      return new Promise((resolve, reject) => {
        const rec = new MediaRecorder(camStream, MIME ? { mimeType: MIME } : undefined);
        const chunks = [];
        rec.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };
        rec.onstop = () => resolve(new Blob(chunks, { type: MIME || 'video/webm' }));
        rec.onerror = (e) => reject(e.error || new Error('record_error'));
        let n = 4; setOverlay('<b>Sign!</b> <span class="count">' + n + '</span>');
        const iv = setInterval(() => { n--; if (n >= 0) setOverlay('<b>Sign!</b> <span class="count">' + n + '</span>'); }, 1000);
        cursor.style.transition = 'none'; cursor.style.left = '0%';
        requestAnimationFrame(() => { cursor.style.transition = `left ${RECORD_MS}ms linear`; cursor.style.left = '100%'; });
        rec.start();
        setTimeout(() => { clearInterval(iv); rec.stop(); }, RECORD_MS);
      });
    }
```

- [ ] **Step 2: Wire the buttons and kick off the first round in `boot()`**

In `boot()`, replace the `// Task 4 wires the state machine start here.` comment with:
```js
        renderScore();
        startBtn.onclick = () => { running = true; runAttempt(); };
        retryBtn.onclick = () => runAttempt();
        skipBtn.onclick  = () => { score.streak = 0; renderScore(); nextRound(); };
        stopBtn.onclick  = () => { running = false; setOverlay('Stopped. Press Skip for a new sign.'); };
        nextRound();
```

- [ ] **Step 3: Verify the timing loop (manual, browser)**

Reload `http://localhost:8799/`. Expected: a reference clip loops with its gloss
label; pressing **Start** shows "Ready?…", then "Sign!" with a 4→0 countdown while
the cursor sweeps left→right over ~4s; then "Analyzing…". (Analysis wiring lands
in Task 5 — for now `analyze` is undefined, so you'll see "Analysis failed —
Retry"; that is expected at this step.) **Skip** loads a different sign.

- [ ] **Step 4: Commit**

```bash
cd /web/annotation-tool
git add practice/index.html
git commit -m "practice: 4s capture state machine (watch/ready/record/analyze) + round flow"
```

---

## Task 5: Per-attempt inference + match decision

**Files:**
- Modify: `practice/index.html` (extend the module script)

- [ ] **Step 1: Add `analyze()` and the result/decision handler**

Append inside the module script:
```js
    // Pipeline: webm → serverConvert → segment → pick sign → spot.
    async function analyze(blob) {
      const { videoId, file } = await serverConvert(blob);
      const segments = await segInfer(videoId).catch(() => []);   // tolerate seg failure
      const clipDur = RECORD_MS / 1000;
      const seg = pickSignSegment(segments, clipDur, 2);
      const spotVideoId = await inferUpload(file);
      const spotted = await inferSpot(spotVideoId, seg.start, seg.end);
      return { spotted, segments, clipDur, seg };
    }

    function showResult(spotted, segments, clipDur) {
      // Timeline: draw detected segments.
      segLayer.innerHTML = '';
      for (const s of segments) {
        const bar = document.createElement('div');
        bar.className = 'seg';
        bar.style.left = (100 * s.start / clipDur) + '%';
        bar.style.width = (100 * (s.end - s.start) / clipDur) + '%';
        segLayer.appendChild(bar);
      }
      // Spotted list, target highlighted if top-3.
      const matched = isTopKMatch(current.gloss, spotted, 3);
      spotList.innerHTML = '';
      spotted.slice(0, 10).forEach((g, i) => {
        const li = document.createElement('li');
        const name = g && g.gloss != null ? g.gloss : g;
        li.textContent = (i + 1) + '. ' + name + (g.score != null ? '  ' + Number(g.score).toFixed(2) : '');
        if (normalizeGloss(name) === normalizeGloss(current.gloss)) li.classList.add('hit');
        spotList.appendChild(li);
      });
      decide(matched);
    }

    async function decide(matched) {
      const res = applyResult({ attempts }, matched); attempts = res.attempts;
      if (res.outcome === 'match') {
        score.matched++; score.streak++; renderScore();
        setOverlay('✅ Matched <b>' + current.gloss + '</b>!');
        await sleep(1400); nextRound();
      } else if (res.outcome === 'retry') {
        setOverlay('❌ Not in top-3 (attempt ' + attempts + '/' + MAX_ATTEMPTS + ') — Retry');
        retryBtn.hidden = false;
      } else { // reveal
        score.revealed++; score.streak = 0; renderScore();
        setOverlay('Here it is — watch closely');
        refVideo.playbackRate = 0.5; refVideo.currentTime = 0; refVideo.play().catch(() => {});
        await sleep(3000); refVideo.playbackRate = 1; nextRound();
      }
    }
```

- [ ] **Step 2: Verify a full attempt end-to-end (manual, browser)**

Pre-req: spotter + segmenter reachable (dev ports or prod proxies). Reload the
page, press **Start**, perform the shown sign during the 4s window. Expected:
after "Analyzing…", detected segment bars appear on the strip and a top-10 list
renders; if your sign is in the top-3 the target row is highlighted and the app
advances after ~1.4s; otherwise it offers **Retry**, and after 3 misses it
slow-replays the reference and advances. Confirm the scoreboard updates.

- [ ] **Step 3: Commit**

```bash
cd /web/annotation-tool
git add practice/index.html
git commit -m "practice: per-attempt convert→segment→spot pipeline + top-3 match decision"
```

---

## Task 6: Visual design pass (frontend-design)

**Files:**
- Modify: `practice/index.html` (the `<style>` block + minor markup classes)

> Use the **frontend-design** skill for this task to produce a distinctive,
> polished, fluid UI (not generic). Keep all element IDs/behaviors from Tasks
> 3–5 intact; this task only adds styling, transitions, and layout.

- [ ] **Step 1: Implement the styles**

Fill the `<style>` block to realize spec §7: two-panel hero (reference left,
webcam right, both large), a countdown overlay with a big tabular-numeral
`.count`, a horizontal `#timelineStrip` with an animated `#cursor` and `.seg`
bars, a `#spotList` with a green `.hit` row, a scoreboard, and a hidden-by-default
`#errorBar` styled as a banner. Requirements:
- Webcam preview mirrored for the user: `#camPreview { transform: scaleX(-1); }`
  (display only — the recorded stream is unaffected, so inference stays
  un-mirrored).
- Smooth state transitions (opacity/scale) on overlay changes; color-code states
  (neutral/recording/analyzing/success/miss).
- Responsive down to a laptop screen; large readable type.

- [ ] **Step 2: Verify the look and the mirror invariant (manual, browser)**

Reload. Expected: a polished two-panel layout; the webcam preview is mirrored but
a recorded attempt still spots correctly (mirroring is CSS-only). Run one full
match and one full reveal to confirm transitions read well.

- [ ] **Step 3: Commit**

```bash
cd /web/annotation-tool
git add practice/index.html
git commit -m "practice: polished two-panel UI, state transitions, mirrored preview"
```

---

## Task 7: README + final verification

**Files:**
- Modify: `practice/README.md`

- [ ] **Step 1: Write the full README**

Replace `practice/README.md` with complete docs modeled on `webcam/README.md`:
purpose (practice loop), requirements (Chrome/Edge, webcam, HTTPS), how it works
(watch → 4s capture → spot → advance/reveal), the `/vocab`-driven target pool, the
inference pipeline (link `../v3/README.md`), files-in-directory table
(`index.html`, `practice-core.js`, `glosses_transformed.json`), and local preview
(`python3 -m http.server`). Note the spotter `/vocab` endpoint dependency.

- [ ] **Step 2: Run the unit tests once more**

Run:
```bash
cd /web/annotation-tool/practice && node --test
```
Expected: all `practice-core` tests PASS.

- [ ] **Step 3: Full manual smoke (browser)**

With inference reachable: load the app, complete one match round and one 3-miss
reveal round, press Skip and Stop. Expected: no console errors; scoreboard
accurate; fatal banner appears only when `/vocab` is unreachable.

- [ ] **Step 4: Commit**

```bash
cd /web/annotation-tool
git add practice/README.md
git commit -m "practice: documentation + final verification"
```

---

## Self-review notes (coverage vs spec)

- Spec §2 scope/deploy → Task 0. §3 state machine → Task 4. §4 pipeline + segment
  pick → Task 5 (+ `pickSignSegment` Task 1). §5 target pool/vocab → Tasks 1+3.
  §6 backend `/vocab` + Apache → Task 2. §7 UI → Task 6. §8 defaults
  (3 attempts/top-3/4s/endless) → encoded in Tasks 1,4,5. §9 YAGNI omissions →
  honored in file structure. §10 risks (vocab label format, latency masking, empty
  segments) → Task 2 step 3, ANALYZE overlay, `pickSignSegment` fallback.
- No placeholders: all code steps contain full code; copied functions cite exact
  `webcam/index.html` source ranges and are reproduced in Task 3.
- Type consistency: `{gloss, video}` pool items, `{start, end}` segments, and
  `spotted` (objects-or-strings) are handled uniformly across `practice-core.js`
  and `index.html`.
```
