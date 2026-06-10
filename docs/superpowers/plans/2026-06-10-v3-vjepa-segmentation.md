# v3 V-JEPA Auto-Segmentation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Drop a video → if it has no segments, auto-run the V-JEPA segmentor on monsterfish (loading bar) → fill the timeline → auto-spot each segment with the existing SignRep spotter.

**Architecture:** A new warm V-JEPA segmentation HTTP server runs on monsterfish GPU (`127.0.0.1:8001`), reached from the browser over HTTPS via Apache `/sign-segmenter/` → an SSH reverse tunnel, exactly mirroring the existing spotter (`/sign-spotter/` → `:8000`). The frontend lives in a new `v3/` folder (copy of `v2/`), and `/annotation-tool/` redirects to `v3/`.

**Tech Stack:** Python stdlib `http.server` (warm server, lazy torch imports), `vjepa_seg` (V-JEPA2 + MS-TCN), systemd `--user` (monsterfish) + systemd (cloud tunnel), Apache reverse-proxy, vanilla-JS single-file frontend.

**Hosts & paths (verified live):**
- **cloud** (= signcollect.nl): vjepa source `/home/gomer/vjepa-sign-segmentation`; tunnel units in `/etc/systemd/system/`; Apache confs in `/etc/apache2/conf-available/`. Spotter tunnel = `signrep-tunnel.service` (`ssh -L 127.0.0.1:8000:127.0.0.1:8000 gomer@monsterfish`).
- **monsterfish001** (NVIDIA TITAN RTX, `python3.11`): spotter at `/mnt/fishbowl/gomer/signrep-spotter` (`.venv`, user unit `signrep-infer.service`, `:8000`). **vjepa not yet present** → target dir `/mnt/fishbowl/gomer/vjepa-sign-segmentation`.

**rtk gotcha (MUST follow):** the rtk Claude Code hook rewrites tokens like `pip`/`curl`/`grep` inside Bash commands, which corrupts them inside `ssh '...'` payloads (remote gets `rtk: command not found`). Wrap every remote command in `rtk proxy ssh …` with an **inline single-quoted** script (`rtk proxy` does not forward stdin, so no `bash -s` heredocs). The vjepa repo, like signrep-spotter, is **not** git-tracked — save changes in place.

---

## File Structure

**On cloud (`/home/gomer/vjepa-sign-segmentation/`):**
- Create `infer_server.py` — warm segmentation HTTP server (stdlib top; lazy torch).
- Create `test_infer_server.py` — stdlib `unittest`, no torch (FakeSegmenter).
- Create `deploy/vjepa-segment.service` — monsterfish user-systemd unit (`:8001`, cuda).
- Create `deploy/vjepa-tunnel.service` — cloud systemd ssh tunnel (`:8001`).
- Create `deploy/apache-sign-segmenter.conf` — Apache `/sign-segmenter/` → `:8001`.
- Create `deploy/install-segment.sh` — cloud-side installer (tunnel + apache + verify).
- Create `deploy/provision-monsterfish.sh` — copies repo to monsterfish, builds venv, downloads models, installs user service.

**On cloud (`/web/annotation-tool/`):**
- Create `v3/` — copy of `v2/` (`index.html`, `mod.js`, `glosses_transformed.json`, `vendor/`).
- Modify `v3/index.html` — add `SEG_BASE`, `segInfer`, `runSegmentation`, auto-trigger, repurpose `autoSegmentBtn`, remove dead ISS code.
- Modify `index.html` (top-level redirect) — `./v2/` → `./v3/`.

---

## Task 1: Warm segmentation server — health endpoint (FakeSegmenter)

**Files:**
- Create: `/home/gomer/vjepa-sign-segmentation/infer_server.py`
- Test: `/home/gomer/vjepa-sign-segmentation/test_infer_server.py`

- [ ] **Step 1: Write the failing test**

Create `test_infer_server.py`:

```python
import json
import threading
import unittest
import urllib.request
import urllib.error
from http.server import ThreadingHTTPServer

import infer_server


def _start_server():
    infer_server.SEGMENTER = infer_server.FakeSegmenter()
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
        data = json.loads(body)
        self.assertEqual(data["ok"], True)
        self.assertEqual(data["model"], "vjepa_seg")
        # fake flag lets an operator detect a stray --fake server.
        self.assertTrue(data["fake"])

    def test_unknown_path_404(self):
        status, _, _ = _req(self.srv, "GET", "/nope")
        self.assertEqual(status, 404)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/gomer/vjepa-sign-segmentation && python3 -m unittest test_infer_server -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'infer_server'`.

- [ ] **Step 3: Write minimal implementation**

Create `infer_server.py`:

```python
#!/usr/bin/env python3
"""Warm V-JEPA sign-segmentation inference server.

Loads the V-JEPA2 backbone + MS-TCN head once and serves:
  GET  /health   -> {"ok": true, "model": "vjepa_seg", "device": "...", "fake": bool}
  POST /segment  -> body = video bytes -> {"fps": 25.0, "segments": [[s, e], ...]}

Run inside the vjepa venv (on monsterfish):
    python infer_server.py --device cuda --port 8001 --host 127.0.0.1
Tests run it with a FakeSegmenter (no torch) by setting the module global SEGMENTER.
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

# Module state (tests override SEGMENTER before serving)
SEGMENTER = None
DEVICE = "cpu"
VIDEO_DIR = tempfile.mkdtemp(prefix="vjepa_seg_")
SEG_LOCK = threading.Lock()            # serialize GPU calls (not re-entrant)

HERE = os.path.dirname(os.path.abspath(__file__))


class FakeSegmenter:
    """Stand-in for tests and `--fake`: no torch, deterministic segments."""
    def segment(self, video_path):
        return [[0.48, 1.32], [1.80, 2.68], [3.04, 4.12]]


def _cors(handler):
    handler.send_header("Access-Control-Allow-Origin", "*")
    handler.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
    handler.send_header("Access-Control-Allow-Headers", "Content-Type")


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        sys.stderr.write("  %s\n" % (fmt % args))

    def _send(self, code, obj=None):
        body = json.dumps(obj or {}).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
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
            fake = SEGMENTER is None or isinstance(SEGMENTER, FakeSegmenter)
            return self._send(200, {"ok": True, "model": "vjepa_seg",
                                    "device": DEVICE, "fake": fake})
        return self._send(404, {"error": "not_found"})

    def _read_body(self):
        length = int(self.headers.get("Content-Length", 0))
        return self.rfile.read(length) if length else b""

    def do_POST(self):
        path = urlparse(self.path).path
        if path == "/segment":
            data = self._read_body()
            if not data:
                return self._send(400, {"error": "empty_body"})
            vid = hashlib.sha256(data).hexdigest()[:16]
            dest = os.path.join(VIDEO_DIR, vid + ".mp4")
            if not os.path.exists(dest):
                with open(dest, "wb") as f:
                    f.write(data)
            with SEG_LOCK:
                segments = SEGMENTER.segment(dest)
            return self._send(200, {"fps": 25.0, "segments": segments})
        return self._send(404, {"error": "not_found"})


def serve(host, port):
    srv = ThreadingHTTPServer((host, port), Handler)
    print(f"V-JEPA segmentation server on http://{host}:{port}/  (Ctrl+C to stop)")
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        print("\nbye")
```

(The `load_real_segmenter` + `main()` come in Task 3 — keep the file ending here for now so the test imports cleanly.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /home/gomer/vjepa-sign-segmentation && python3 -m unittest test_infer_server -v`
Expected: PASS (2 tests).

- [ ] **Step 5: Save in place (no git — repo is untracked)**

No commit; the vjepa repo is not git-tracked. Confirm files exist:
Run: `ls -l /home/gomer/vjepa-sign-segmentation/infer_server.py /home/gomer/vjepa-sign-segmentation/test_infer_server.py`
Expected: both listed.

---

## Task 2: `/segment` endpoint behavior (fake) + CORS

**Files:**
- Modify: `/home/gomer/vjepa-sign-segmentation/test_infer_server.py`

- [ ] **Step 1: Add failing tests**

Append these classes before the `if __name__` line in `test_infer_server.py`:

```python
class SegmentTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.srv = _start_server()

    @classmethod
    def tearDownClass(cls):
        cls.srv.shutdown()

    def test_segment_returns_list(self):
        status, body, _ = _req(self.srv, "POST", "/segment", data=b"fake-video-bytes")
        self.assertEqual(status, 200)
        data = json.loads(body)
        self.assertEqual(data["fps"], 25.0)
        self.assertEqual(data["segments"], [[0.48, 1.32], [1.80, 2.68], [3.04, 4.12]])

    def test_segment_empty_body_400(self):
        status, body, _ = _req(self.srv, "POST", "/segment", data=b"")
        self.assertEqual(status, 400)


class CorsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.srv = _start_server()

    @classmethod
    def tearDownClass(cls):
        cls.srv.shutdown()

    def test_preflight(self):
        status, _, headers = _req(self.srv, "OPTIONS", "/segment")
        self.assertEqual(status, 204)
        self.assertEqual(headers.get("Access-Control-Allow-Origin"), "*")
```

Note: `urlopen` with `data=b""` sends a GET; force POST by passing a non-None body. For the empty-body case use an explicit method via a 0-length POST:

Replace `test_segment_empty_body_400` body with:

```python
    def test_segment_empty_body_400(self):
        host, port = self.srv.server_address
        req = urllib.request.Request(f"http://{host}:{port}/segment",
                                     data=b"", method="POST",
                                     headers={"Content-Length": "0"})
        try:
            with urllib.request.urlopen(req) as r:
                status = r.status
        except urllib.error.HTTPError as e:
            status = e.code
        self.assertEqual(status, 400)
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `cd /home/gomer/vjepa-sign-segmentation && python3 -m unittest test_infer_server -v`
Expected: PASS (5 tests). The server code from Task 1 already implements `/segment`, empty-body 400, and CORS — these tests lock that behavior in.

- [ ] **Step 3: Save in place**

Run: `python3 -m unittest test_infer_server -v 2>&1 | tail -3`
Expected: `OK`.

---

## Task 3: Real warm segmenter (`load_real_segmenter` + `main`)

**Files:**
- Modify: `/home/gomer/vjepa-sign-segmentation/infer_server.py`

This wraps `vjepa_seg` and loads the backbone + head **once**. `infer.run()` reloads the backbone every call, so we do NOT call it — we hold the loaded backbone/head and reproduce `run()`'s per-video body (read frames → extract tokens → head argmax → upsample → label_fixes → bio_to_segments). All heavy imports are lazy so the module top stays stdlib-only (tests never trigger this).

- [ ] **Step 1: Append the real wrapper + main to `infer_server.py`**

Add after the `serve()` function:

```python
class RealSegmenter:
    """Warm V-JEPA2 backbone + MS-TCN head, loaded once. .segment(path)->[[s,e],..]."""
    TARGET_FPS = 25.0

    def __init__(self, device, models_dir):
        import torch
        from vjepa_seg.extract import load_backbone
        from vjepa_seg.model import RegionMSTCN

        self.torch = torch
        seg_path = os.path.join(models_dir,
                                "native_segmenter_ftdgs_mstcn_vj_s1r9.pt")
        if not os.path.exists(seg_path):
            sys.exit(f"[vjepa_seg] model not found: {seg_path}\n"
                     "Run: bash download_models.sh")
        # Backbone (auto-downloads ~1.2 GB from HF Hub on first run, then cached).
        self.model, self.dev = load_backbone(device=device)
        # Head.
        # weights_only=True: the checkpoint is a plain state_dict of tensors
        # (from download_models.sh) — avoids unpickling arbitrary objects.
        sd = torch.load(seg_path, map_location=self.dev, weights_only=True)
        token_dim = sd["tcn.stage1.inp.weight"].shape[1]
        self.head = RegionMSTCN(token_dim).to(self.dev)
        self.head.load_state_dict(sd)
        self.head.eval()

    def segment(self, video_path):
        from vjepa_seg.extract import extract_tokens, read_video_frames
        from vjepa_seg.postprocess import (upsample_to_frames, bio_to_segments,
                                           label_fixes)
        torch = self.torch
        frames, _ = read_video_frames(video_path, tgt_fps=self.TARGET_FPS)
        if not frames:
            return []
        Z, frames_per_token = extract_tokens(self.model, frames, self.dev,
                                             phase_dual=True, regions=3)
        z_t = torch.from_numpy(Z.astype("float32")).unsqueeze(0).to(self.dev)
        with torch.no_grad():
            stage_outs = self.head(z_t)
        tok_bio = stage_outs[-1].argmax(-1).squeeze(0).cpu().numpy()
        n_frames = len(frames)
        frame_bio = upsample_to_frames(tok_bio, frames_per_token, n_frames)
        frame_bio = label_fixes(torch.from_numpy(frame_bio)).numpy()
        segments = bio_to_segments(frame_bio, self.TARGET_FPS)
        return [[float(s), float(e)] for s, e in segments]


def load_real_segmenter(device, models_dir):
    return RealSegmenter(device, models_dir)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--device", default="cpu")
    ap.add_argument("--port", type=int, default=8001)
    ap.add_argument("--host", default="127.0.0.1")
    ap.add_argument("--models-dir", default=os.path.join(HERE, "models"))
    ap.add_argument("--fake", action="store_true",
                    help="use FakeSegmenter (no model load)")
    args = ap.parse_args()
    global SEGMENTER, DEVICE
    DEVICE = args.device
    SEGMENTER = FakeSegmenter() if args.fake else load_real_segmenter(
        args.device, args.models_dir)
    serve(args.host, args.port)


if __name__ == "__main__":
    main()
```

- [ ] **Step 2: Verify the stdlib tests still pass (no torch needed)**

Run: `cd /home/gomer/vjepa-sign-segmentation && python3 -m unittest test_infer_server -v`
Expected: PASS (5 tests) — the lazy imports mean adding `RealSegmenter` does not pull torch into the test path.

- [ ] **Step 3: Verify `--fake` server boots and answers (smoke, on cloud, no GPU)**

Run:
```bash
cd /home/gomer/vjepa-sign-segmentation && \
python3 infer_server.py --fake --port 8011 --host 127.0.0.1 & \
SRV=$!; sleep 1; \
curl -fsS http://127.0.0.1:8011/health; echo; \
curl -fsS -X POST --data-binary 'abc' http://127.0.0.1:8011/segment; echo; \
kill $SRV
```
Expected:
`{"ok": true, "model": "vjepa_seg", "device": "cpu", "fake": true}`
`{"fps": 25.0, "segments": [[0.48, 1.32], [1.8, 2.68], [3.04, 4.12]]}`

- [ ] **Step 4: Save in place** (no git).

---

## Task 4: Deploy artifacts (service + tunnel + apache + installers)

**Files:**
- Create: `/home/gomer/vjepa-sign-segmentation/deploy/vjepa-segment.service`
- Create: `/home/gomer/vjepa-sign-segmentation/deploy/vjepa-tunnel.service`
- Create: `/home/gomer/vjepa-sign-segmentation/deploy/apache-sign-segmenter.conf`
- Create: `/home/gomer/vjepa-sign-segmentation/deploy/provision-monsterfish.sh`
- Create: `/home/gomer/vjepa-sign-segmentation/deploy/install-segment.sh`

- [ ] **Step 1: monsterfish user-systemd unit** — `deploy/vjepa-segment.service` (mirrors the spotter's `signrep-infer.service`, port 8001):

```ini
[Unit]
Description=V-JEPA sign-segmentation inference server (GPU, warm model)
After=network.target

[Service]
Type=simple
WorkingDirectory=/mnt/fishbowl/gomer/vjepa-sign-segmentation
ExecStart=/mnt/fishbowl/gomer/vjepa-sign-segmentation/.venv/bin/python infer_server.py --device cuda --host 127.0.0.1 --port 8001
Restart=on-failure
RestartSec=3

[Install]
WantedBy=default.target
```

- [ ] **Step 2: cloud ssh tunnel unit** — `deploy/vjepa-tunnel.service` (mirrors `signrep-tunnel.service`, port 8001):

```ini
[Unit]
Description=SSH tunnel to monsterfish GPU segmentation (127.0.0.1:8001)
After=network-online.target
Wants=network-online.target

[Service]
User=gomer
Environment=HOME=/home/gomer
ExecStart=/usr/bin/ssh -N -T -o BatchMode=yes -o ExitOnForwardFailure=yes -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o StrictHostKeyChecking=accept-new -i /home/gomer/.ssh/id_ed25519 -o UserKnownHostsFile=/home/gomer/.ssh/known_hosts -L 127.0.0.1:8001:127.0.0.1:8001 gomer@monsterfish
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

- [ ] **Step 3: Apache conf** — `deploy/apache-sign-segmenter.conf` (mirrors `apache-sign-spotter.conf`, port 8001):

```apache
# Reverse-proxy the warm V-JEPA segmentation server (warm model on 127.0.0.1:8001,
# reached over the SSH tunnel to monsterfish). Drop into the signcollect.nl HTTPS vhost.
#   a2enmod proxy proxy_http
ProxyTimeout 300
<Location /sign-segmenter/>
    # Video uploads can be tens of MB; allow large bodies on this path.
    LimitRequestBody 0
    ProxyPass        http://127.0.0.1:8001/
    ProxyPassReverse http://127.0.0.1:8001/
</Location>
```

- [ ] **Step 4: monsterfish provisioning script** — `deploy/provision-monsterfish.sh`. Run **from cloud**; pushes the repo to monsterfish, builds the venv, downloads models, installs + starts the user service. Mirrors how the spotter venv was bootstrapped (`python3.11 -m venv --without-pip` + get-pip.py, since `python3.11-venv`/ensurepip is unavailable and there is no sudo on monsterfish).

```bash
#!/usr/bin/env bash
# provision-monsterfish.sh — deploy the vjepa segmentation server to monsterfish.
# Run from cloud (signcollect). Idempotent.
set -eu
LOCAL="/home/gomer/vjepa-sign-segmentation"
REMOTE="/mnt/fishbowl/gomer/vjepa-sign-segmentation"
HOST="gomer@monsterfish"

echo "== 1. rsync repo -> monsterfish (excluding venv/models/caches) =="
rsync -az --delete \
  --exclude '.venv' --exclude 'models' --exclude '__pycache__' \
  --exclude '*.pyc' --exclude 'example.mp4' \
  "$LOCAL/" "$HOST:$REMOTE/"

echo "== 2. build venv (python3.11, no ensurepip) + install requirements + cuda torch =="
rtk proxy ssh "$HOST" '
  set -eu
  cd /mnt/fishbowl/gomer/vjepa-sign-segmentation
  if [ ! -x .venv/bin/python ]; then
    python3.11 -m venv --without-pip .venv
    curl -fsS https://bootstrap.pypa.io/get-pip.py -o /tmp/get-pip.py
    .venv/bin/python /tmp/get-pip.py
  fi
  .venv/bin/pip install --upgrade pip
  .venv/bin/pip install torch --index-url https://download.pytorch.org/whl/cu126
  .venv/bin/pip install -r requirements.txt
'

echo "== 3. download MS-TCN head (~2 MB); V-JEPA backbone auto-downloads on first run =="
rtk proxy ssh "$HOST" '
  set -eu
  cd /mnt/fishbowl/gomer/vjepa-sign-segmentation
  bash download_models.sh
'

echo "== 4. install + start the user systemd service (port 8001, cuda) =="
rtk proxy ssh "$HOST" '
  set -eu
  mkdir -p ~/.config/systemd/user
  cp /mnt/fishbowl/gomer/vjepa-sign-segmentation/deploy/vjepa-segment.service ~/.config/systemd/user/
  systemctl --user daemon-reload
  systemctl --user enable --now vjepa-segment
  systemctl --user restart vjepa-segment
'

echo "== 5. wait for health on monsterfish (first run downloads the backbone) =="
rtk proxy ssh "$HOST" '
  for i in $(seq 1 80); do
    if curl -fsS http://127.0.0.1:8001/health >/tmp/vj_health.json 2>/dev/null; then
      echo "monsterfish health: $(cat /tmp/vj_health.json)"; exit 0
    fi
    sleep 5
  done
  echo "NOT healthy in time; recent logs:"; journalctl --user -u vjepa-segment -n 40 --no-pager; exit 1
'
echo "Done provisioning monsterfish."
```

- [ ] **Step 5: cloud installer** — `deploy/install-segment.sh` (installs the tunnel + apache, verifies HTTPS health). Mirrors `install-inference.sh`; needs root (re-execs with sudo).

```bash
#!/usr/bin/env bash
# install-segment.sh — wire the cloud-side tunnel + Apache for /sign-segmenter/.
# Run on cloud (signcollect). Re-execs with sudo. Idempotent.
set -u
DEPLOY="/home/gomer/vjepa-sign-segmentation/deploy"
PUBLIC="https://signcollect.nl/sign-segmenter"

if [ "$(id -u)" -ne 0 ]; then exec sudo -- "$0" "$@"; fi
red(){ printf '\033[31m%s\033[0m\n' "$*"; }
green(){ printf '\033[32m%s\033[0m\n' "$*"; }
fail(){ red "FAILED: $*"; exit 1; }

echo "== 1. SSH tunnel service (127.0.0.1:8001 -> monsterfish) =="
[ -f "$DEPLOY/vjepa-tunnel.service" ] || fail "missing vjepa-tunnel.service"
cp "$DEPLOY/vjepa-tunnel.service" /etc/systemd/system/vjepa-tunnel.service
systemctl daemon-reload
systemctl enable --now vjepa-tunnel
systemctl restart vjepa-tunnel
sleep 3
for i in $(seq 1 20); do
  curl -fsS http://127.0.0.1:8001/health >/tmp/vj_local.json 2>/dev/null && break
  sleep 2
done
[ -s /tmp/vj_local.json ] || { journalctl -u vjepa-tunnel -n 20 --no-pager; fail "no local 8001 health (tunnel/monsterfish down?)"; }
green "local health: $(cat /tmp/vj_local.json)"

echo "== 2. Apache /sign-segmenter/ -> 127.0.0.1:8001 =="
a2enmod proxy proxy_http >/dev/null
cp "$DEPLOY/apache-sign-segmenter.conf" /etc/apache2/conf-available/sign-segmenter.conf
a2enconf sign-segmenter >/dev/null
apachectl configtest || fail "apache configtest failed"
systemctl reload apache2
green "apache reloaded with /sign-segmenter/ proxy"

echo "== 3. verify HTTPS health =="
curl -fsS "$PUBLIC/health" >/tmp/vj_https.json 2>/dev/null \
  && green "HTTPS health: $(cat /tmp/vj_https.json)" \
  || red "WARN: could not reach $PUBLIC/health from this host"
green "Done."
```

- [ ] **Step 6: Make scripts executable + save in place**

Run: `chmod +x /home/gomer/vjepa-sign-segmentation/deploy/*.sh && ls -l /home/gomer/vjepa-sign-segmentation/deploy/`
Expected: 6 deploy files listed, `.sh` files executable.

---

## Task 5: Provision monsterfish + cloud tunnel/apache (live)

**Files:** none (runs the Task 4 scripts). This is the infra cutover — confirm with the user before running (it installs services and downloads ~1.2 GB on monsterfish).

- [ ] **Step 1: Provision monsterfish**

Run: `bash /home/gomer/vjepa-sign-segmentation/deploy/provision-monsterfish.sh`
Expected: ends with `monsterfish health: {"ok": true, "model": "vjepa_seg", "device": "cuda", "fake": false}`.

- [ ] **Step 2: Install cloud tunnel + apache**

Run: `bash /home/gomer/vjepa-sign-segmentation/deploy/install-segment.sh`
Expected: `local health: {…"device": "cuda", "fake": false}` and `HTTPS health: {…}`.

- [ ] **Step 3: Live end-to-end segment of the sample clip**

Run:
```bash
curl -fsS -X POST --data-binary @/home/gomer/vjepa-sign-segmentation/example.mp4 \
  https://signcollect.nl/sign-segmenter/segment | python3 -m json.tool
```
Expected: `{"fps": 25.0, "segments": [[…], …]}` with a plausible, non-empty list.

- [ ] **Step 4: Confirm the spotter is still healthy (untouched)**

Run: `curl -fsS https://signcollect.nl/sign-spotter/health; echo`
Expected: `{"ok": true, "model": "fine-tuned", "dict": "coverage", "fake": false}`.

---

## Task 6: Create `v3/` frontend folder

**Files:**
- Create: `/web/annotation-tool/v3/` (copy of `v2/`)

- [ ] **Step 1: Copy v2 → v3**

Run: `cp -a /web/annotation-tool/v2/. /web/annotation-tool/v3/ && ls /web/annotation-tool/v3/`
Expected: `index.html  mod.js  glosses_transformed.json  vendor` present.

- [ ] **Step 2: Commit the copy (annotation-tool IS git-tracked)**

```bash
cd /web/annotation-tool
git add v3
git commit -m "v3: copy v2/ as starting point for vjepa segmentation

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```
Expected: commit succeeds.

---

## Task 7: Frontend — `SEG_BASE` + `segInfer()`

**Files:**
- Modify: `/web/annotation-tool/v3/index.html` (near the `INFER_BASE` block, ~line 586)

- [ ] **Step 1: Add `SEG_BASE` next to `INFER_BASE`**

Find:
```javascript
    const INFER_BASE = (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')
```
Locate the end of that `INFER_BASE` assignment (it spans two lines, dev vs prod). Immediately after it, add:

```javascript
    // Segmentation server (V-JEPA on monsterfish via the SSH tunnel + Apache proxy).
    const SEG_BASE = (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')
      ? 'http://localhost:8001'
      : 'https://signcollect.nl/sign-segmenter';
```

- [ ] **Step 2: Add `segInfer()` next to `inferUpload`/`inferSpot`**

After the `inferSpot` function (ends ~line 608), add:

```javascript
    // POST the (normalized 25fps) video blob to the segmenter; returns
    // [{start,end,text:''}] ready for replaceTier2Subtitles().
    async function segInfer(blob) {
      const r = await fetch(SEG_BASE + '/segment', { method: 'POST', body: blob });
      if (!r.ok) throw new Error('segment HTTP ' + r.status);
      const data = await r.json();
      const segs = Array.isArray(data.segments) ? data.segments : [];
      return segs.map(([s, e]) => ({ start: s, end: e, text: '' }));
    }
```

- [ ] **Step 3: Syntax sanity check**

Run: `node --input-type=module --check < <(sed -n '/<script type="module">/,/<\/script>/p' /web/annotation-tool/v3/index.html | sed '1d;$d')`
Expected: no output (exit 0). If `node` is unavailable, skip and rely on the browser console in Task 11.

- [ ] **Step 4: Commit**

```bash
cd /web/annotation-tool
git add v3/index.html
git commit -m "v3: add SEG_BASE + segInfer() segmentation client

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 8: Frontend — `runSegmentation()` with indeterminate loading bar

**Files:**
- Modify: `/web/annotation-tool/v3/index.html`

`replaceTier2Subtitles(segments)`, the `segmentationProgressModal`, `updateSegmentationProgress`, `renderAll`, `uploadSubtitles`, `runSpot`, and `isSegmentationInProgress` all already exist (verified). `runSegmentation` reuses them.

- [ ] **Step 1: Add `runSegmentation()` near the other segmentation helpers**

Add immediately above the `// Add progress tracking state` / `let isSegmentationInProgress = false;` line (~line 3092). NOTE: `isSegmentationInProgress` is declared there — `runSegmentation` references it, which is fine (same module scope, hoisted by `let`? No — `let` is not hoisted for use before declaration). To avoid a temporal-dead-zone error, place `runSegmentation` **after** the `let isSegmentationInProgress = false;` line instead. Insert it right after that line:

```javascript
    // V-JEPA auto-segmentation: POST the video, fill tier-2, then auto-spot each segment.
    let segElapsedTimer = null;
    async function runSegmentation() {
      if (!SEG_BASE) return;
      if (isSegmentationInProgress) {
        showToast('Segmentation is already in progress. Please wait.', 'error', 3000);
        return;
      }
      if (!videoBlob) { showToast('No video loaded to segment.', 'error', 3000); return; }
      isSegmentationInProgress = true;

      // Indeterminate progress: striped/animated bar at 100%, ticking elapsed seconds.
      const $bar = $('#segmentationProgressBar');
      $bar.removeClass('bg-info bg-warning bg-success').addClass('bg-info');
      $bar.css('width', '100%').addClass('progress-bar-striped progress-bar-animated');
      $('#segmentationProgressPercent').text('');
      $('#segmentationStage').text('Segmenting…');
      const t0 = Date.now();
      $('#segmentationProgressMessage').text('Running V-JEPA segmentation…');
      clearInterval(segElapsedTimer);
      segElapsedTimer = setInterval(() => {
        const s = Math.round((Date.now() - t0) / 1000);
        $('#segmentationProgressMessage').text('Running V-JEPA segmentation… ' + s + 's');
      }, 1000);
      $('#segmentationProgressModal').modal('show');

      try {
        const segments = await segInfer(videoBlob);
        if (!segments.length) {
          showToast('No segments detected in the video.', 'error', 4000);
        } else {
          replaceTier2Subtitles(segments);
          renderAll();
          uploadSubtitles();
          // Auto-spot every new tier-2 segment (same as manual-create auto-spot).
          const tier2Id = tiers[2] && tiers[2].id;
          subtitles.filter(s => s.tierId === tier2Id).forEach(s => runSpot(s));
        }
      } catch (err) {
        console.error('[seg] segmentation failed', err);
        showToast('Auto-segmentation failed: ' + (err && err.message ? err.message : err), 'error', 6000);
      } finally {
        clearInterval(segElapsedTimer); segElapsedTimer = null;
        await new Promise((resolve) => {
          $('#segmentationProgressModal').one('hidden.bs.modal', resolve);
          $('#segmentationProgressModal').modal('hide');
        });
        $bar.removeClass('progress-bar-striped progress-bar-animated');
        isSegmentationInProgress = false;
      }
    }
```

- [ ] **Step 2: Syntax sanity check** (same command as Task 7 Step 3). Expected: exit 0.

- [ ] **Step 3: Commit**

```bash
cd /web/annotation-tool
git add v3/index.html
git commit -m "v3: runSegmentation() — indeterminate bar, fill tier-2, auto-spot

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 9: Frontend — auto-trigger on drop + repurpose the Auto-Segment button

**Files:**
- Modify: `/web/annotation-tool/v3/index.html`

- [ ] **Step 1: Auto-trigger in the video `onFinish`**

Find (in `window.start`'s `onFinish`, ~line 1038):
```javascript
          updateTimelineWidth();
          renderAll();
          //onclick on button id normal
          document.querySelector('.speed-btn[data-speed="1"]').click();
```
Replace with:
```javascript
          updateTimelineWidth();
          renderAll();
          //onclick on button id normal
          document.querySelector('.speed-btn[data-speed="1"]').click();
          // Auto-segment a freshly dropped video — but only if it has no segments
          // yet (e.g. an EAF dropped alongside it would pre-populate tier-2).
          const t2 = tiers[2] && tiers[2].id;
          const hasSegments = t2 && subtitles.some(s => s.tierId === t2);
          if (!hasSegments) runSegmentation();
```

- [ ] **Step 2: Repurpose the `autoSegmentBtn` click handler**

Find the handler header (~line 3096):
```javascript
    // Auto-Segmentation Button Event Handler
    document.getElementById('autoSegmentBtn').addEventListener('click', async function() {
```
Read from there to the end of that `addEventListener('click', …)` call (it closes at `});` around line 3134, just before the `/** Process segmentation via ISS Server… */` comment). Replace the **entire** handler with:

```javascript
    // Auto-Segmentation Button — re-run V-JEPA segmentation on the loaded video.
    document.getElementById('autoSegmentBtn').addEventListener('click', function() {
      runSegmentation();
    });
```

- [ ] **Step 3: Remove the now-dead ISS/HaMeR code**

The old WebSocket segmentor functions are now unreferenced. Confirm each is unused elsewhere, then delete its definition:
- `processSegmentationViaISS`
- `handleCompletedSegmentation`
- `formatStageName`
- `parseVTTToSegments` — **verify first**: `grep -n "parseVTTToSegments" v3/index.html`. If referenced only inside `handleCompletedSegmentation`, delete it too; if used elsewhere (e.g. EAF/VTT import), KEEP it.

For each function to delete, run `grep -nc "<name>" /web/annotation-tool/v3/index.html`. Delete the definition only when the sole remaining reference is its own declaration. Keep `replaceTier2Subtitles`, `revertToOriginalSegments`, `updateSegmentationProgress`, `showSegmentationError`, and `timeToSeconds` — they are reused or harmless.

If unsure whether a helper is dead, leave it — dead code is lower-risk than removing something still referenced. The functional requirement is only that the button calls `runSegmentation`.

- [ ] **Step 4: Syntax sanity check** (Task 7 Step 3 command). Expected: exit 0.

- [ ] **Step 5: Commit**

```bash
cd /web/annotation-tool
git add v3/index.html
git commit -m "v3: auto-segment on drop (if empty); repurpose Auto-Segment button to vjepa

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 10: Repoint the redirect to v3

**Files:**
- Modify: `/web/annotation-tool/index.html`

- [ ] **Step 1: Update the redirect target**

In `/web/annotation-tool/index.html`, change every `./v2/` to `./v3/`:
- `<meta http-equiv="refresh" content="0; url=./v3/">`
- `location.replace('./v3/' + location.search + location.hash);`
- Body link: `Redirecting to the <a href="./v3/">annotation tool</a>…` (keep the `v1`/`v2` previous-version links: `(previous versions: <a href="./v2/">v2</a>, <a href="./v1/">v1</a>)`).

- [ ] **Step 2: Verify the file**

Run: `grep -nE "v1|v2|v3" /web/annotation-tool/index.html`
Expected: refresh + `location.replace` point to `./v3/`; both `./v2/` and `./v1/` remain only as previous-version links.

- [ ] **Step 3: Commit**

```bash
cd /web/annotation-tool
git add index.html
git commit -m "Redirect /annotation-tool/ to v3/ (keep v1/v2 links)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 11: Live verification (manual smoke)

**Files:** none.

- [ ] **Step 1: Redirect + reachability**

Run:
```bash
for p in / /v3/ /v2/ /v1/; do printf '%s ' "$p"; \
  curl -s -o /dev/null -w '%{http_code}\n' "https://signcollect.nl/annotation-tool$p"; done
curl -fsS https://signcollect.nl/sign-segmenter/health; echo
```
Expected: all `200`; health shows `device:"cuda", fake:false`.

- [ ] **Step 2: Browser — fresh drop (no EAF)**

Open `https://signcollect.nl/annotation-tool/` (lands on v3). Drop a short `.mp4`. Confirm: frames load → loading modal "Segmenting…" with ticking seconds → modal closes → tier-2 fills with segments → each segment box shows a spinner then top-10 glosses. No console errors.

- [ ] **Step 3: Browser — drop video + EAF together**

Drop a video **and** an EAF with existing segments. Confirm auto-segmentation is **skipped** (tier-2 keeps the EAF segments; no segmentation modal).

- [ ] **Step 4: Browser — manual re-run**

Click the **Auto-Segment** button on a loaded video. Confirm it re-runs segmentation (modal → tier-2 replaced → re-spot). The Revert button restores the previous tier-2.

- [ ] **Step 5: Spotter regression**

Confirm gloss hover-preview and per-segment spotting still work (the spotter path is unchanged).

---

## Self-Review notes (for the implementer)

- **Spec coverage:** server (T1–3), tests (T1–2), deploy (T4), live cutover (T5), v3 copy (T6), `SEG_BASE`/`segInfer` (T7), `runSegmentation`+indeterminate bar (T8), auto-trigger-if-empty + button repurpose + ISS removal (T9), redirect (T10), verification (T11). All spec sections mapped.
- **TDZ guard:** `runSegmentation` is placed AFTER `let isSegmentationInProgress = false;` (T8 Step 1) to avoid a temporal-dead-zone reference error.
- **Naming consistency:** `SEG_BASE`, `segInfer`, `runSegmentation`, `FakeSegmenter`, `RealSegmenter`, `vjepa-segment.service`, `vjepa-tunnel.service`, `/sign-segmenter/`, port `8001` used uniformly.
- **Untracked vs tracked:** `/home/gomer/vjepa-sign-segmentation` is NOT git-tracked (save in place); `/web/annotation-tool` IS (commit per task).
- **rtk:** all remote/monsterfish commands wrapped in `rtk proxy ssh` with inline single-quoted scripts.
