# signlab_annotation-tool
Browser editor that annotates sign-language video on a multi-tier timeline and saves ELAN EAF files.

## What it does
| dir | what |
|---|---|
| `v3/` | the editor: drop a video (+ optional `.eaf`), annotate, autosave `<video>.eaf` to a local folder (File System Access API, Chrome/Edge). Auto-segmentation (V-JEPA 2) and gloss spotting (SignRep) via GPU services. User guide: `v3/README.md` |
| `webcam/` | v3 fork with a webcam recording as entry point (`webcam/README.md`) |
| `clusters/` | review UI over pre-computed cluster segmentations; `clusters/tool/` is a v3 fork with deep-link + autosave to `clusters/edit/io.php` |
| `practice/` | NGT practice app scored by the spotter (`practice/README.md`) |
| `docs/` | design specs, paper sources |

## Where it runs
- Production core server, `<webroot>/annotation-tool`, https://signcollect.nl/annotation-tool/ (root redirects to `v3/`).
- Demo hosts get the same tree via the stack deploy.

## Status
Production.

## How to run / deploy
Deployed by [signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack) (`repos.tsv`: `annotation-tool`, branch `main`). No build step.
`vendor/ffmpeg/esm/ffmpeg-core.wasm` (32 MB) is not in git; the stack's `fetch-ffmpeg-core.sh` puts a hash-verified copy in each editor. Local preview:
```bash
for d in v3 webcam clusters/tool; do mkdir -p $d/vendor/ffmpeg/esm
  curl -fL https://cdn.jsdelivr.net/npm/@ffmpeg/core@0.12.6/dist/esm/ffmpeg-core.wasm -o $d/vendor/ffmpeg/esm/ffmpeg-core.wasm; done
cp -rn v3/vendor/ffmpeg/. clusters/tool/vendor/ffmpeg/   # clusters/tool has no loaders in git
python3 -m http.server 8799   # open http://localhost:8799/ in Chrome or Edge
```
Cron: `clusters/backup_eaf.sh` daily, snapshots EAFs + review data to `gebarenoverleg_media/studioFiles/annotation-tool/segments/` (30 days).

## Configuration
- No credentials. `sc_paths.php` is the vendored signcollect-lib resolver (keep byte-identical).
- `<webroot>/annotation_data/clusters/` (outside the checkout, writable by www-data): `status.json`, `merge_decisions.json`, `eaf/*.eaf` written by `clusters/edit/io.php` and `merge_io.php`. Seeded from `clusters/edit/seed/` on first use. POSTs need a portal login (signCollect-v2's `menu_beta/php_api/session.php`); same-origin only.
- `clusters/out/`, `clusters/vid/`, `clusters/clips/` are gitignored pipeline output.

## Dependencies
| used for | endpoint |
|---|---|
| conversion + auto-segmentation | `https://signcollect.nl/sign-segmenter` (hardcoded) |
| gloss spotting, practice scoring | `https://signcollect.nl/sign-spotter` (hardcoded) |
| handshape search / gloss video | `https://signcollect.nl/getHandshapes.php`, `/zin/getGlossVideo.php` (signlab_zin) |
| gloss glossary | `/signbank_data/glosses_transformed.json`, falls back to `/glosses_transformed.json` |
| cluster video | URLs listed in `clusters/out/videos.json` (studio media) |

The segmenter/spotter proxies exist only on production; on demo hosts AI features fail quietly.
