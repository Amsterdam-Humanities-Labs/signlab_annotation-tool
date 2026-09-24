<?php
// Merge-review persistence endpoint.
// GET                     -> returns merge_decisions.json ({ "<a>_<b>": {"decision":"merge|keep","ts":...}, ... })
// POST {a, b, decision}   -> records one pair decision
// POST needs a logged-in session; same-origin only (no CORS).
// Data lives outside the checkout - see data.php.
header('Content-Type: application/json');

require_once __DIR__ . '/data.php';

function read_dec($f) {
  if (!file_exists($f)) return array();
  $j = json_decode(file_get_contents($f), true);
  return is_array($j) ? $j : array();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Cache-Control: no-store');
  echo json_encode(read_dec(ann_read_path('merge_decisions.json')));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  ann_require_session();
  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) { http_response_code(400); echo json_encode(array('error'=>'bad json')); exit; }
  $a = isset($body['a']) ? (int)$body['a'] : -999999;
  $b = isset($body['b']) ? (int)$body['b'] : -999999;
  $d = isset($body['decision']) ? (string)$body['decision'] : '';
  if ($a === -999999 || $b === -999999 || ($d !== 'merge' && $d !== 'keep')) {
    http_response_code(400); echo json_encode(array('error'=>'bad pair')); exit;
  }
  $DIR = ann_data_ready();
  if ($DIR === '') ann_fail_unwritable();
  $DEC = $DIR . '/merge_decisions.json';
  // Serialize read-modify-write: without the lock, two quick decisions
  // each read the old file and the second write drops the first.
  $lock = @fopen($DEC . '.lock', 'c');
  if (!$lock || !flock($lock, LOCK_EX)) ann_fail_unwritable();
  $st = read_dec($DEC);
  $st[$a . '_' . $b] = array('decision' => $d, 'ts' => time());
  $tmp = $DEC . '.tmp';
  $ok = @file_put_contents($tmp, json_encode($st)) !== false && @rename($tmp, $DEC);
  flock($lock, LOCK_UN);
  fclose($lock);
  if (!$ok) ann_fail_unwritable();
  echo json_encode(array('ok'=>true, 'pair'=>$a.'_'.$b, 'decision'=>$d, 'total'=>count($st)));
  exit;
}

http_response_code(405);
echo json_encode(array('error'=>'method'));
