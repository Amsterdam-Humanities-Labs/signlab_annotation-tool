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

// The target gloss's own spotted score (cosine), or null if the target isn't in
// the results or carries no numeric score.
export function targetScore(targetGloss, spotted) {
  const t = normalizeGloss(targetGloss);
  for (const s of spotted) {
    if (normalizeGloss(s && s.gloss != null ? s.gloss : s) === t) {
      return s && typeof s.score === 'number' ? s.score : null;
    }
  }
  return null;
}

// A target counts as matched if it lands in the top-k OR the spotter scored the
// target itself above the threshold — a confident hit just outside the top-k
// still passes. (Threshold compares the TARGET's score, not the top-1 gloss's.)
export function isMatch(targetGloss, spotted, k = 3, scoreThreshold = 0.7) {
  if (isTopKMatch(targetGloss, spotted, k)) return true;
  const sc = targetScore(targetGloss, spotted);
  return sc != null && sc > scoreThreshold;
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
