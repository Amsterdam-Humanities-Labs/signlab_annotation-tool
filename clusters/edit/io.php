<?php
// Cluster-correction persistence endpoint.
// GET            -> returns status.json ({ "<video>": {"status": "...", "ts": <unix>}, ... })
// GET ?eaf=<video> -> the corrected EAF for <video>, 404 if there is none
// POST {video, status?, eaf?} -> updates status.json and/or writes eaf/<video>.eaf
// POST needs a logged-in session; same-origin only (no CORS).
// Data lives outside the checkout - see data.php.
header('Content-Type: application/json');

require_once __DIR__ . '/data.php';

function read_status($f) {
  if (!file_exists($f)) return array();
  $j = json_decode(file_get_contents($f), true);
  return is_array($j) ? $j : array();
}
function safe_name($v) {
  // only allow the video-name charset we control (M202..., letters/digits/_-)
  return preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$v);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (isset($_GET['eaf'])) {
    $video = safe_name($_GET['eaf']);
    $f = $video === '' ? '' : ann_read_path('eaf/' . $video . '.eaf');
    if ($f === '' || !is_file($f)) { http_response_code(404); echo json_encode(array('error'=>'no corrected eaf')); exit; }
    header('Content-Type: application/xml');
    header('Cache-Control: no-store');
    readfile($f);
    exit;
  }
  echo json_encode(read_status(ann_read_path('status.json')));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  ann_require_session();
  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) { http_response_code(400); echo json_encode(array('error'=>'bad json')); exit; }
  $video = safe_name(isset($body['video']) ? $body['video'] : '');
  if ($video === '') { http_response_code(400); echo json_encode(array('error'=>'no video')); exit; }

  $DIR = ann_data_ready();
  if ($DIR === '') ann_fail_unwritable();
  $STATUS = $DIR . '/status.json';
  $EAFDIR = $DIR . '/eaf';
  $resp = array('ok'=>true, 'video'=>$video);

  // Save corrected EAF (kept separate from out/eaf so re-emit never overwrites it).
  if (isset($body['eaf']) && is_string($body['eaf']) && strlen($body['eaf']) > 0) {
    $ok = @file_put_contents($EAFDIR . '/' . $video . '.eaf', $body['eaf']) !== false;
    $resp['eaf_saved'] = $ok;
  }

  // Update status map.
  if (isset($body['status'])) {
    $st = read_status($STATUS);
    $st[$video] = array('status' => (string)$body['status'], 'ts' => time());
    // atomic-ish write
    $tmp = $STATUS . '.tmp';
    @file_put_contents($tmp, json_encode($st));
    @rename($tmp, $STATUS);
    $resp['status'] = (string)$body['status'];
  }

  echo json_encode($resp);
  exit;
}

http_response_code(405);
echo json_encode(array('error'=>'method'));
