# annotation-tool — standalone sign-language annotation editor

A browser-based multi-tier timeline editor that annotates sign-language video
and saves the result as an ELAN **EAF** file.

**Live URL:** https://signcollect.nl/annotation-tool/ — the root `index.html`
is a redirect; the current tool is `v3/`.

## What it does

You drop in a video (and optionally an existing `.eaf`), lay annotations out on
a multi-tier timeline, and the tool autosaves `<video-name>.eaf` into a folder
you pick. There is no login, no database and no per-user state on the server —
annotations live in local files, written through the browser's File System
Access API.

From v3 on it is not purely local: dropping a video also triggers automatic
**sign segmentation** (V-JEPA 2) and per-segment **gloss spotting** (SignRep)
against GPU inference servers, and video conversion to 25 fps happens
server-side rather than in browser ffmpeg.wasm.

The repo carries several variants, each its own directory with its own README:

| directory | what it is |
|---|---|
| `v3/` | the current editor — server-side conversion, auto-segmentation, auto-spotting. **This is what the root URL serves.** |
| `v2/`, `v1/` | previous iterations, kept live so old links keep working. v1/v2 do conversion in-browser with bundled ffmpeg.wasm. |
| `webcam/` | v3 with a webcam recording as the entry point instead of a file drop |
| `practice/` | not an editor: an NGT practice app that shows a sign, records your attempt, and scores it with the spotter. Has a small node test suite (`practice-core.test.mjs`). |
| `clusters/` | a review UI over pre-computed hand-cluster segmentations, plus its own copy of the v3 tool under `clusters/tool/` |
| `docs/` | design specs, implementation plans, and the LaTeX sources for two papers |

## Where it runs

The **signcollect core server** (production VPS), at
`https://signcollect.nl/annotation-tool/`, from `<root>/annotation-tool`. The
demo hosts deploy the same tree: dev2 under `/web`, dev-1 under
`/srv/signcollect/web`.

The editor itself needs no server beyond a static one — it must be served over
HTTP/HTTPS, because the File System Access API and the ES-module import of
`mod.js` do not work from a `file://` URL. Chrome or Edge on the desktop:
Firefox and Safari have no File System Access API, so autosave is unavailable
there.

**One correction to the "browser-only" description:** `clusters/edit/io.php`
and `clusters/edit/merge_io.php` are small PHP endpoints that persist cluster
review decisions to `clusters/edit/status.json` and corrected EAFs to
`clusters/edit/eaf/`. They need PHP and a web-writable `clusters/edit/`. They
are the only server-side code in the repo, and the editor does not use them.

## Status

**Production.**

## How to deploy it

No build step. Everything is static files plus two small PHP endpoints.

Deployment is by `interface_deploy/scripts/repos.tsv` in
`signlab_signcollect-stack`, which maps `annotation-tool` → this repo on branch
`main`. `scripts/install.sh` runs `host-bootstrap.sh`, which clones (or fetches
and hard-resets) the repo into `<root>/annotation-tool`. On a demo host
`rewrite-urls.sh` then repoints hardcoded `signcollect.nl` URLs at the demo's
hostname.

### The one file a clone does not give you

`vendor/ffmpeg/esm/ffmpeg-core.wasm` — 32,129,114 bytes of `@ffmpeg/core`
0.12.6 — is **not in this repository**, and each of the five editors
(`v1/`, `v2/`, `v3/`, `webcam/`, `clusters/tool/`) loads its own copy of it by
a relative URL. Four identical copies used to be committed: 128 MB that every
clone of this repo paid for. `.gitignore` has named all five paths since the
file was introduced.

The deploy supplies it. `interface_deploy/scripts/fetch-ffmpeg-core.sh`
downloads the pinned release, refuses anything that does not hash to
`2390efa7fb66e7e42dbae15427571a5ffc96b829480904c30f471f0a78967f61`, caches it
outside the docroot and copies it into all five directories; the deploy's
`git clean` spares `vendor/`, so it survives every redeploy. The stack's
`scripts/verify.sh` asserts all five are served at the right length, because a
missing core does not break the page — it breaks the first conversion, later,
silently.

To preview locally, fetch it once and serve the directory over HTTP:

```bash
for d in v1 v2 v3 webcam clusters/tool; do
  mkdir -p "$d/vendor/ffmpeg/esm"
  curl -fL https://cdn.jsdelivr.net/npm/@ffmpeg/core@0.12.6/dist/esm/ffmpeg-core.wasm \
       -o "$d/vendor/ffmpeg/esm/ffmpeg-core.wasm"
done
# clusters/tool/ has no vendor/ loaders in git either — take v3's:
cp v3/vendor/ffmpeg/{ffmpeg.js,util.js,814.ffmpeg.js} clusters/tool/vendor/ffmpeg/
cp v3/vendor/ffmpeg/esm/ffmpeg-core.js clusters/tool/vendor/ffmpeg/esm/

python3 -m http.server 8799   # then open http://localhost:8799/ in Chrome or Edge
```

## Configuration

None. There is no config file, no credentials and no `.env` — which is why this
repo needs nothing from `signcollect-lib`. `clusters/edit/` needs to be
writable by the web user if the cluster review UI is used; nothing else on the
host has to be prepared.

## Dependencies

All of these are optional at the level of "the editor still opens without
them", but the AI features and the previews go dark:

| service | used for |
|---|---|
| `/sign-segmenter` (upload, convert, segment) | server-side ffmpeg conversion and V-JEPA 2 auto-segmentation. Warm GPU inference server. |
| `/sign-spotter` | SignRep gloss spotting per segment, and the practice app's scoring |
| `/getHandshapes.php` | handshape "smart search" |
| `/zin/getGlossVideo.php` (`signlab_zin`) | Signbank gloss video preview |
| `/glosses_transformed.json` | the gloss glossary, read from the docroot root of whichever site serves the tool. Produced by the Signbank connector in `signlab_signCollect-v2`; the tool used to carry its own 11 MB copy, which meant six copies that nothing ever refreshed. |
| `/gebarenoverleg_media/studioFilesMini/raw/` | studio video the cluster review UI plays |
| Signbank (`https://signbank.cls.ru.nl`) | gloss dictionary links |

The inference services are reached by path on the same origin
(`/sign-segmenter`, `/sign-spotter`), so Apache reverse-proxies them to the GPU
box. Those proxy rules live on the production core server only: the deploy repo
declares them explicitly out of scope, alongside Signbank, ISS_Server and
handshape_search, so on a demo host the AI features and handshape search have
no backend and fail quietly.

*TODO: confirm where the segmenter/spotter proxy configuration and the services
themselves are defined — they are not in this repo and not in
`signlab_signcollect-stack`.*

## EAF format notes

- **Loading:** reads `TIME_ORDER`/`TIME_SLOT` times and each `<TIER>`'s
  `ALIGNABLE_ANNOTATION`s. Tiers with no time-aligned annotations load as empty
  tiers.
- **Saving:** generates ELAN **EAF 3.0** XML with a `MEDIA_DESCRIPTOR` pointing
  at the video filename, deduplicated time slots, and one `<TIER>` per
  timeline. The output re-opens cleanly in ELAN and round-trips back into this
  tool.

For the full user guide — tiers, autosave, session restore, the inference
pipeline — see `v3/README.md`.
