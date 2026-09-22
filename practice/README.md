# Annotation tool, practice app
An NGT practice app. It shows a random sign, you repeat it on your webcam, and the gloss spotter (SignRep) scores you.

Live: https://signcollect.nl/annotation-tool/practice/

It is not an annotation editor. It has no timeline editing, no tiers and no EAF. It reuses the webcam recording and the two inference clients from v3. See `../v3/README.md` for the servers.

## Requirements
- Chrome or Edge on a desktop, over HTTPS, with a webcam. The app needs `getUserMedia` and the `:has()` CSS selector.
- The segmenter and spotter must be reachable. Without them the app cannot work.

## How it works
1. On load the app reads `/signbank_data/glosses_transformed.json` and the spotter's `GET /vocab`. It picks targets only from signs that the spotter knows and that have a Signbank video. So every target can be shown and can be scored. If `/vocab` does not answer, the app shows an error and does not start.
2. Watch: a random target sign plays in a loop on the left, with its gloss.
3. Start: after "Ready?" and "Sign!" the webcam records exactly 4 seconds while a countdown runs.
4. Analyse: the clip is uploaded, converted to 25 fps and segmented. The spotter returns the 10 most likely NGT glosses for the longest segment.
5. Result: the segments appear on the timeline and the glosses are listed. The target turns green if it is in the top 3, or if the spotter gives it a score above 0.7 (`SCORE_PASS`).
   - Match: your streak goes up by one and the next sign starts.
   - Miss: click **Retry**. After 3 attempts the sign plays in slow motion and the next sign starts.
6. **Skip** moves to the next sign. **Stop** cancels an attempt. A scoreboard shows your streak and matched/total.

The preview is mirrored so it feels natural. The recording is not mirrored, because the spotter needs the real orientation.

Scoring happens after the recording, not during it. Only the cursor and the countdown move in real time.

## Inference per attempt
```
webcam 4 s webm
  +- POST /sign-segmenter/upload --> {videoId}  (ffmpeg on the server, 25 fps mp4)
       +- POST {videoId} /sign-segmenter/segment --> [[start,end], ...]
       |     +- longest segment is the sign (fallback: a 2 s window in the middle)
       +- GET /sign-segmenter/video/{videoId} --> 25 fps mp4
             +- POST /sign-spotter/upload --> {videoId}
                  +- POST {videoId,start,end,topk:10} /sign-spotter/spot --> top 10 glosses
                       +- target in top 3 or score > 0.7?  match : miss
```
Both servers run on the GPU machine (monsterfish) behind the Apache proxies `/sign-segmenter/` and `/sign-spotter/`. On `localhost` the app uses the dev servers on ports `8001` (segmenter) and `8000` (spotter).

## Spotter endpoint `GET /vocab`
The spotter must return its gloss list:
```
GET /sign-spotter/vocab  ->  { "glosses": ["HUIS", "BOOM", ...] }
```
These are the same labels that `/spot` ranks. The app compares them after trimming and lowercasing, so the spelling must match `/spot`.

## Files
Serve the folder over HTTP or HTTPS; `file://` does not work. There is no build step.

| File | Purpose |
|---|---|
| `index.html` | The whole app (HTML, CSS and JS) |
| `practice-core.js` | Pure logic: target list, match rule, segment choice, attempts. Has unit tests. |
| `practice-core.test.mjs` | Unit tests for `practice-core.js` |

## Tests and local preview
```bash
cd practice
node --test                   # unit tests
python3 -m http.server 8799   # open http://localhost:8799/ in Chrome or Edge
```
On `localhost` the app uses dev servers on ports 8000 and 8001. To use the production servers instead, serve it from a host that is not `localhost`.
