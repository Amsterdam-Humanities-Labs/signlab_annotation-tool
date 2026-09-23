# signlab_annotation-tool
A browser editor for annotating NGT video on a timeline with several tiers. It saves ELAN EAF files.

## What it does
| Folder | Contents |
|---|---|
| `v3/` | The editor. Drop a video (and optionally an `.eaf`), annotate, and it autosaves `<video>.eaf` to a local folder. Adds automatic segmentation (V-JEPA 2) and gloss spotting (SignRep). User guide: `v3/README.md`. |
| `webcam/` | Redirect to `v3/?mode=webcam` (the editor, starting from a webcam recording). |
| `clusters/` | Review pages for pre-computed cluster segmentations: `index.html` (clusters), `merge.html` (merge review), `segview.html`, `videos.html`. `clusters/tool/` redirects to `v3/?mode=clusters`, which opens from a link and autosaves to `clusters/edit/io.php`. |
| `practice/` | An NGT practice app. The gloss spotter scores your sign. See `practice/README.md`. |
| `docs/` | Design specs and paper sources. |

## Where it runs
- Core server: `<webroot>/annotation-tool`, https://signcollect.nl/annotation-tool/ (the root redirects to `v3/`).
- Demo hosts get the same tree through the stack deploy.

## Status
Production.

## How to run / deploy
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack) deploys it (`repos.tsv`: `annotation-tool`, branch `main`). There is no build step.

`vendor/ffmpeg/esm/ffmpeg-core.wasm` (32 MB) is not in git. The stack's `fetch-ffmpeg-core.sh` puts a hash-checked copy in each editor. For a local preview:
```bash
for d in v3 webcam clusters/tool; do mkdir -p $d/vendor/ffmpeg/esm
  curl -fL https://cdn.jsdelivr.net/npm/@ffmpeg/core@0.12.6/dist/esm/ffmpeg-core.wasm -o $d/vendor/ffmpeg/esm/ffmpeg-core.wasm; done
cp -rn v3/vendor/ffmpeg/. clusters/tool/vendor/ffmpeg/   # clusters/tool has no loaders in git
python3 -m http.server 8799   # open http://localhost:8799/ in Chrome or Edge
```
Tests for the practice logic: `cd practice && npm test` (Node's built-in test runner).

A daily cron job runs `clusters/backup_eaf.sh`. It copies the EAFs and review data to `gebarenoverleg_media/studioFiles/annotation-tool/segments/` and keeps 30 days.

## Configuration
- No credentials. `sc_paths.php` is the path resolver copied from signcollect-lib. Keep it byte-identical.
- `<webroot>/annotation_data/clusters/` lives outside the checkout and must be writable by www-data. `clusters/edit/io.php` and `merge_io.php` write `status.json`, `merge_decisions.json` and `eaf/*.eaf` there. The first request copies the start data from `clusters/edit/seed/`.
- Saving (POST) needs a portal login (`menu_beta/php_api/session.php` in signCollect-v2) and only accepts same-origin requests.
- `clusters/out/`, `clusters/vid/` and `clusters/clips/` hold pipeline output and are gitignored.
- `SC_WEB_ROOT` (environment, default `/web`) tells `backup_eaf.sh` where the install root is.

## Dependencies
| Used for | Endpoint |
|---|---|
| Video conversion, automatic segmentation | `https://signcollect.nl/sign-segmenter` (hardcoded) |
| Gloss spotting, practice scores | `https://signcollect.nl/sign-spotter` (hardcoded) |
| Handshape search | `/zin/getHandshapes.php` on the same host ([signlab_zinnen-annotation](https://github.com/Amsterdam-Humanities-Labs/signlab_zinnen-annotation)) |
| Gloss video | `https://signcollect.nl/zin/getGlossVideo.php` (hardcoded, signlab_zinnen-annotation) |
| Gloss list | `/signbank_data/glosses_transformed.json`, or `/glosses_transformed.json` if that fails |
| Cluster video | URLs in `clusters/out/videos.json` (studio media) |

Only the core server has the segmenter and spotter proxies. On demo hosts the AI features fail without an error.
