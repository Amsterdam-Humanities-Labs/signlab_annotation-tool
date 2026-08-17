<?php
// Cluster-correction persistence endpoint.
// GET            -> returns status.json ({ "<video>": {"status": "...", "ts": <unix>}, ... })
// POST {video, status?, eaf?} -> updates status.json and/or writes edit/eaf/<video>.eaf
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$BASE = __DIR__;                       // .../clusters/edit
$STATUS = $BASE . '/status.json';
$EAFDIR = $BASE . '/eaf';

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
  echo json_encode(read_status($STATUS));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) { http_response_code(400); echo json_encode(array('error'=>'bad json')); exit; }
  $video = safe_name(isset($body['video']) ? $body['video'] : '');
  if ($video === '') { http_response_code(400); echo json_encode(array('error'=>'no video')); exit; }

  $resp = array('ok'=>true, 'video'=>$video);

  // Save corrected EAF (kept separate from out/eaf so re-emit never overwrites it).
  if (isset($body['eaf']) && is_string($body['eaf']) && strlen($body['eaf']) > 0) {
    if (!is_dir($EAFDIR)) @mkdir($EAFDIR, 0777, true);
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
