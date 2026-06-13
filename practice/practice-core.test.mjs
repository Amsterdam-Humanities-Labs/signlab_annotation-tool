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
