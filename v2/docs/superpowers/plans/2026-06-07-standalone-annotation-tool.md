# Standalone Annotation Tool Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fork `/web/zin/subBeta4.html` into a standalone browser-only sign-language annotation editor at `/web/annotation-tool/` with drop-in video + EAF, dynamic multi-tier timelines, and File System Access API autosave — no PHP, no DB.

**Architecture:** Single self-contained `index.html` (forked) + copied `mod.js` (WebCodecs frame decoder) + bundled `glosses_transformed.json`. Server calls to `getZinnen.php` are removed. EAF is parsed and generated client-side. Autosave writes an `.eaf` into a user-chosen folder via the File System Access API, with the directory handle cached in IndexedDB for session restore. Tiers become a dynamic array (1..N) instead of the hardcoded 3.

**Tech Stack:** HTML5 Canvas, WebCodecs (`mod.js`), Bootstrap 4.5 / jQuery (existing), File System Access API, IndexedDB, DOMParser/XMLSerializer for EAF.

**Environment note:** No test framework and no git repo in this codebase. "Verify" steps are manual browser checks in **Chrome or Edge** (File System Access API is unsupported in Firefox/Safari). "Commit" steps are file-save checkpoints. New pure functions (`parseEAF`, `buildEAF`) include console self-test snippets.

---

## File Structure

- Create: `/web/annotation-tool/index.html` — the app (forked from subBeta4.html)
- Create: `/web/annotation-tool/mod.js` — copy of `/web/zin/mod.js` (verbatim)
- Create: `/web/annotation-tool/glosses_transformed.json` — copy of `/web/glosses_transformed.json`
- Create: `/web/annotation-tool/temp/.gitkeep` — default autosave working folder
- Reference (read-only): `/web/zin/subBeta4.html` — the source

All edits in this plan are against `/web/annotation-tool/index.html` unless stated. Because the source file is large (3483 lines), tasks locate code by **searching for anchor strings / function names** rather than relying on line numbers, which shift as edits land.

---

### Task 1: Scaffold the standalone directory

**Files:**
- Create: `/web/annotation-tool/index.html` (copy)
- Create: `/web/annotation-tool/mod.js` (copy)
- Create: `/web/annotation-tool/glosses_transformed.json` (copy)
- Create: `/web/annotation-tool/temp/.gitkeep`

- [ ] **Step 1: Copy the source files**

```bash
mkdir -p /web/annotation-tool/temp
cp /web/zin/subBeta4.html /web/annotation-tool/index.html
cp /web/zin/mod.js /web/annotation-tool/mod.js
cp /web/glosses_transformed.json /web/annotation-tool/glosses_transformed.json
touch /web/annotation-tool/temp/.gitkeep
```

- [ ] **Step 2: Point the gloss fetch at the bundled local copy**

In `index.html`, find:
```js
fetch('../glosses_transformed.json')
```
Replace with:
```js
fetch('glosses_transformed.json')
```

- [ ] **Step 3: Remove the auth script**

Find and delete this line entirely:
```html
<script src="/userProtect.js"></script>
```

- [ ] **Step 4: Verify it loads (broken state expected)**

Serve the folder and open in Chrome:
```bash
cd /web/annotation-tool && python3 -m http.server 8777
```
Open `http://localhost:8777/`. Expected: page renders the editor chrome (toolbar, empty timeline). Console will show failed `getZinnen.php` fetches — that's expected and fixed in later tasks. Confirm `glosses_transformed.json` loads (200, not 404) in the Network tab.

- [ ] **Step 5: Checkpoint** — files copied and gloss path fixed.

---

### Task 2: Remove the status bar and its server saves

**Files:**
- Modify: `/web/annotation-tool/index.html`

- [ ] **Step 1: Remove the status bar HTML**

Find the block starting at `<span id="statusBar"` and ending with its closing `</span>` (the container holding `statusVideo`, `statusNederlands`, `statusGlossen`, `statusGvG` selects). Delete the entire `#statusBar` span and all four `<label>`/`<select>` children.

- [ ] **Step 2: Remove the saveStatus function**

Find and delete the whole function:
```js
function saveStatus(action, paramName, value) { ... }
```
(it POSTs to `getZinnen.php?action=' + action`).

- [ ] **Step 3: Remove the four status change listeners**

Delete all four blocks of the form:
```js
document.getElementById('statusVideo').addEventListener('change', function() { ... });
document.getElementById('statusNederlands').addEventListener('change', function() { ... });
document.getElementById('statusGlossen').addEventListener('change', function() { ... });
document.getElementById('statusGvG').addEventListener('change', function() { ... });
```

- [ ] **Step 4: Remove status-load code in `start()`**

In the `window.start` function, find the block that reads status values from the zin response and delete it:
```js
document.getElementById('statusVideo').value = zinData.status_video || '';
document.getElementById('statusNederlands').value = zinData.status_annotatie || '';
document.getElementById('statusGlossen').value = zinData.status_glos || '';
document.getElementById('statusGvG').value = zinData.status_gvg || '';
```
Also delete the adjacent `statusBar` show/hide block that references `Number(zinData.video_count) >= 2`.

- [ ] **Step 5: Verify**

Reload `http://localhost:8777/`. Expected: the status dropdowns are gone from the toolbar; no `statusVideo`-related console errors. Other features unaffected.

- [ ] **Step 6: Checkpoint** — status feature fully removed.

---

### Task 3: Strip the remaining getZinnen.php calls

**Files:**
- Modify: `/web/annotation-tool/index.html`

- [ ] **Step 1: Remove the zin-array fetch in `start()`**

Find the `fetch(...fetchZinArrayByFilename...)` call and its `await`/`.then` handling inside `start()`. Replace the whole block with a no-op that leaves `currentZinArray = []` and `currentSentenceRowId = null`:
```js
// Standalone: no sentence/zin metadata available without the server.
currentZinArray = [];
currentSentenceRowId = null;
```
Keep any downstream code that reads `currentZinArray` working with an empty array.

- [ ] **Step 2: Remove `fetchSubtitlesFromServer` and its call**

Find `async function fetchSubtitlesFromServer()` and the immediately-following invocation `fetchSubtitlesFromServer();`. Delete both. Subtitle data now comes from a dropped EAF (Task 6) or starts empty.

- [ ] **Step 3: Remove `verifySubtitlesOnDisk` and its call sites**

Delete `async function verifySubtitlesOnDisk()`. Then remove its callers: the line `verifySubtitlesOnDisk();` (after subtitle load) and the `visibilitychange` listener block that calls it.

- [ ] **Step 4: Remove the beforeunload sendBeacon save**

Find the `window.addEventListener('beforeunload', ...)` block containing `navigator.sendBeacon('getZinnen.php?action=saveSubtitlesAndEAFFiles...`. Replace its body with a simple unsaved-changes warning:
```js
window.addEventListener('beforeunload', function(e) {
  if (hasUnsavedChanges) { e.preventDefault(); e.returnValue = ''; }
});
```

- [ ] **Step 5: Neutralize the link-intercept `saveAndNavigate` path**

Find the `document.addEventListener('click', ...)` block that intercepts `a[href]` and calls `saveAndNavigate`. Since this is now a single-page tool with no navigation, delete that click-intercept block and the `popstate` listener that calls `saveAndNavigate`. Leave `saveAndNavigate` undeleted only if other code references it; otherwise delete it too. (Search for `saveAndNavigate` — if no remaining references, delete the function.)

- [ ] **Step 6: Verify**

Reload. Expected: Network tab shows **zero** requests to `getZinnen.php`. App loads with an empty timeline, no console errors referencing those functions.

- [ ] **Step 7: Checkpoint** — all `getZinnen.php` calls removed.

---

### Task 4: Convert tiers to a dynamic model (data layer)

This is the core refactor. Today each subtitle has `tier: 0|1|2` and rendering uses a hardcoded names array and `tierVisibility = {0,1,2}`. We introduce a `tiers` array and `tierId` on subtitles.

**Files:**
- Modify: `/web/annotation-tool/index.html`

- [ ] **Step 1: Add the tiers state and helpers near the other globals**

Find the globals area (where `let subtitles = [];`, `let uniqueId`, `const TIER_HEIGHT = 80;` are declared). Add directly after them:
```js
let tierUid = 0;
let tiers = [{ id: ++tierUid, name: 'Tier 1' }]; // dynamic 1..N

function tierIndexById(id) { return tiers.findIndex(t => t.id === id); }
function tierById(id) { return tiers.find(t => t.id === id); }
function tierVisible(id) {
  const t = tierById(id);
  return t ? (t.visible !== false) : false;
}
function defaultTierId() { return tiers.length ? tiers[0].id : null; }
```

- [ ] **Step 2: Replace the old `tierVisibility` object**

Find:
```js
const tierVisibility = { 0: true, 1: true, 2: true };
```
Delete it. Anywhere `tierVisibility[...]` is referenced, replace with `tierVisible(<tierId>)`. (Visibility now lives on each tier object as `t.visible`.)

- [ ] **Step 3: Migrate subtitle creation to `tierId`**

Find `addSubtitle()`. Change the pushed object from `tier: 0` to `tierId: defaultTierId()`:
```js
function addSubtitle() {
  uniqueId++;
  const newStart = 0, defaultDur = 0.2;
  subtitles.push({ id: uniqueId, text: '', start: newStart, end: newStart + defaultDur, tierId: defaultTierId() });
  renderAll();
  uploadSubtitles();
}
```

- [ ] **Step 4: Add the row-count helpers used by rendering**

Add near the tier helpers:
```js
function tierRows() { return tiers.length; } // number of timeline rows
function tierRowTop(tierId) { return tierIndexById(tierId) * TIER_HEIGHT; }
```

- [ ] **Step 5: Console self-test**

In the browser console after reload, run:
```js
tiers.length === 1 && tiers[0].name === 'Tier 1' && defaultTierId() === tiers[0].id
```
Expected: `true`.

- [ ] **Step 6: Checkpoint** — tier data model in place (rendering wired next).

---

### Task 5: Drive timeline rendering from the dynamic tiers

**Files:**
- Modify: `/web/annotation-tool/index.html`

- [ ] **Step 1: Replace the hardcoded tier-names rendering**

Find the timeline background/label rendering that uses:
```js
const trackNames = ["Nederlands", "Gebaar-voor-Gebaar", "Signbank ID glossen"];
```
Replace `trackNames` usage with `tiers.map(t => t.name)`. Iterate `tiers` to draw one row per tier:
```js
tiers.forEach((t, idx) => {
  // draw row background + label at top = idx * TIER_HEIGHT, height TIER_HEIGHT
  // gate visibility with: if (!tierVisible(t.id)) return; // or render dimmed
  // use t.name as the row label
});
```
Match the existing per-row markup the code already produced for the 3 fixed rows.

- [ ] **Step 2: Replace subtitle-box vertical positioning**

Find every place computing a box's vertical offset from the numeric tier, e.g.:
```js
box.style.top = (sub.tier * TIER_HEIGHT) + 'px';
```
Replace with:
```js
box.style.top = tierRowTop(sub.tierId) + 'px';
```
(There are typically two such sites — search for `* TIER_HEIGHT`.)

- [ ] **Step 3: Set the timeline track total height from tier count**

Find where the timeline track/container height is set (search for `TIER_HEIGHT` in width/height assignments, or a fixed `3 * TIER_HEIGHT`). Set height to `tierRows() * TIER_HEIGHT`:
```js
document.getElementById('timelineTrack').style.height = (tierRows() * TIER_HEIGHT) + 'px';
```
If no such assignment exists, add it inside `updateTimelineWidth()` (which already runs on render).

- [ ] **Step 4: Map drag-to-tier by vertical position**

Find the subtitle-box drag handler that, on vertical drop, assigns a tier (search for `sub.tier =` or a `Math.round(... / TIER_HEIGHT)`). Replace the assignment so the computed row index maps back to a tier id:
```js
const rowIdx = Math.max(0, Math.min(tiers.length - 1, Math.floor(newTop / TIER_HEIGHT)));
sub.tierId = tiers[rowIdx].id;
```

- [ ] **Step 5: Verify**

Reload. Click the add-subtitle control. Expected: a subtitle box appears on the single "Tier 1" row. Drag it vertically — with one tier it stays on row 0. No console errors. The timeline shows exactly one tier row.

- [ ] **Step 6: Checkpoint** — rendering is tier-array driven.

---

### Task 6: EAF parsing (drop-in load)

**Files:**
- Modify: `/web/annotation-tool/index.html`

- [ ] **Step 1: Add `parseEAF` near the EAF/save code**

Add this function (pure; takes XML string, returns `{tiers, subtitles}`):
```js
// Parse an ELAN .eaf XML string into { tiers:[{id,name}], subtitles:[{id,text,start,end,tierId}] }
function parseEAF(xmlString) {
  const doc = new DOMParser().parseFromString(xmlString, 'application/xml');
  if (doc.querySelector('parsererror')) throw new Error('Invalid EAF XML');

  // TIME_SLOT id -> seconds
  const slotSec = {};
  doc.querySelectorAll('TIME_ORDER > TIME_SLOT').forEach(ts => {
    const id = ts.getAttribute('TIME_SLOT_ID');
    const ms = ts.getAttribute('TIME_VALUE');
    slotSec[id] = ms != null ? (parseInt(ms, 10) / 1000) : 0;
  });

  const outTiers = [];
  const outSubs = [];
  let localTierUid = 0, localSubId = 0;

  doc.querySelectorAll('TIER').forEach(tierEl => {
    const name = tierEl.getAttribute('TIER_ID') || ('Tier ' + (localTierUid + 1));
    const tid = ++localTierUid;
    outTiers.push({ id: tid, name });
    tierEl.querySelectorAll('ANNOTATION > ALIGNABLE_ANNOTATION').forEach(ann => {
      const ref1 = ann.getAttribute('TIME_SLOT_REF1');
      const ref2 = ann.getAttribute('TIME_SLOT_REF2');
      const valEl = ann.querySelector('ANNOTATION_VALUE');
      outSubs.push({
        id: ++localSubId,
        text: valEl ? valEl.textContent : '',
        start: slotSec[ref1] || 0,
        end: slotSec[ref2] || 0,
        tierId: tid
      });
    });
  });

  if (!outTiers.length) outTiers.push({ id: ++localTierUid, name: 'Tier 1' });
  return { tiers: outTiers, subtitles: outSubs, nextTierUid: localTierUid, nextSubId: localSubId };
}
```

- [ ] **Step 2: Add a loader that applies a parsed EAF to app state**

```js
function loadEAFString(xmlString) {
  const parsed = parseEAF(xmlString);
  tiers = parsed.tiers;
  tierUid = parsed.nextTierUid;
  subtitles = parsed.subtitles;
  uniqueId = Math.max(uniqueId || 0, parsed.nextSubId);
  renderAll();
  rebuildTierControls(); // defined in Task 9
}
```

- [ ] **Step 3: Console self-test for the parser**

Reload, then in console:
```js
const sample = `<?xml version="1.0" encoding="UTF-8"?>
<ANNOTATION_DOCUMENT><TIME_ORDER>
<TIME_SLOT TIME_SLOT_ID="ts1" TIME_VALUE="1000"/>
<TIME_SLOT TIME_SLOT_ID="ts2" TIME_VALUE="2500"/>
</TIME_ORDER>
<TIER TIER_ID="Nederlands"><ANNOTATION><ALIGNABLE_ANNOTATION TIME_SLOT_REF1="ts1" TIME_SLOT_REF2="ts2">
<ANNOTATION_VALUE>hallo</ANNOTATION_VALUE></ALIGNABLE_ANNOTATION></ANNOTATION></TIER></ANNOTATION_DOCUMENT>`;
const p = parseEAF(sample);
console.log(p.tiers.length === 1, p.tiers[0].name === 'Nederlands',
  p.subtitles[0].text === 'hallo', p.subtitles[0].start === 1, p.subtitles[0].end === 2.5);
```
Expected: five `true` values.

- [ ] **Step 4: Checkpoint** — EAF parsing works (wired to drop zone in Task 8).

---

### Task 7: EAF generation (`buildEAF`)

**Files:**
- Modify: `/web/annotation-tool/index.html`

- [ ] **Step 1: Add `buildEAF`**

```js
// Build a valid ELAN .eaf XML string from current tiers + subtitles.
// videoFileName is the dropped video's name (for MEDIA_DESCRIPTOR).
function buildEAF(videoFileName) {
  // Collect unique time points (ms) -> slot ids
  const times = [];
  subtitles.forEach(s => { times.push(Math.round(s.start * 1000), Math.round(s.end * 1000)); });
  const uniq = Array.from(new Set(times)).sort((a, b) => a - b);
  const slotId = {};
  uniq.forEach((ms, i) => { slotId[ms] = 'ts' + (i + 1); });

  const esc = s => String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  let annId = 0;
  const timeSlots = uniq.map(ms => `        <TIME_SLOT TIME_SLOT_ID="${slotId[ms]}" TIME_VALUE="${ms}"/>`).join('\n');

  const tierXml = tiers.map(t => {
    const rows = subtitles.filter(s => s.tierId === t.id).map(s => {
      const a1 = slotId[Math.round(s.start * 1000)];
      const a2 = slotId[Math.round(s.end * 1000)];
      return `        <ANNOTATION>
            <ALIGNABLE_ANNOTATION ANNOTATION_ID="a${++annId}" TIME_SLOT_REF1="${a1}" TIME_SLOT_REF2="${a2}">
                <ANNOTATION_VALUE>${esc(s.text)}</ANNOTATION_VALUE>
            </ALIGNABLE_ANNOTATION>
        </ANNOTATION>`;
    }).join('\n');
    return `    <TIER LINGUISTIC_TYPE_REF="default-lt" TIER_ID="${esc(t.name)}">
${rows}
    </TIER>`;
  }).join('\n');

  return `<?xml version="1.0" encoding="UTF-8"?>
<ANNOTATION_DOCUMENT AUTHOR="" DATE="2026-06-07T00:00:00+00:00" FORMAT="3.0" VERSION="3.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.mpi.nl/tools/elan/EAFv3.0.xsd">
    <HEADER MEDIA_FILE="" TIME_UNITS="milliseconds">
        <MEDIA_DESCRIPTOR MEDIA_URL="${esc(videoFileName || '')}" MIME_TYPE="video/mp4"/>
    </HEADER>
    <TIME_ORDER>
${timeSlots}
    </TIME_ORDER>
${tierXml}
    <LINGUISTIC_TYPE GRAPHIC_REFERENCES="false" LINGUISTIC_TYPE_ID="default-lt" TIME_ALIGNABLE="true"/>
</ANNOTATION_DOCUMENT>`;
}
```

- [ ] **Step 2: Round-trip console self-test**

Reload, then in console:
```js
tiers = [{id:1,name:'Nederlands'}];
subtitles = [{id:1,text:'hallo',start:1,end:2.5,tierId:1}];
const xml = buildEAF('clip.mp4');
const back = parseEAF(xml);
console.log(back.tiers[0].name === 'Nederlands', back.subtitles[0].text === 'hallo',
  back.subtitles[0].start === 1, back.subtitles[0].end === 2.5, xml.includes('clip.mp4'));
```
Expected: five `true`. (Then reload to reset state.)

- [ ] **Step 3: Checkpoint** — EAF generation round-trips with the parser.

---

### Task 8: Drop-in zone for video + EAF

**Files:**
- Modify: `/web/annotation-tool/index.html`

- [ ] **Step 1: Add a drop overlay to the HTML**

Add near the top of `<body>` (before the toolbar):
```html
<div id="dropOverlay" style="position:fixed;inset:0;z-index:9999;background:rgba(20,20,30,.92);
     color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;
     text-align:center;font-family:sans-serif;">
  <h2>Drop a video (.mp4) and optionally an EAF (.eaf)</h2>
  <p>or pick files:</p>
  <input type="file" id="filePicker" accept=".mp4,video/mp4,.eaf,application/xml" multiple>
  <p id="dropHint" style="margin-top:12px;opacity:.7;font-size:13px;">
    Chrome or Edge required (File System Access API for autosave).</p>
</div>
```

- [ ] **Step 2: Add the global video-name state**

Near the globals add:
```js
let videoFileName = '';   // dropped video's filename, used in EAF + autosave name
let videoObjectUrl = '';  // object URL for mod.js
```

- [ ] **Step 3: Add file-handling logic**

Add a function that accepts a `FileList` and routes by extension:
```js
async function handleDroppedFiles(fileList) {
  let videoFile = null, eafFile = null;
  for (const f of fileList) {
    const lower = f.name.toLowerCase();
    if (lower.endsWith('.eaf')) eafFile = f;
    else if (lower.endsWith('.mp4') || f.type === 'video/mp4') videoFile = f;
  }
  if (eafFile) {
    const text = await eafFile.text();
    loadEAFString(text); // applies tiers + subtitles
  }
  if (videoFile) {
    videoFileName = videoFile.name;
    videoObjectUrl = URL.createObjectURL(videoFile);
    document.getElementById('dropOverlay').style.display = 'none';
    await start(videoObjectUrl); // existing pipeline, now takes the object URL directly
  } else if (eafFile) {
    // EAF only: hide overlay so the user can still review/edit annotations
    document.getElementById('dropOverlay').style.display = 'none';
  }
}
```

- [ ] **Step 4: Adapt `start()` to take an object URL**

Find `window.start` / `function start(file)`. It currently reads `urlParams.get('filename')` and builds the object URL itself. Change its signature to accept the already-built object URL and skip URL-param logic:
```js
window.start = async function start(objectUrl) {
  fps = 60;
  // ...existing frame-extraction setup, but use objectUrl as the videoUrl for getVideoFrames...
}
```
Search inside `start` for where `getVideoFrames({ videoUrl: ... })` is called and ensure it uses `objectUrl`. Remove the `URL.createObjectURL(file)` line inside `start` (now done by the caller) and remove the `urlParams.get('filename')` read.

- [ ] **Step 5: Wire up drop + picker events**

Add at the end of the script:
```js
['dragover','drop'].forEach(ev => document.addEventListener(ev, e => e.preventDefault()));
document.addEventListener('drop', e => {
  if (e.dataTransfer && e.dataTransfer.files.length) handleDroppedFiles(e.dataTransfer.files);
});
document.getElementById('filePicker').addEventListener('change', e => {
  if (e.target.files.length) handleDroppedFiles(e.target.files);
});
```

- [ ] **Step 6: Remove the auto-start-on-load behavior**

Search for any auto-call to `start(...)` on page load (e.g. driven by the old `?filename=` param). Delete it — start now happens only after a drop/pick. Also remove the signcollect CDN IIFE that fetched the remote video (search for `studioFilesMini`) — the video now comes from the dropped file. (Leave the Signbank *preview* CDN logic alone; that's Task 10.)

- [ ] **Step 7: Verify**

Reload. Expected: the drop overlay covers the page. Drag a local `.mp4` onto it → overlay disappears, frames decode, timeline shows one tier. Then drag an `.eaf` → tiers/annotations from the file appear as timeline rows. Dropping an `.eaf` first (before video) also loads tiers. No `getZinnen.php` requests.

- [ ] **Step 8: Checkpoint** — drop-in load works end to end.

---

### Task 9: Tier add / rename / delete UI

**Files:**
- Modify: `/web/annotation-tool/index.html`

- [ ] **Step 1: Add a tier-control container to the toolbar**

Add near the timeline controls in the HTML:
```html
<div id="tierControls" class="btn-group ml-2" role="group" aria-label="Tiers"></div>
<button id="addTierBtn" class="btn btn-sm btn-outline-secondary ml-1" type="button">+ Tier</button>
```

- [ ] **Step 2: Implement `rebuildTierControls`**

```js
function rebuildTierControls() {
  const wrap = document.getElementById('tierControls');
  if (!wrap) return;
  wrap.innerHTML = '';
  tiers.forEach(t => {
    const chip = document.createElement('span');
    chip.style.cssText = 'display:inline-flex;align-items:center;gap:4px;margin-right:6px;font-size:12px;';
    const label = document.createElement('span');
    label.textContent = t.name;
    label.title = 'Double-click to rename';
    label.style.cursor = 'pointer';
    label.ondblclick = () => {
      const name = prompt('Rename tier', t.name);
      if (name && name.trim()) { t.name = name.trim(); rebuildTierControls(); renderAll(); }
    };
    const del = document.createElement('button');
    del.textContent = '×';
    del.className = 'btn btn-sm btn-outline-danger';
    del.style.cssText = 'padding:0 5px;line-height:1;';
    del.onclick = () => removeTier(t.id);
    chip.appendChild(label); chip.appendChild(del);
    wrap.appendChild(chip);
  });
}
```

- [ ] **Step 3: Implement add/remove**

```js
function addTier() {
  tiers.push({ id: ++tierUid, name: 'Tier ' + (tiers.length + 1) });
  rebuildTierControls(); renderAll(); uploadSubtitles();
}
function removeTier(id) {
  if (tiers.length <= 1) { alert('At least one tier is required.'); return; }
  if (!confirm('Delete this tier and its annotations?')) return;
  subtitles = subtitles.filter(s => s.tierId !== id);
  tiers = tiers.filter(t => t.id !== id);
  rebuildTierControls(); renderAll(); uploadSubtitles();
}
```

- [ ] **Step 4: Wire the add button and initial render**

```js
document.getElementById('addTierBtn').addEventListener('click', addTier);
rebuildTierControls(); // initial paint
```

- [ ] **Step 5: Verify**

Reload. Expected: toolbar shows "Tier 1" chip and a "+ Tier" button. Click "+ Tier" → a second row appears on the timeline. Add a subtitle, drag it to row 2 → its tier changes. Double-click a chip → rename works and the row label updates. Delete a tier → its row and annotations are removed (can't delete the last one).

- [ ] **Step 6: Checkpoint** — tier management UI complete.

---

### Task 10: Autosave via File System Access API + IndexedDB handle

**Files:**
- Modify: `/web/annotation-tool/index.html`

- [ ] **Step 1: Add a tiny IndexedDB helper for the directory handle**

```js
const HANDLE_DB = 'annotationTool', HANDLE_STORE = 'handles', HANDLE_KEY = 'workdir';
function idbOpen() {
  return new Promise((res, rej) => {
    const r = indexedDB.open(HANDLE_DB, 1);
    r.onupgradeneeded = () => r.result.createObjectStore(HANDLE_STORE);
    r.onsuccess = () => res(r.result);
    r.onerror = () => rej(r.error);
  });
}
async function idbSet(key, val) {
  const db = await idbOpen();
  return new Promise((res, rej) => {
    const tx = db.transaction(HANDLE_STORE, 'readwrite');
    tx.objectStore(HANDLE_STORE).put(val, key);
    tx.oncomplete = () => res(); tx.onerror = () => rej(tx.error);
  });
}
async function idbGet(key) {
  const db = await idbOpen();
  return new Promise((res, rej) => {
    const tx = db.transaction(HANDLE_STORE, 'readonly');
    const rq = tx.objectStore(HANDLE_STORE).get(key);
    rq.onsuccess = () => res(rq.result); rq.onerror = () => rej(rq.error);
  });
}
```

- [ ] **Step 2: Add the working-directory handle state + getter**

```js
let workDirHandle = null;

async function ensureWorkDir() {
  if (workDirHandle) {
    if (await verifyPermission(workDirHandle)) return workDirHandle;
  }
  const cached = await idbGet(HANDLE_KEY);
  if (cached && await verifyPermission(cached)) { workDirHandle = cached; return workDirHandle; }
  if (!window.showDirectoryPicker) {
    showToast('Autosave needs Chrome/Edge (File System Access API).', 'error', 5000);
    return null;
  }
  workDirHandle = await window.showDirectoryPicker({ id: 'annotation-temp', mode: 'readwrite' });
  await idbSet(HANDLE_KEY, workDirHandle);
  return workDirHandle;
}

async function verifyPermission(handle) {
  const opts = { mode: 'readwrite' };
  if ((await handle.queryPermission(opts)) === 'granted') return true;
  if ((await handle.requestPermission(opts)) === 'granted') return true;
  return false;
}
```

- [ ] **Step 3: Rewrite `performUpload` to write the EAF to the folder**

Replace the body of `performUpload` (the old PHP POST) with:
```js
async function performUpload() {
  if (!subtitles.length && tiers.length <= 1) { hasUnsavedChanges = false; return; }
  try {
    const dir = await ensureWorkDir();
    if (!dir) { document.getElementById('saveSubtitlesBtn').textContent = 'Autosave: pick folder'; return; }
    const base = (videoFileName || 'annotation').replace(/\.[^.]+$/, '').replace(/\s+/g, '_');
    const fileHandle = await dir.getFileHandle(base + '.eaf', { create: true });
    const writable = await fileHandle.createWritable();
    await writable.write(buildEAF(videoFileName));
    await writable.close();
    hasUnsavedChanges = false;
    document.getElementById('saveSubtitlesBtn').textContent = 'Autosave Enabled';
    showToast('Saved ' + base + '.eaf', 'success');
  } catch (err) {
    console.error('Autosave error', err);
    document.getElementById('saveSubtitlesBtn').textContent = 'Save Error!';
    showToast('Autosave failed: ' + err.message, 'error', 5000);
  }
}
```
Keep the existing debounced `uploadSubtitles()` wrapper (the 1-second `setTimeout(performUpload, 1000)`) unchanged.

- [ ] **Step 4: Verify**

Reload, drop a video, add a couple of subtitles and type text. Within ~1s the browser prompts to pick a folder (choose `/web/annotation-tool/temp`). Expected: toast "Saved <name>.eaf"; the file appears in `temp/`. Open it:
```bash
cat /web/annotation-tool/temp/*.eaf
```
Expected: valid EAF XML with your tiers and annotation values. Edit more text → file updates without re-prompting for the folder.

- [ ] **Step 5: Checkpoint** — autosave writes EAF to the chosen folder.

---

### Task 11: Session restore on reload

**Files:**
- Modify: `/web/annotation-tool/index.html`

- [ ] **Step 1: Add a restore attempt on load**

Add after the drop/picker wiring:
```js
async function tryRestoreSession() {
  try {
    const cached = await idbGet(HANDLE_KEY);
    if (!cached) return;
    if (!(await verifyPermission(cached))) return; // user must re-grant; skip silently
    workDirHandle = cached;
    // Find the most recent .eaf in the folder
    let latest = null;
    for await (const [name, handle] of cached.entries()) {
      if (handle.kind === 'file' && name.toLowerCase().endsWith('.eaf')) {
        const file = await handle.getFile();
        if (!latest || file.lastModified > latest.lastModified) latest = { name, file };
      }
    }
    if (!latest) return;
    if (!confirm('Restore last session from ' + latest.name + '?')) return;
    loadEAFString(await latest.file.text());

    // If a matching video sits in the same folder, offer to load it
    const base = latest.name.replace(/\.eaf$/i, '');
    for await (const [name, handle] of cached.entries()) {
      if (handle.kind === 'file' && name.toLowerCase().startsWith(base.toLowerCase()) &&
          name.toLowerCase().endsWith('.mp4')) {
        const vf = await handle.getFile();
        videoFileName = vf.name;
        videoObjectUrl = URL.createObjectURL(vf);
        document.getElementById('dropOverlay').style.display = 'none';
        await start(videoObjectUrl);
        break;
      }
    }
  } catch (err) { console.warn('Restore skipped:', err); }
}
tryRestoreSession();
```

- [ ] **Step 2: Verify**

After Task 10's autosave created an EAF, reload the page. Expected: a confirm dialog "Restore last session from <name>.eaf?". Accept → tiers/annotations reappear. (Video reloads only if an `.mp4` with the same base name is in the folder; otherwise re-drop it.) Decline → fresh drop overlay.

- [ ] **Step 3: Checkpoint** — session restore works.

---

### Task 12: Graceful degradation for signcollect features

**Files:**
- Modify: `/web/annotation-tool/index.html`

- [ ] **Step 1: Guard the handshape smart-search fetch**

Find the `fetch('getHandshapes.php', ...)` call. Change the URL to the absolute signcollect endpoint and wrap in try/catch so failure is non-fatal:
```js
try {
  const response = await fetch('https://signcollect.nl/getHandshapes.php', { method: 'POST', body: formData });
  // ...existing handling...
} catch (err) {
  showToast('Smart search unavailable (offline).', 'error', 3000);
  console.warn('Handshape search failed:', err);
}
```

- [ ] **Step 2: Confirm Signbank preview URLs are absolute**

Search for `signcollect.nl` in the Signbank preview code. Ensure any gloss-video URL is already absolute (`https://signcollect.nl/...`). The gloss glossary itself reads from the bundled local `glosses_transformed.json` (already fixed in Task 1), so gloss lookup works offline; only the preview video and smart-search need the network.

- [ ] **Step 3: Verify**

Reload (offline or online). Expected: gloss dropdown search works from bundled JSON. With no network, smart-search shows the "unavailable" toast instead of throwing; the rest of the app keeps working. Online, smart-search and Signbank preview still function against signcollect.nl.

- [ ] **Step 4: Checkpoint** — optional signcollect features degrade gracefully.

---

### Task 13: Final pass — cleanup and full manual run-through

**Files:**
- Modify: `/web/annotation-tool/index.html`

- [ ] **Step 1: Grep for dead server references**

```bash
grep -n -i "getZinnen.php\|fetchSubtitles\|verifySubtitles\|sendBeacon\|studioFilesMini\|userProtect\|statusVideo\|statusNederlands\|currentSentenceRowId" /web/annotation-tool/index.html
```
Expected: only `currentSentenceRowId = null;` (set in Task 3) may remain; everything else should be gone. Remove any stragglers.

- [ ] **Step 2: Full workflow run-through**

In Chrome at `http://localhost:8777/`:
1. Drop a video → frames load, one tier row.
2. Add 2 tiers, rename them, add subtitles on each, edit text, drag between rows.
3. Confirm autosave writes the `.eaf` to `temp/` (toast appears).
4. Drop an existing multi-tier `.eaf` → rows match the file's tier count.
5. Reload → restore prompt → state returns.
6. Verify the saved `.eaf` opens in ELAN (or re-parses cleanly via `parseEAF`).

- [ ] **Step 3: Verify the saved EAF re-parses**

```bash
cat /web/annotation-tool/temp/*.eaf
```
Then in the browser console:
```js
fetch('temp/<your-file>.eaf').then(r=>r.text()).then(t=>console.log(parseEAF(t)));
```
Expected: tiers and subtitles match what you edited.

- [ ] **Step 4: Checkpoint** — standalone tool complete.

---

## Self-Review Notes

- **Spec coverage:** drop-in video+EAF (Task 8), no PHP (Tasks 2–3, 8, 13), dynamic N tiers → N timelines (Tasks 4–5, 9, parse in 6), client-side EAF parse/build (6–7), autosave to chosen folder via File System Access API (10) with restore (11), status removed (2), gloss bundled + signcollect features kept/graceful (1, 12). All spec sections map to tasks.
- **Type consistency:** subtitle objects use `{id,text,start,end,tierId}` everywhere (Tasks 4,6,7,8); tiers use `{id,name,visible?}` (Tasks 4,6,9). `tierId` (not `tier`) is consistent across parse/build/render/drag. `videoFileName` used in 8/10/11. `ensureWorkDir`/`verifyPermission`/`idbGet`/`idbSet`/`loadEAFString`/`rebuildTierControls`/`buildEAF`/`parseEAF` names are consistent across tasks.
- **Manual-verify rationale:** no test framework exists; pure functions get console self-tests (Tasks 6,7), the rest are browser checks.
