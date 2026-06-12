# Server-Side Video Conversion (one-upload) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the slow in-browser ffmpeg.wasm conversion with native ffmpeg on monsterfish — the browser uploads the original file once, the server derives the 25 fps/1080p browser copy and the 50 fps segmenter input from it.

**Architecture:** The V-JEPA segmenter server (`infer_server.py`, monsterfish :8001, proxied at `https://signcollect.nl/sign-segmenter/`) gains a persistent content-hash store with three endpoints: `POST /upload` (store original, transcode the 25 fps/1080p browser copy), `GET /video/{id}` (download that copy), and a `{"videoId": ...}` JSON mode on the existing `POST /segment` (segments the stored *original*, reusing the existing lazy `ensure_fps` 50 fps transcode). The v3 frontend tries this server path first and falls back to the existing ffmpeg.wasm path when the server is unreachable, the file exceeds the proxy body cap, or the videoId has expired. A TTL sweep bounds the store's disk use. The spotter is untouched: the browser keeps uploading the (now server-made) 25 fps copy to it exactly as today.

**Tech Stack:** Python stdlib `http.server` (existing), ffmpeg/ffprobe subprocesses (existing helpers), vanilla JS + XHR (for upload progress) in `v3/index.html`.

**Repos:**
- `/home/gomer/vjepa-sign-segmentation` — NOT a git repo; no commits, just edits + test runs. Tests: `cd /home/gomer/vjepa-sign-segmentation && .venv/bin/python -m unittest test_infer_server -v`.
- `/web/annotation-tool` — git repo (branch `v2-signrep-spotting`), working tree IS the live docroot. Commit frontend changes here.
- Production: `gomer@monsterfish:/mnt/fishbowl/gomer/vjepa-sign-segmentation`, systemd `--user` unit `vjepa-segment.service`, venv `/mnt/fishbowl/gomer/signrep-spotter/.venv`. Access via `rtk proxy ssh gomer@monsterfish '<cmd>'`.

**Locked-in design decisions:**
- `videoId` = `sha256(original bytes)[:16]` — same scheme `/segment` already uses, so identical content dedupes for free.
- Store dir: `VJEPA_SEG_STORE` env var, default `<repo>/uploads` (on `/mnt/fishbowl` in prod — big disk). Survives restarts. Distinct from the ephemeral `VIDEO_DIR` tempdir, which keeps its one-shot-delete behavior for raw-bytes `/segment` so the two modes never delete each other's files.
- Browser copy `<id>.browser.mp4` mirrors the wasm output: `-r 25`, scale to fit 1920×1080 (even dims), `libx264`, `yuv420p`, `-an`. `-preset veryfast` instead of wasm's `ultrafast` (native cores make it fast anyway; better size/quality). If the original is already ~25 fps AND ≤1920×1080, no derivative is written — `GET /video` serves the original.
- 50 fps segmenter input stays LAZY via the existing `ensure_fps()` at `/segment` time. No eager background transcode: `SEG_LOCK` serializes `.segment()` and therefore `ensure_fps()`; an eager thread would race it on the same output path.
- TTL: 24 h, sweep every 30 min, by mtime, matching `<id>*` so `ensure_fps` derivatives (`<id>.mp4.50fps.mp4`) and browser copies go with the original.
- Apache: NO changes — `/sign-segmenter/` already has `LimitRequestBody 209715200` and `ProxyTimeout 300` (deploy/apache-sign-segmenter.conf; verify live copy in Task 4).
- Frontend fallback ladder: server convert → ffmpeg.wasm (unchanged path). Segment: videoId → (on 404, id expired) original-bytes ≤190 MB → normalized blob. IndexedDB conversion cache stays (a cache hit means no videoId — bytes fallback handles it).

---

### Task 1: Server — persistent store, `POST /upload`, `GET /video/{id}`

**Files:**
- Modify: `/home/gomer/vjepa-sign-segmentation/infer_server.py`
- Test: `/home/gomer/vjepa-sign-segmentation/test_infer_server.py`

- [ ] **Step 1.1: Write the failing tests**

Append to `test_infer_server.py` (the existing `_start_server`/`_req` helpers work as-is; `STORE_DIR` is pointed at a temp dir per test class so tests never touch `uploads/`):

```python
import os
import tempfile


class UploadTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.store = tempfile.mkdtemp(prefix="seg_store_test_")
        cls._old_store = infer_server.STORE_DIR
        infer_server.STORE_DIR = cls.store
        cls.srv = _start_server()

    @classmethod
    def tearDownClass(cls):
        cls.srv.shutdown()
        infer_server.STORE_DIR = cls._old_store

    def test_upload_returns_video_id(self):
        # Garbage bytes: ffprobe can't read them -> fps/duration 0 -> stored
        # as-is, no transcode attempted. The plumbing is what's under test.
        status, body, _ = _req(self.srv, "POST", "/upload", data=b"not-a-video")
        self.assertEqual(status, 200)
        data = json.loads(body)
        self.assertRegex(data["videoId"], r"^[0-9a-f]{16}$")
        self.assertTrue(os.path.exists(
            os.path.join(self.store, data["videoId"] + ".mp4")))

    def test_upload_empty_body_400(self):
        status, _, _ = _req(self.srv, "POST", "/upload", data=b"",
                            headers={"Content-Length": "0"})
        self.assertEqual(status, 400)

    def test_video_roundtrip(self):
        payload = b"roundtrip-video-bytes"
        _, body, _ = _req(self.srv, "POST", "/upload", data=payload)
        vid = json.loads(body)["videoId"]
        status, got, headers = _req(self.srv, "GET", "/video/" + vid)
        self.assertEqual(status, 200)
        self.assertEqual(got, payload)   # no browser copy -> serves original
        self.assertEqual(headers.get("Content-Type"), "video/mp4")

    def test_video_unknown_id_404(self):
        status, _, _ = _req(self.srv, "GET", "/video/" + "0" * 16)
        self.assertEqual(status, 404)

    def test_video_bad_id_404(self):
        status, _, _ = _req(self.srv, "GET", "/video/../etc/passwd")
        self.assertEqual(status, 404)
```

- [ ] **Step 1.2: Run tests to verify they fail**

Run: `cd /home/gomer/vjepa-sign-segmentation && .venv/bin/python -m unittest test_infer_server -v 2>&1 | tail -15`
Expected: the five new tests FAIL/ERROR (404 from unknown paths, `AttributeError: STORE_DIR`); the existing 6 still pass.

- [ ] **Step 1.3: Implement store + endpoints**

In `infer_server.py`:

(a) Module state — after the `VIDEO_DIR = ...` line add:

```python
# Persistent content-hash store for /upload'd originals + their derivatives
# (<id>.mp4, <id>.browser.mp4, <id>.mp4.50fps.mp4). Unlike VIDEO_DIR (one-shot
# temp for raw-bytes /segment), entries here live until the TTL sweep.
STORE_DIR = os.environ.get("VJEPA_SEG_STORE", os.path.join(
    os.path.dirname(os.path.abspath(__file__)), "uploads"))
```

(b) ffprobe dimensions helper — after `_video_duration`:

```python
def _probe_dims(path):
    """(width, height), or (0, 0) if undetectable."""
    try:
        out = subprocess.run(
            [_FFPROBE, "-v", "error", "-select_streams", "v:0",
             "-show_entries", "stream=width,height", "-of", "csv=p=0", path],
            capture_output=True, text=True, timeout=30).stdout.strip()
        w, h = out.split(",")[:2]
        return int(w), int(h)
    except Exception:
        return 0, 0
```

(c) Browser-copy transcode — after `ensure_fps`:

```python
# Mirror of the frontend's ffmpeg.wasm normalization (25 fps, fit 1920x1080,
# H.264, no audio) so the file the browser decodes is equivalent either way.
BROWSER_FPS, BROWSER_W, BROWSER_H = 25, 1920, 1080


def make_browser_copy(src, dst):
    """Transcode src to the browser-normalized form at dst. Returns dst, or
    None when src already conforms (caller then serves src directly) or the
    transcode fails (caller falls back; frontend will wasm-convert)."""
    fps = _probe_fps(src)
    w, h = _probe_dims(src)
    if fps <= 0:
        return None                       # unreadable -> nothing to derive
    if abs(fps - BROWSER_FPS) <= FPS_TOL and 0 < w <= BROWSER_W and 0 < h <= BROWSER_H:
        return None                       # already conformant
    vf = ("scale='min(%d,iw)':'min(%d,ih)':force_original_aspect_ratio=decrease,"
          "scale=trunc(iw/2)*2:trunc(ih/2)*2" % (BROWSER_W, BROWSER_H))
    try:
        subprocess.run(
            [_FFMPEG, "-nostdin", "-y", "-loglevel", "error", "-i", src,
             "-r", str(BROWSER_FPS), "-vf", vf,
             "-c:v", "libx264", "-preset", "veryfast", "-pix_fmt", "yuv420p",
             "-an", dst],
            check=True, timeout=240, stdin=subprocess.DEVNULL)
        return dst
    except Exception as e:
        sys.stderr.write("[browser-copy] transcode failed (%s)\n" % e)
        try:
            os.remove(dst)
        except OSError:
            pass
        return None
```

(d) `do_GET` — add the `/video/` route before the final 404 (keep `import re` at top of file):

```python
        if path.startswith("/video/"):
            vid = path[len("/video/"):]
            if not re.fullmatch(r"[0-9a-f]{16}", vid):
                return self._send(404, {"error": "not_found"})
            browser = os.path.join(STORE_DIR, vid + ".browser.mp4")
            original = os.path.join(STORE_DIR, vid + ".mp4")
            src = browser if os.path.exists(browser) else original
            if not os.path.exists(src):
                return self._send(404, {"error": "unknown_video"})
            self.send_response(200)
            self.send_header("Content-Type", "video/mp4")
            self.send_header("Content-Length", str(os.path.getsize(src)))
            _cors(self)
            self.end_headers()
            with open(src, "rb") as f:
                shutil.copyfileobj(f, self.wfile)
            return
```

(e) `do_POST` — add the `/upload` route (same structure as `/segment`'s body handling — atomic write, duration guard — but into `STORE_DIR` and WITHOUT the one-shot delete):

```python
        if path == "/upload":
            data = self._read_body()
            if not data:
                return self._send(400, {"error": "empty_body"})
            vid = hashlib.sha256(data).hexdigest()[:16]
            os.makedirs(STORE_DIR, exist_ok=True)
            dest = os.path.join(STORE_DIR, vid + ".mp4")
            if not os.path.exists(dest):
                fd, tmp = tempfile.mkstemp(dir=STORE_DIR, suffix=".part")
                with os.fdopen(fd, "wb") as f:
                    f.write(data)
                os.replace(tmp, dest)
            dur = _video_duration(dest)
            if dur > MAX_VIDEO_SECONDS:
                try:
                    os.remove(dest)
                except OSError:
                    pass
                return self._send(413, {"error": "video_too_long",
                                        "seconds": round(dur, 1),
                                        "max_seconds": MAX_VIDEO_SECONDS})
            browser = os.path.join(STORE_DIR, vid + ".browser.mp4")
            if not os.path.exists(browser):
                make_browser_copy(dest, browser)   # None -> GET serves original
            return self._send(200, {"videoId": vid,
                                    "fps": _probe_fps(dest),
                                    "duration": dur})
```

(f) Update the module docstring's endpoint list to include `/upload` and `/video/{id}`.

- [ ] **Step 1.4: Run tests to verify they pass**

Run: `cd /home/gomer/vjepa-sign-segmentation && .venv/bin/python -m unittest test_infer_server -v 2>&1 | tail -5`
Expected: `Ran 11 tests ... OK`

### Task 2: Server — `/segment` videoId mode

**Files:**
- Modify: `/home/gomer/vjepa-sign-segmentation/infer_server.py` (`do_POST` `/segment` branch)
- Test: `/home/gomer/vjepa-sign-segmentation/test_infer_server.py`

- [ ] **Step 2.1: Write the failing tests**

Append to `test_infer_server.py` (inside `UploadTest` to reuse the store setup):

```python
    def test_segment_by_video_id(self):
        _, body, _ = _req(self.srv, "POST", "/upload", data=b"segment-me")
        vid = json.loads(body)["videoId"]
        status, body, _ = _req(self.srv, "POST", "/segment",
                               data=json.dumps({"videoId": vid}).encode(),
                               headers={"Content-Type": "application/json"})
        self.assertEqual(status, 200)
        data = json.loads(body)
        self.assertEqual(data["segments"],
                         [[0.48, 1.32], [1.80, 2.68], [3.04, 4.12]])
        # videoId mode must NOT one-shot-delete the stored original
        self.assertTrue(os.path.exists(
            os.path.join(self.store, vid + ".mp4")))

    def test_segment_unknown_video_id_404(self):
        status, body, _ = _req(self.srv, "POST", "/segment",
                               data=json.dumps({"videoId": "f" * 16}).encode(),
                               headers={"Content-Type": "application/json"})
        self.assertEqual(status, 404)
        self.assertEqual(json.loads(body)["error"], "unknown_video")

    def test_segment_bad_json_400(self):
        status, _, _ = _req(self.srv, "POST", "/segment",
                            data=b"{not json",
                            headers={"Content-Type": "application/json"})
        self.assertEqual(status, 400)
```

- [ ] **Step 2.2: Run tests to verify they fail**

Run: `cd /home/gomer/vjepa-sign-segmentation && .venv/bin/python -m unittest test_infer_server -v 2>&1 | tail -8`
Expected: the three new tests FAIL (the JSON body is treated as video bytes today).

- [ ] **Step 2.3: Implement videoId mode**

Restructure the `/segment` branch of `do_POST`: JSON requests resolve a stored file (no delete); raw-bytes requests keep the existing tempdir + one-shot-delete path. The shared tail runs the segmenter:

```python
        if path == "/segment":
            cleanup = []                  # files to one-shot-delete (bytes mode)
            ctype = (self.headers.get("Content-Type") or "").lower()
            if "application/json" in ctype:
                try:
                    req = json.loads(self._read_body() or b"{}")
                    vid = str(req.get("videoId") or "")
                except (ValueError, UnicodeDecodeError):
                    return self._send(400, {"error": "bad_json"})
                if not re.fullmatch(r"[0-9a-f]{16}", vid):
                    return self._send(404, {"error": "unknown_video"})
                dest = os.path.join(STORE_DIR, vid + ".mp4")
                if not os.path.exists(dest):
                    return self._send(404, {"error": "unknown_video"})
                # duration was guarded at /upload; 50fps derivative is created
                # lazily by ensure_fps inside SEGMENTER.segment and persists
                # next to the original for re-runs (TTL sweeps both).
            else:
                data = self._read_body()
                if not data:
                    return self._send(400, {"error": "empty_body"})
                vid = hashlib.sha256(data).hexdigest()[:16]
                dest = os.path.join(VIDEO_DIR, vid + ".mp4")
                if not os.path.exists(dest):
                    fd, tmp = tempfile.mkstemp(dir=VIDEO_DIR, suffix=".part")
                    with os.fdopen(fd, "wb") as f:
                        f.write(data)
                    os.replace(tmp, dest)
                dur = _video_duration(dest)
                if dur > MAX_VIDEO_SECONDS:
                    try:
                        os.remove(dest)
                    except OSError:
                        pass
                    return self._send(413, {"error": "video_too_long",
                                            "seconds": round(dur, 1),
                                            "max_seconds": MAX_VIDEO_SECONDS})
                cleanup = [dest] + [dest + ".*fps.mp4"]   # glob pattern, see below
            try:
                with SEG_LOCK:
                    segments = SEGMENTER.segment(dest)
                return self._send(200, {"fps": getattr(SEGMENTER, "fps", 25.0),
                                        "segments": segments})
            finally:
                if cleanup:
                    for p in [cleanup[0]] + glob.glob(cleanup[1]):
                        try:
                            os.remove(p)
                        except OSError:
                            pass
```

(The existing atomic-write comments stay with the bytes branch; keep them.)

- [ ] **Step 2.4: Run all tests**

Run: `cd /home/gomer/vjepa-sign-segmentation && .venv/bin/python -m unittest test_infer_server -v 2>&1 | tail -5`
Expected: `Ran 14 tests ... OK`

### Task 3: Server — TTL sweep

**Files:**
- Modify: `/home/gomer/vjepa-sign-segmentation/infer_server.py`
- Test: `/home/gomer/vjepa-sign-segmentation/test_infer_server.py`

- [ ] **Step 3.1: Write the failing test**

```python
class SweepTest(unittest.TestCase):
    def test_sweep_removes_only_expired(self):
        store = tempfile.mkdtemp(prefix="seg_sweep_test_")
        old = os.path.join(store, "a" * 16 + ".mp4")
        old_deriv = os.path.join(store, "a" * 16 + ".mp4.50fps.mp4")
        fresh = os.path.join(store, "b" * 16 + ".mp4")
        for p in (old, old_deriv, fresh):
            with open(p, "wb") as f:
                f.write(b"x")
        past = 1_000_000.0
        os.utime(old, (past, past))
        os.utime(old_deriv, (past, past))
        removed = infer_server.sweep_store(store, ttl=24 * 3600,
                                           now=past + 25 * 3600)
        self.assertEqual(sorted(removed), sorted([old, old_deriv]))
        self.assertFalse(os.path.exists(old))
        self.assertTrue(os.path.exists(fresh))
```

- [ ] **Step 3.2: Run test to verify it fails**

Run: `cd /home/gomer/vjepa-sign-segmentation && .venv/bin/python -m unittest test_infer_server.SweepTest -v`
Expected: ERROR — `infer_server` has no attribute `sweep_store`.

- [ ] **Step 3.3: Implement sweep + background thread**

```python
STORE_TTL_S = 24 * 3600
SWEEP_EVERY_S = 1800


def sweep_store(store_dir, ttl=STORE_TTL_S, now=None):
    """Delete store entries older than ttl (by mtime). Returns removed paths.
    Derivatives (<id>.browser.mp4, <id>.mp4.50fps.mp4) age alongside their
    original, so prefix-matching is not needed — mtime covers each file."""
    if now is None:
        now = time.time()
    removed = []
    for name in (os.listdir(store_dir) if os.path.isdir(store_dir) else []):
        p = os.path.join(store_dir, name)
        try:
            if os.path.isfile(p) and now - os.path.getmtime(p) > ttl:
                os.remove(p)
                removed.append(p)
        except OSError:
            pass
    if removed:
        sys.stderr.write("[sweep] removed %d expired upload(s)\n" % len(removed))
    return removed


def _sweep_loop():
    while True:
        time.sleep(SWEEP_EVERY_S)
        sweep_store(STORE_DIR)
```

Add `import time` to the imports. In `serve()`, before `serve_forever()`:

```python
    threading.Thread(target=_sweep_loop, daemon=True).start()
```

- [ ] **Step 3.4: Run all server tests**

Run: `cd /home/gomer/vjepa-sign-segmentation && .venv/bin/python -m unittest test_infer_server -v 2>&1 | tail -5`
Expected: `Ran 15 tests ... OK`

- [ ] **Step 3.5: Run the standalone model tests (regression)**

Run: `cd /home/gomer/vjepa-sign-segmentation && PYTHONPATH=. .venv/bin/python tests/test_model.py 2>&1 | tail -2`
Expected: `All 16 tests passed.`

### Task 4: Deploy server to monsterfish + verify with a real video

**Files:**
- Remote: `gomer@monsterfish:/mnt/fishbowl/gomer/vjepa-sign-segmentation/`

- [ ] **Step 4.1: Sync code**

```bash
rtk proxy rsync -av /home/gomer/vjepa-sign-segmentation/infer_server.py \
  /home/gomer/vjepa-sign-segmentation/test_infer_server.py \
  gomer@monsterfish:/mnt/fishbowl/gomer/vjepa-sign-segmentation/
```

- [ ] **Step 4.2: Run tests remotely, then restart**

```bash
rtk proxy ssh gomer@monsterfish 'cd /mnt/fishbowl/gomer/vjepa-sign-segmentation && \
  /mnt/fishbowl/gomer/signrep-spotter/.venv/bin/python -m unittest test_infer_server 2>&1 | tail -3 && \
  systemctl --user restart vjepa-segment && sleep 25 && curl -s http://127.0.0.1:8001/health'
```
Expected: `Ran 15 tests ... OK`, then health JSON with `"profile": "ensemble50", "fps": 50.0`.

- [ ] **Step 4.3: End-to-end with a real high-fps video**

Make a 60 fps test clip from test5s.mp4, push it through upload → video → segment-by-id:

```bash
rtk proxy scp /home/gomer/vjepa-sign-segmentation/test5s.mp4 gomer@monsterfish:/tmp/
rtk proxy ssh gomer@monsterfish '
  ffmpeg -nostdin -y -loglevel error -i /tmp/test5s.mp4 -r 60 -c:v libx264 -pix_fmt yuv420p -an /tmp/test60.mp4 &&
  curl -s --data-binary @/tmp/test60.mp4 http://127.0.0.1:8001/upload | tee /tmp/up.json &&
  VID=$(python3 -c "import json;print(json.load(open(\"/tmp/up.json\"))[\"videoId\"])") &&
  curl -s -o /tmp/browser.mp4 http://127.0.0.1:8001/video/$VID &&
  ffprobe -v error -select_streams v:0 -show_entries stream=r_frame_rate -of default=nw=1:nk=1 /tmp/browser.mp4 &&
  time curl -s -H "Content-Type: application/json" -d "{\"videoId\":\"$VID\"}" http://127.0.0.1:8001/segment &&
  ls /mnt/fishbowl/gomer/vjepa-sign-segmentation/uploads/ &&
  rm /tmp/test5s.mp4 /tmp/test60.mp4 /tmp/browser.mp4 /tmp/up.json'
```
Expected: upload returns `{"videoId": ..., "fps": 60.0, ...}`; downloaded browser copy probes as `25/1` fps; `/segment` returns `"fps": 50.0` with plausible segments; `uploads/` shows `<id>.mp4`, `<id>.browser.mp4`, `<id>.mp4.50fps.mp4`.

- [ ] **Step 4.4: Confirm the live Apache conf has the body cap**

```bash
rtk proxy grep -rn "sign-segmenter" /etc/apache2/ | head -3
```
Then read the matched file's Location block; expected `LimitRequestBody 209715200` and `ProxyTimeout 300` as in `deploy/apache-sign-segmenter.conf`. If absent, update the live conf to match the repo copy (needs sudo — ask the user to run `! sudo ...` if permission is denied).

### Task 5: Frontend — server convert with wasm fallback, videoId segmentation

**Files:**
- Modify: `/web/annotation-tool/v3/index.html`

- [ ] **Step 5.1: Hoist the upload cap, add segVideoId state**

Near the `segSourceBlob` declaration (search for `let segSourceBlob`), add:

```js
    let segVideoId = null;    // server-side videoId from /upload (server-convert path); null on wasm fallback or session restore
```

Near `const TARGET_FPS = 25;` add (and DELETE the local `const SEG_MAX_UPLOAD = 190 * 1024 * 1024;` from the auto-segmentation block — search for `SEG_MAX_UPLOAD`):

```js
    const SEG_MAX_UPLOAD = 190 * 1024 * 1024; // stay under Apache LimitRequestBody (200 MB)
```

- [ ] **Step 5.2: Add serverConvert() next to segInfer()**

```js
    // Upload the ORIGINAL file to the segmenter's /upload (native ffmpeg there
    // converts far faster than wasm), then download the 25fps/1080p browser
    // copy. Returns {videoId, file}. Throws on any failure (caller falls back
    // to the in-browser wasm converter).
    async function serverConvert(file) {
      showConvertModal('Uploading original…', 0);
      const videoId = await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', SEG_BASE + '/upload');
        xhr.responseType = 'json';
        xhr.timeout = 290000; // just under Apache ProxyTimeout (300 s)
        xhr.upload.onprogress = (e) => {
          if (!e.lengthComputable) return;
          const pct = Math.round((e.loaded / e.total) * 100);
          setConvertProgress(pct < 100 ? 'Uploading original… ' + pct + '%'
                                       : 'Converting on server…', pct);
        };
        xhr.onload = () => (xhr.status === 200 && xhr.response && xhr.response.videoId)
          ? resolve(xhr.response.videoId)
          : reject(new Error('upload HTTP ' + xhr.status));
        xhr.onerror = () => reject(new Error('upload network error'));
        xhr.ontimeout = () => reject(new Error('upload timeout'));
        xhr.send(file);
      });
      setConvertProgress('Downloading converted video…', 100);
      const r = await fetch(SEG_BASE + '/video/' + videoId);
      if (!r.ok) throw new Error('video download HTTP ' + r.status);
      const buf = await r.arrayBuffer();
      hideConvertModal();
      const base = (file.name.replace(/\.[^.]+$/, '') || 'video');
      return { videoId, file: new File([buf], base + '.mp4', { type: 'video/mp4' }) };
    }
```

- [ ] **Step 5.3: Try the server path in prepareAndLoadVideo**

In `prepareAndLoadVideo`, add `segVideoId = null;` right after `segSourceBlob = file;`. Then in the `needsConvert` branch, replace:

```js
        try {
          finalFile = await convertVideoWithProgress(file);
          cachePutConverted(fp, finalFile); // cache for next time (fire-and-forget)
        } catch (e) {
```

with:

```js
        try {
          let converted = null;
          if (SEG_BASE && file.size <= SEG_MAX_UPLOAD) {
            try {
              converted = await serverConvert(file);   // fast native path
              segVideoId = converted.videoId;
            } catch (err) {
              console.warn('[convert] server convert failed, using wasm:', err);
              hideConvertModal();
            }
          }
          finalFile = converted ? converted.file
                                : await convertVideoWithProgress(file);
          cachePutConverted(fp, finalFile); // cache for next time (fire-and-forget)
        } catch (e) {
```

- [ ] **Step 5.4: videoId-first segInfer with expiry fallback**

Replace `segInfer` with:

```js
    // POST to the segmenter: by videoId when the server already has the
    // original (server-convert path) — falling back to re-uploading bytes when
    // the id expired (24h TTL) — else raw video bytes.
    async function segInfer(blob) {
      if (segVideoId) {
        const r = await fetch(SEG_BASE + '/segment', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ videoId: segVideoId })
        });
        if (r.ok) {
          const data = await r.json();
          const segs = Array.isArray(data.segments) ? data.segments : [];
          return segs.map(([s, e]) => ({ start: s, end: e, text: '' }));
        }
        if (r.status !== 404) throw new Error('segment HTTP ' + r.status);
        segVideoId = null;   // expired on the server — re-upload below
      }
      const r = await fetch(SEG_BASE + '/segment', { method: 'POST', body: blob });
      if (!r.ok) throw new Error('segment HTTP ' + r.status);
      const data = await r.json();
      const segs = Array.isArray(data.segments) ? data.segments : [];
      return segs.map(([s, e]) => ({ start: s, end: e, text: '' }));
    }
```

The auto-segmentation call site (search `const segUpload =`) stays — its original-vs-normalized choice is now only the fallback when `segVideoId` is null. Remove the now-duplicated local `SEG_MAX_UPLOAD` const there (done in Step 5.1).

- [ ] **Step 5.5: Manual verify on the live tool**

The working tree is the live docroot. In a browser at `https://signcollect.nl/annotation-tool/v3/`:
1. Drop a high-fps (e.g. 60 fps phone) clip → expect "Uploading original…" then "Converting on server…" instead of the slow wasm progress; video loads and scrubs normally.
2. Auto-segmentation runs without re-uploading (check DevTools network tab: `/segment` request is small JSON, not megabytes).
3. Drop an already-25fps clip → no convert UI, segmentation posts bytes (unchanged old path).
4. Kill the tunnel or use an oversized file → wasm fallback still converts.

(If browser access isn't available in this session, use the `verify` skill / ask the user to confirm step 1–2.)

- [ ] **Step 5.6: Commit**

```bash
cd /web/annotation-tool && git add v3/index.html docs/superpowers/plans/2026-06-12-server-side-convert.md && \
git commit -m "v3: server-side video conversion on monsterfish (one upload, wasm fallback)

The original file is uploaded once to the segmenter's new /upload; native
ffmpeg there makes the 25fps/1080p browser copy (downloaded back) and the
50fps segmenter input is derived lazily at /segment {videoId}. ffmpeg.wasm
remains as fallback (server down, >190MB, expired videoId).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

### Task 6: Docs + memory

**Files:**
- Modify: `/home/gomer/vjepa-sign-segmentation/README.md` (server endpoints section)
- Modify: `/home/gomer/.claude/projects/-web-annotation-tool/memory/production-inference-on-monsterfish.md`

- [ ] **Step 6.1: Document the endpoints in the segmenter README**

In the server section of `README.md`, list the endpoint set:

```markdown
The warm server (`infer_server.py`) exposes:

| Endpoint | Body | Returns |
|---|---|---|
| `GET /health` | – | profile/fps/checkpoint of the live model |
| `POST /upload` | original video bytes | `{videoId, fps, duration}`; stores the original (24 h TTL) and derives the 25 fps/1080p browser copy |
| `GET /video/{videoId}` | – | the browser copy (mp4) |
| `POST /segment` | video bytes, or JSON `{"videoId"}` | `{fps, segments: [[s,e],…]}`; bytes mode is one-shot, videoId mode reuses the stored original (50 fps derivative cached) |
```

- [ ] **Step 6.2: Sync README to monsterfish**

```bash
rtk proxy rsync -av /home/gomer/vjepa-sign-segmentation/README.md \
  gomer@monsterfish:/mnt/fishbowl/gomer/vjepa-sign-segmentation/
```

- [ ] **Step 6.3: Update memory**

In `production-inference-on-monsterfish.md`, append to the segmenter sentence: video conversion now happens server-side via `/upload` + `/video/{id}` on :8001 (24 h TTL store in `uploads/`), browser wasm is fallback-only.

---

## Self-review notes

- Spec coverage: one-upload ✓ (original posted once to `/upload`; `/segment` is JSON by id), 25 fps derivative for browser+spotter ✓ (`GET /video`, browser re-uses it for the spotter exactly as before — spotter untouched), 50 fps for segmenter ✓ (existing lazy `ensure_fps` on the stored original), faster-than-wasm ✓ (native ffmpeg, veryfast), fallbacks ✓ (wasm, bytes-mode, TTL-404 re-upload), disk bounded ✓ (TTL sweep + 200 MB cap + 180 s duration guard).
- Types consistent: `videoId` is the 16-hex sha256 prefix everywhere; `/segment` JSON shape `{"videoId"}` matches frontend; `sweep_store(store_dir, ttl, now)` signature matches test.
- No placeholders: every code step is complete and paste-able.
