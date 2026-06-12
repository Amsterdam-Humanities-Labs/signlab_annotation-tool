# annotation-tool v2 — SignRep gloss spotting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In a new `v2/` copy of the annotation tool, run the SignRep gloss spotter on each timeline segment — spinner while running, top-10 dropdown when done, click-to-fill — backed by a warm Python inference server reverse-proxied over HTTPS.

**Architecture:** A persistent `http.server`-based inference server in `/home/gomer/signrep-spotter/infer_server.py` loads the `Spotter` model once and exposes `POST /upload` (whole video once → `videoId`) and `POST /spot` (`{videoId,start,end,topk}` → top-k glosses). `v2/index.html` uploads the normalized video once, fires a spot request when a box is created, and renders a per-box panel (spinner / top-10 / error) under each box. Apache reverse-proxies `https://signcollect.nl/sign-spotter/` to the localhost inference port.

**Tech Stack:** Python 3.12 stdlib `http.server` (no new deps), the existing `spotter.py`/`sltk`/torch venv, vanilla browser JS (single-file app), Apache reverse proxy + systemd.

---

## File Structure

| File | Create/Modify | Responsibility |
|------|---------------|----------------|
| `/web/annotation-tool/v2/` (whole tree) | Create (copy) | Isolated v2 of the tool; all frontend changes happen here |
| `/home/gomer/signrep-spotter/infer_server.py` | Create | Warm inference HTTP server: `/health`, `/upload`, `/spot`, CORS |
| `/home/gomer/signrep-spotter/test_infer_server.py` | Create | Stdlib `unittest` tests using an injected fake spotter (no torch) |
| `/web/annotation-tool/v2/index.html` | Modify | Config, upload-once, per-box spot trigger + panel rendering + CSS |
| `/home/gomer/signrep-spotter/deploy/signrep-infer.service` | Create | systemd unit to keep the server warm |
| `/home/gomer/signrep-spotter/deploy/apache-sign-spotter.conf` | Create | Apache reverse-proxy snippet |

**Note on line numbers:** all `index.html` line numbers below refer to the file **before** edits and to the **`v2/` copy** (identical to the original at copy time). After an edit shifts lines, locate the next edit by its quoted anchor text, not the original number.

---

## Task 1: Create the v2 copy

**Files:**
- Create: `/web/annotation-tool/v2/` (recursive copy of the current tree)

- [ ] **Step 1: Copy the tree into v2/ (excluding any nested v2 and VCS dirs)**

Run:
```bash
cd /web/annotation-tool
mkdir -p v2
rsync -a --exclude 'v2' --exclude '.git' ./ v2/
```

- [ ] **Step 2: Verify the key files landed**

Run:
```bash
ls /web/annotation-tool/v2/index.html /web/annotation-tool/v2/mod.js /web/annotation-tool/v2/glosses_transformed.json
```
Expected: all three paths print (no "No such file").

- [ ] **Step 3: Commit**

```bash
cd /web/annotation-tool
git add v2
git commit -m "Add v2/ as a copy of the annotation tool for SignRep spotting work"
```

---

## Task 2: Inference server skeleton + /health (TDD)

**Files:**
- Create: `/home/gomer/signrep-spotter/infer_server.py`
- Test: `/home/gomer/signrep-spotter/test_infer_server.py`

The server must import **only stdlib at module top** so tests run without torch. The real `Spotter` is imported lazily inside `load_real_spotter()`. Tests inject a `FakeSpotter` by setting the module global `SPOTTER`.

- [ ] **Step 1: Write the failing test**

Create `/home/gomer/signrep-spotter/test_infer_server.py`:

```python
import json
import threading
import unittest
import urllib.request
import urllib.error
from http.server import ThreadingHTTPServer

import infer_server


def _start_server():
    infer_server.SPOTTER = infer_server.FakeSpotter()
    infer_server.VIDEOS.clear()
    srv = ThreadingHTTPServer(("127.0.0.1", 0), infer_server.Handler)
    t = threading.Thread(target=srv.serve_forever, daemon=True)
    t.start()
    return srv


def _req(srv, method, path, data=None, headers=None):
    host, port = srv.server_address
    url = f"http://{host}:{port}{path}"
    req = urllib.request.Request(url, data=data, method=method, headers=headers or {})
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, r.read(), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read(), dict(e.headers)


class HealthTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.srv = _start_server()

    @classmethod
    def tearDownClass(cls):
        cls.srv.shutdown()

    def test_health_ok(self):
        status, body, _ = _req(self.srv, "GET", "/health")
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(body)["ok"], True)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
cd /home/gomer/signrep-spotter && python3 -m unittest test_infer_server -v
```
Expected: FAIL — `ModuleNotFoundError: No module named 'infer_server'`.

- [ ] **Step 3: Write minimal implementation**

Create `/home/gomer/signrep-spotter/infer_server.py`:

```python
#!/usr/bin/env python3
"""Warm SignRep gloss-spotting inference server.

Loads the Spotter model once and serves:
  GET  /health   -> {"ok": true, "model": "fine-tuned"|"base"}
  POST /upload   -> body = video bytes; returns {"videoId": "<sha256>"}
  POST /spot     -> {"videoId","start","end","topk"} -> {"glosses": [...]}

Run inside the signrep-spotter venv:
    python infer_server.py --device cpu --port 8000 --host 127.0.0.1
Tests run it with a FakeSpotter (no torch) by setting the module global SPOTTER.
"""
import argparse
import hashlib
import json
import os
import sys
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse

# Module state (tests override SPOTTER before serving)
SPOTTER = None
VIDEOS = {}                       # videoId -> saved mp4 path
VIDEO_DIR = tempfile.mkdtemp(prefix="signrep_infer_")
SPOT_LOCK = threading.Lock()      # serialize model calls (CPU-bound / not thread-safe)


class FakeSpotter:
    """Stand-in for tests and `--fake`: no torch, deterministic top-k."""
    ft_loaded = (0, 0)

    def spot_segments(self, video_path, segments, topk=5):
        n = min(topk, 10)
        glosses = [
            {"rank": i + 1, "gloss": f"GLOSS{i}", "score": round(1.0 - 0.05 * i, 3)}
            for i in range(n)
        ]
        segs = [{"glosses": glosses, "label": glosses[0]["gloss"]} for _ in segments]
        return {"fps": 25.0, "num_frames": 0, "segments": segs}


def load_real_spotter(device):
    """Import and construct the real model. Imported lazily so the module top
    stays stdlib-only (tests never trigger this)."""
    from spotter import Spotter
    return Spotter(device=device)


def _cors(handler):
    handler.send_header("Access-Control-Allow-Origin", "*")
    handler.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
    handler.send_header("Access-Control-Allow-Headers", "Content-Type")


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        sys.stderr.write("  %s\n" % (fmt % args))

    def _send(self, code, obj=None, ctype="application/json", raw=None):
        body = raw if raw is not None else json.dumps(obj or {}).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        _cors(self)
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def do_OPTIONS(self):
        self.send_response(204)
        _cors(self)
        self.send_header("Content-Length", "0")
        self.end_headers()

    def do_GET(self):
        path = urlparse(self.path).path
        if path == "/health":
            model = "base" if SPOTTER is None or SPOTTER.ft_loaded is None else "fine-tuned"
            return self._send(200, {"ok": True, "model": model})
        return self._send(404, {"error": "not_found"})


def serve(host, port):
    srv = ThreadingHTTPServer((host, port), Handler)
    print(f"SignRep inference server on http://{host}:{port}/  (Ctrl+C to stop)")
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        print("\nbye")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--device", default="cpu")
    ap.add_argument("--port", type=int, default=8000)
    ap.add_argument("--host", default="127.0.0.1")
    ap.add_argument("--fake", action="store_true", help="use FakeSpotter (no model load)")
    args = ap.parse_args()
    global SPOTTER
    SPOTTER = FakeSpotter() if args.fake else load_real_spotter(args.device)
    serve(args.host, args.port)


if __name__ == "__main__":
    main()
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
cd /home/gomer/signrep-spotter && python3 -m unittest test_infer_server -v
```
Expected: PASS (`test_health_ok ... ok`).

- [ ] **Step 5: Checkpoint (signrep-spotter is not a git repo here)**

`/home/gomer/signrep-spotter` has no `.git`, so there is nothing to commit — the files are saved in place. Just confirm they exist:
```bash
ls /home/gomer/signrep-spotter/infer_server.py /home/gomer/signrep-spotter/test_infer_server.py
```
Expected: both paths print.

---

## Task 3: /upload endpoint (TDD)

**Files:**
- Modify: `/home/gomer/signrep-spotter/infer_server.py` (add `do_POST` + `/upload`)
- Test: `/home/gomer/signrep-spotter/test_infer_server.py` (add upload tests)

- [ ] **Step 1: Write the failing tests**

Append to `/home/gomer/signrep-spotter/test_infer_server.py` (before the `if __name__` line):

```python
class UploadTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.srv = _start_server()

    @classmethod
    def tearDownClass(cls):
        cls.srv.shutdown()

    def test_upload_returns_id(self):
        status, body, _ = _req(self.srv, "POST", "/upload", data=b"fake-video-bytes")
        self.assertEqual(status, 200)
        self.assertTrue(json.loads(body)["videoId"])

    def test_upload_idempotent(self):
        s1, b1, _ = _req(self.srv, "POST", "/upload", data=b"same-bytes")
        s2, b2, _ = _req(self.srv, "POST", "/upload", data=b"same-bytes")
        self.assertEqual(json.loads(b1)["videoId"], json.loads(b2)["videoId"])
```

- [ ] **Step 2: Run tests to verify they fail**

Run:
```bash
cd /home/gomer/signrep-spotter && python3 -m unittest test_infer_server.UploadTest -v
```
Expected: FAIL — `/upload` returns 404 (no `do_POST`).

- [ ] **Step 3: Add the implementation**

In `/home/gomer/signrep-spotter/infer_server.py`, add a `do_POST` method to `Handler` (place it right after `do_GET`):

```python
    def _read_body(self):
        length = int(self.headers.get("Content-Length", 0))
        return self.rfile.read(length) if length else b""

    def do_POST(self):
        path = urlparse(self.path).path
        if path == "/upload":
            data = self._read_body()
            if not data:
                return self._send(400, {"error": "empty_body"})
            vid = hashlib.sha256(data).hexdigest()[:16]
            if vid not in VIDEOS:
                dest = os.path.join(VIDEO_DIR, vid + ".mp4")
                with open(dest, "wb") as f:
                    f.write(data)
                VIDEOS[vid] = dest
            return self._send(200, {"videoId": vid})
        return self._send(404, {"error": "not_found"})
```

- [ ] **Step 4: Run tests to verify they pass**

Run:
```bash
cd /home/gomer/signrep-spotter && python3 -m unittest test_infer_server -v
```
Expected: PASS (health + both upload tests).

- [ ] **Step 5: Checkpoint (no git in signrep-spotter)**

No commit — `/home/gomer/signrep-spotter` is not a git repo. Files are saved in place; tests passing in Step 4 is the checkpoint.

---

## Task 4: /spot endpoint (TDD)

**Files:**
- Modify: `/home/gomer/signrep-spotter/infer_server.py` (extend `do_POST` with `/spot`)
- Test: `/home/gomer/signrep-spotter/test_infer_server.py` (add spot tests)

- [ ] **Step 1: Write the failing tests**

Append to `/home/gomer/signrep-spotter/test_infer_server.py` (before `if __name__`):

```python
class SpotTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.srv = _start_server()

    @classmethod
    def tearDownClass(cls):
        cls.srv.shutdown()

    def _post_json(self, path, obj):
        data = json.dumps(obj).encode("utf-8")
        return _req(self.srv, "POST", path, data=data,
                    headers={"Content-Type": "application/json"})

    def test_spot_returns_glosses(self):
        _, b, _ = _req(self.srv, "POST", "/upload", data=b"vid-for-spot")
        vid = json.loads(b)["videoId"]
        status, body, _ = self._post_json(
            "/spot", {"videoId": vid, "start": 0.5, "end": 1.2, "topk": 10})
        self.assertEqual(status, 200)
        glosses = json.loads(body)["glosses"]
        self.assertEqual(len(glosses), 10)
        self.assertIn("gloss", glosses[0])
        self.assertIn("score", glosses[0])

    def test_spot_unknown_video_409(self):
        status, body, _ = self._post_json(
            "/spot", {"videoId": "doesnotexist", "start": 0, "end": 1, "topk": 10})
        self.assertEqual(status, 409)
        self.assertEqual(json.loads(body)["error"], "no_video")


class CorsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.srv = _start_server()

    @classmethod
    def tearDownClass(cls):
        cls.srv.shutdown()

    def test_preflight(self):
        status, _, headers = _req(self.srv, "OPTIONS", "/spot")
        self.assertEqual(status, 204)
        self.assertEqual(headers.get("Access-Control-Allow-Origin"), "*")
```

- [ ] **Step 2: Run tests to verify they fail**

Run:
```bash
cd /home/gomer/signrep-spotter && python3 -m unittest test_infer_server.SpotTest -v
```
Expected: FAIL — `/spot` returns 404.

- [ ] **Step 3: Add the implementation**

In `/home/gomer/signrep-spotter/infer_server.py`, extend `do_POST` by adding a `/spot` branch **before** the final `return self._send(404, ...)`:

```python
        if path == "/spot":
            data = self._read_body()
            try:
                payload = json.loads(data or b"{}")
            except json.JSONDecodeError as e:
                return self._send(400, {"error": f"bad_json: {e}"})
            vid = payload.get("videoId")
            if vid not in VIDEOS:
                return self._send(409, {"error": "no_video"})
            start = float(payload.get("start", 0.0))
            end = float(payload.get("end", 0.0))
            topk = int(payload.get("topk", 10))
            with SPOT_LOCK:
                result = SPOTTER.spot_segments(
                    VIDEOS[vid], [(start, end, None)], topk=topk)
            glosses = result["segments"][0]["glosses"] if result["segments"] else []
            return self._send(200, {"glosses": glosses})
```

- [ ] **Step 4: Run tests to verify they pass**

Run:
```bash
cd /home/gomer/signrep-spotter && python3 -m unittest test_infer_server -v
```
Expected: PASS — all tests (health, upload x2, spot x2, cors).

- [ ] **Step 5: Smoke-test the real model is wired (optional, slow)**

Run (only if model assets are present; this loads ~400 MB and is slow):
```bash
cd /home/gomer/signrep-spotter && timeout 600 ./.venv/bin/python infer_server.py --device cpu --port 8765 &
sleep 60
curl -s http://127.0.0.1:8765/health
kill %1
```
Expected: `{"ok": true, "model": "fine-tuned"}` (or `"base"` if FT weights absent).

- [ ] **Step 6: Checkpoint (no git in signrep-spotter)**

No commit — `/home/gomer/signrep-spotter` is not a git repo. Files are saved in place; all tests passing in Step 4 is the checkpoint.

---

## Task 5: Frontend config + network helpers

**Files:**
- Modify: `/web/annotation-tool/v2/index.html` (globals block near line 565)

- [ ] **Step 1: Add config + helpers + state**

In `/web/annotation-tool/v2/index.html`, find this line (~565):

```javascript
    let currentSentenceRowId = null; // Store sentence row ID for status saves
```

Insert immediately **after** it:

```javascript
    // ---- SignRep gloss spotting (v2) ----
    // Same-origin HTTPS path on signcollect.nl (Apache reverse-proxies to the
    // warm inference server); localhost for dev. Mirrors the ISS_Server pattern.
    const INFER_BASE = (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')
      ? 'http://localhost:8000'
      : 'https://signcollect.nl/sign-spotter';
    let inferVideoId = null;        // id returned by /upload for the current video
    let inferUploadPromise = null;  // in-flight upload (so we upload only once)

    async function inferUpload(blob) {
      const r = await fetch(INFER_BASE + '/upload', { method: 'POST', body: blob });
      if (!r.ok) throw new Error('upload_failed');
      return (await r.json()).videoId;
    }

    async function inferSpot(videoId, start, end) {
      const r = await fetch(INFER_BASE + '/spot', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ videoId, start, end, topk: 10 })
      });
      if (r.status === 409) throw new Error('no_video');
      if (!r.ok) throw new Error('spot_failed');
      return (await r.json()).glosses || [];
    }

    // Upload the current video once; resolve to its videoId (or null if no video).
    async function ensureVideoUploaded() {
      if (inferVideoId) return inferVideoId;
      if (!videoBlob) return null;
      if (!inferUploadPromise) {
        inferUploadPromise = inferUpload(videoBlob)
          .then(id => { inferVideoId = id; return id; })
          .catch(e => { inferUploadPromise = null; throw e; });
      }
      return inferUploadPromise;
    }

    // Run gloss spotting for one segment, updating sub.spotState/spotResults and
    // re-rendering. Supersedes any prior in-flight request for the same sub.
    async function runSpot(sub, _retried) {
      if (!INFER_BASE) return;
      sub.spotState = 'loading';
      sub.spotResults = null;
      const reqId = (sub._spotReq = (sub._spotReq || 0) + 1);
      renderAll();
      try {
        const vid = await ensureVideoUploaded();
        if (!vid) { if (reqId === sub._spotReq) { sub.spotState = 'error'; renderAll(); } return; }
        const glosses = await inferSpot(vid, sub.start, sub.end);
        if (reqId !== sub._spotReq) return; // superseded by a newer re-run
        sub.spotState = 'done';
        sub.spotResults = glosses;
        renderAll();
      } catch (err) {
        if (err && err.message === 'no_video' && !_retried) {
          inferVideoId = null; inferUploadPromise = null; // server lost it; re-upload
          return runSpot(sub, true);
        }
        console.warn('[spot] failed', err);
        if (reqId === sub._spotReq) { sub.spotState = 'error'; renderAll(); }
      }
    }
```

- [ ] **Step 2: Verify the file still parses (no syntax error)**

Run:
```bash
node --check /web/annotation-tool/v2/index.html 2>&1 | head -5 || echo "node --check rejects HTML; do a manual brace check instead"
```
Expected: `node --check` cannot parse HTML directly — that is fine. Instead confirm visually that the inserted block has balanced braces and ends with the closing `}` of `runSpot`. (No automated JS lint exists for this single-file app.)

- [ ] **Step 3: Commit**

```bash
cd /web/annotation-tool
git add v2/index.html
git commit -m "v2: add inference config + upload/spot network helpers"
```

---

## Task 6: Upload once on load + trigger spotting on box creation

**Files:**
- Modify: `/web/annotation-tool/v2/index.html` (video `onFinish` ~849; `timelineAddClick` ~1962)

- [ ] **Step 1: Kick off the one-time upload when a video finishes loading**

Find (~851), inside the `onFinish()` callback:

```javascript
          videoLoaded = true; // a video is now loaded; the drag overlay may auto-hide on drag-leave
```

Insert immediately **after** it:

```javascript
          // Pre-upload the normalized video to the inference server (once) so the
          // first segment spot is fast. Fails quietly if the server is unreachable.
          inferVideoId = null; inferUploadPromise = null;
          ensureVideoUploaded().catch(e => console.warn('[spot] upload failed', e));
```

- [ ] **Step 2: Fire spotting for newly created boxes**

Find the `else` branch of `timelineAddClick` (~1962):

```javascript
      } else {
        let newStart = pendingStartTime;
```

Change it to capture the pre-insert length:

```javascript
      } else {
        const beforeLen = subtitles.length;
        let newStart = pendingStartTime;
```

Then find the end of that branch (~1990):

```javascript
        timelineContainer.removeEventListener('mousemove', updatePreviewDiv);
        renderAll();
      }
```

Change it to spot the new box(es). Twins (sync clones, which carry `masterId`) share the master's boundaries, so spot only the master to avoid duplicate inference:

```javascript
        timelineContainer.removeEventListener('mousemove', updatePreviewDiv);
        subtitles.slice(beforeLen).filter(s => !s.masterId).forEach(s => runSpot(s));
        renderAll();
      }
```

- [ ] **Step 3: Manual verification (browser, fake server)**

Run a fake inference server and serve v2:
```bash
cd /home/gomer/signrep-spotter && python3 infer_server.py --fake --port 8000 &
cd /web/annotation-tool/v2 && python3 -m http.server 5173 &
```
Open `http://localhost:5173/` in Chrome. Drop a short `.mp4`. After frames load, in DevTools Network you should see one `POST /upload` → 200 with a `videoId`. Create a segment box (click to set start, click to set end). Expected: a `POST /spot` fires; the box briefly shows a spinner, then a top-10 list (`GLOSS0 … GLOSS9`) appears below it (panel rendering lands in Task 7 — for now confirm the `/spot` request fires and returns 200 in the Network tab). Stop servers: `kill %1 %2`.

- [ ] **Step 4: Commit**

```bash
cd /web/annotation-tool
git add v2/index.html
git commit -m "v2: upload video once on load; spot each new segment on create"
```

---

## Task 7: Render the per-box spot panel (spinner / top-10 / error) + CSS

**Files:**
- Modify: `/web/annotation-tool/v2/index.html` (CSS in `<style>`; `renderAll` ~1675; helper near `runSpot`)

- [ ] **Step 1: Add CSS for the panel + spinner**

Find the `.subtitle-box {` rule (~62) in the `<style>` block:

```css
    .subtitle-box {
```

Insert these rules immediately **before** it:

```css
    .spot-panel { position: absolute; left: 0; min-width: 200px; max-width: 320px;
      background: #fff; border: 1px solid #007bff; border-radius: 4px;
      box-shadow: 0 2px 6px rgba(0,0,0,.25); font-size: 12px; }
    .spot-spinner { width: 18px; height: 18px; margin: 6px auto; border: 3px solid #cfe2ff;
      border-top-color: #007bff; border-radius: 50%; animation: spot-spin .8s linear infinite; }
    @keyframes spot-spin { to { transform: rotate(360deg); } }
    .spot-hdr { display: flex; justify-content: space-between; align-items: center;
      padding: 2px 6px; font-weight: bold; color: #333; border-bottom: 1px solid #eee; }
    .spot-list { max-height: 200px; overflow-y: auto; }
    .spot-item { display: flex; justify-content: space-between; gap: 8px;
      padding: 2px 6px; cursor: pointer; }
    .spot-item:hover { background: #007bff; color: #fff; }
    .spot-score { color: #888; }
    .spot-item:hover .spot-score { color: #cfe2ff; }
    .spot-error { padding: 4px 6px; color: #b00; display: flex; align-items: center; gap: 6px; }
    .spot-rerun { border: none; background: transparent; cursor: pointer; font-size: 13px; }
```

- [ ] **Step 2: Add the panel builder next to runSpot**

In `/web/annotation-tool/v2/index.html`, find the end of `runSpot` (the block added in Task 5; it ends with):

```javascript
        if (reqId === sub._spotReq) { sub.spotState = 'error'; renderAll(); }
      }
    }
```

Insert immediately **after** it:

```javascript
    // Build the panel shown under a box for its current spot state.
    function buildSpotPanel(sub) {
      const p = document.createElement('div');
      p.className = 'spot-panel';
      // Sit below the box, clearing the hover delete/loop row (~22px).
      p.style.top = (TIER_HEIGHT + 24) + 'px';
      p.style.zIndex = '1004';
      // Don't let clicks inside the panel start a box drag / time scrub.
      p.addEventListener('mousedown', ev => ev.stopPropagation());

      const rerun = () => {
        const b = document.createElement('button');
        b.className = 'spot-rerun';
        b.title = 'Re-run spotting';
        b.textContent = '↻';
        b.addEventListener('mousedown', ev => { ev.preventDefault(); ev.stopPropagation(); runSpot(sub); });
        return b;
      };

      if (sub.spotState === 'loading') {
        const s = document.createElement('div');
        s.className = 'spot-spinner';
        p.appendChild(s);
      } else if (sub.spotState === 'error') {
        const e = document.createElement('div');
        e.className = 'spot-error';
        e.textContent = 'spotting failed';
        e.appendChild(rerun());
        p.appendChild(e);
      } else if (sub.spotState === 'done') {
        const hdr = document.createElement('div');
        hdr.className = 'spot-hdr';
        const t = document.createElement('span');
        t.textContent = 'top 10';
        hdr.appendChild(t);
        hdr.appendChild(rerun());
        p.appendChild(hdr);
        const list = document.createElement('div');
        list.className = 'spot-list';
        (sub.spotResults || []).forEach(g => {
          const it = document.createElement('div');
          it.className = 'spot-item';
          const name = document.createElement('span');
          name.textContent = g.gloss;
          const score = document.createElement('span');
          score.className = 'spot-score';
          score.textContent = g.score;
          it.appendChild(name);
          it.appendChild(score);
          // Click fills the box text and keeps the list open.
          it.addEventListener('mousedown', ev => {
            ev.preventDefault(); ev.stopPropagation();
            sub.text = g.gloss;
            sub.committed = true;
            syncTwin(sub);
            uploadSubtitles();
            renderAll();
          });
          list.appendChild(it);
        });
        p.appendChild(list);
      }
      return p;
    }
```

- [ ] **Step 3: Append the panel in renderAll**

Find (~1675) the end of the `subtitles.forEach` box-building loop:

```javascript
        box.appendChild(rightHandle);
        timelineTrack.appendChild(box);
```

Insert the panel append **between** those two lines:

```javascript
        box.appendChild(rightHandle);
        if (sub.spotState) box.appendChild(buildSpotPanel(sub));
        timelineTrack.appendChild(box);
```

- [ ] **Step 4: Manual verification (browser, fake server)**

Start the fake server + static server as in Task 6 Step 3. Open `http://localhost:5173/`, drop a short mp4, create a box.
Expected sequence: spinner circle appears under the box → replaced by a panel titled "top 10" listing `GLOSS0 … GLOSS9` with scores. Click a gloss → the box text becomes that gloss and the list stays open. Click `↻` → spinner then list again. Stop the fake server (`kill` it) and create another box → spinner then "spotting failed ↻"; restart the fake server and click `↻` → list returns.

- [ ] **Step 5: Commit**

```bash
cd /web/annotation-tool
git add v2/index.html
git commit -m "v2: render per-box spot panel (spinner/top-10/error) + click-to-fill"
```

---

## Task 8: Deployment artifacts (systemd + Apache) + docs

**Files:**
- Create: `/home/gomer/signrep-spotter/deploy/signrep-infer.service`
- Create: `/home/gomer/signrep-spotter/deploy/apache-sign-spotter.conf`
- Modify: `/web/annotation-tool/v2/README.md` (document the spotting feature + reach)

> Applying these requires root (enabling a service, editing Apache). The plan
> produces the files and instructions; a human runs the privileged steps.

- [ ] **Step 1: Write the systemd unit**

Create `/home/gomer/signrep-spotter/deploy/signrep-infer.service`:

```ini
[Unit]
Description=SignRep gloss-spotting inference server (warm model)
After=network.target

[Service]
Type=simple
User=gomer
WorkingDirectory=/home/gomer/signrep-spotter
ExecStart=/home/gomer/signrep-spotter/.venv/bin/python infer_server.py --device cpu --port 8000 --host 127.0.0.1
Restart=on-failure
RestartSec=3

[Install]
WantedBy=multi-user.target
```

- [ ] **Step 2: Write the Apache reverse-proxy snippet**

Create `/home/gomer/signrep-spotter/deploy/apache-sign-spotter.conf`:

```apache
# Reverse-proxy the warm SignRep inference server (keeps the model loaded on
# 127.0.0.1:8000). Drop into the signcollect.nl HTTPS vhost.
#   a2enmod proxy proxy_http
ProxyTimeout 300
<Location /sign-spotter/>
    # Video uploads can be tens of MB; allow large bodies on this path.
    LimitRequestBody 0
    ProxyPass        http://127.0.0.1:8000/
    ProxyPassReverse http://127.0.0.1:8000/
</Location>
```

- [ ] **Step 3: Document the feature in v2 README**

In `/web/annotation-tool/v2/README.md`, find the "Optional online features" section (the list under that heading) and add a bullet to it:

```markdown
- **Gloss spotting** (SignRep) — when a segment is created, the tool uploads the
  video once and asks the inference server for the **top-10 NGT glosses** for that
  segment, shown in a dropdown under the box (click to fill the annotation; ↻ to
  re-run). Reaches `https://signcollect.nl/sign-spotter/` (Apache reverse-proxies
  to a warm Python server; `infer_server.py` in the `signrep-spotter` repo). Fails
  quietly when offline. Re-decoding a long video per segment is slow on CPU — best
  for short clips; a GPU box or per-video frame cache is the throughput lever.
```

- [ ] **Step 4: Verify the deploy files exist**

Run:
```bash
ls /home/gomer/signrep-spotter/deploy/
```
Expected: `apache-sign-spotter.conf  signrep-infer.service`.

- [ ] **Step 5: Commit (annotation-tool only)**

`/home/gomer/signrep-spotter` is not a git repo — the `deploy/` files are saved in place (confirm with Step 4). Commit only the annotation-tool side:
```bash
cd /web/annotation-tool
git add v2/README.md
git commit -m "v2: document SignRep gloss-spotting feature"
```

- [ ] **Step 6: Print the human follow-up steps**

These privileged steps are run by a human (not the agent):
```bash
# 1. Install + start the warm inference server
sudo cp /home/gomer/signrep-spotter/deploy/signrep-infer.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now signrep-infer
systemctl status signrep-infer        # expect: active (running)
curl -s http://127.0.0.1:8000/health  # expect: {"ok": true, "model": "fine-tuned"}

# 2. Wire the Apache reverse proxy
sudo a2enmod proxy proxy_http
#   include /home/gomer/signrep-spotter/deploy/apache-sign-spotter.conf in the
#   signcollect.nl :443 vhost, then:
sudo apachectl configtest && sudo systemctl reload apache2
curl -s https://signcollect.nl/sign-spotter/health   # expect the same JSON
```

---

## Self-Review Notes

- **Spec coverage:** v2 copy (Task 1) ✓; warm server + upload-once + /spot (Tasks 2–4) ✓; HTTPS same-origin reach via Apache proxy (Tasks 5 config + 8) ✓; spinner-on-create / top-10-when-done / error (Tasks 6–7) ✓; click fills + keeps list, no top-1 auto-fill (Task 7) ✓; on-create + manual ↻ re-run, no move/resize auto-fire (Tasks 6–7) ✓; quiet failure (Task 5 `runSpot` catch) ✓; 409 re-upload retry (Task 5) ✓; one-in-flight-per-box supersede via `_spotReq` (Task 5) ✓.
- **Known limitation (carried from spec):** `spot_segments` re-decodes the whole video per request — fine for short clips, slow for long ones on a loaded CPU box. Noted in the README; a per-`videoId` frame cache is the future optimization, intentionally out of scope.
- **Naming consistency:** `INFER_BASE`, `inferVideoId`, `inferUploadPromise`, `ensureVideoUploaded`, `inferUpload`, `inferSpot`, `runSpot`, `buildSpotPanel`, and sub fields `spotState`/`spotResults`/`_spotReq` are used identically across Tasks 5–7. Server: `SPOTTER`, `VIDEOS`, `VIDEO_DIR`, `SPOT_LOCK`, `Handler`, `FakeSpotter`, `load_real_spotter`, `serve` consistent across Tasks 2–4.
