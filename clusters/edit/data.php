<?php
// Where io.php and merge_io.php keep human corrections: OUTSIDE the checkout,
// at <webroot>/annotation_data/clusters/, so a redeploy (git reset --hard +
// clean -fd) cannot revert or delete them.
//
//   status.json            review status per video      (io.php)
//   eaf/<video>.eaf        corrected EAFs               (io.php)
//   merge_decisions.json   merge-review decisions       (merge_io.php)
//
// seed/ holds the copies that used to be tracked here. A file missing from the
// data dir is seeded from seed/ on first use; an existing one is never touched.
require_once __DIR__ . '/../../sc_paths.php';

// Writes need a logged-in user. The session verifier is signCollect-v2's
// (menu_beta/php_api/session.php, the one login_sc.php signs with). Its
// db.php is loaded here, at top level, so mysql_config's globals stay global.
$ann_session_lib = sc_path('menu_beta/php_api/session.php');
if (is_readable($ann_session_lib)) {
  require_once dirname($ann_session_lib) . '/db.php';
  require_once $ann_session_lib;
}
unset($ann_session_lib);

// 401 unless the request carries a valid session. Fails closed when the
// session library is not installed.
function ann_require_session() {
  if (!function_exists('current_session')) {
    error_log('annotation-tool: menu_beta/php_api/session.php missing - refusing write');
  } elseif (current_session() !== null) {
    return;
  }
  http_response_code(401);
  echo json_encode(array('error' => 'not logged in'));
  exit;
}

function ann_data_dir() {
  return sc_path('annotation_data', 'clusters');
}

function ann_seed_dir() {
  return __DIR__ . '/seed';
}

// Returns the data dir, seeded, or '' when it cannot be created.
function ann_data_ready() {
  $dir = ann_data_dir();
  $seed = ann_seed_dir();
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return '';
  foreach (array('status.json', 'merge_decisions.json') as $f) {
    if (!file_exists("$dir/$f") && file_exists("$seed/$f")) @copy("$seed/$f", "$dir/$f");
  }
  if (!is_dir("$dir/eaf")) {
    if (!@mkdir("$dir/eaf", 0775, true)) return '';
    foreach (glob("$seed/eaf/*.eaf") ?: array() as $src) @copy($src, "$dir/eaf/" . basename($src));
  }
  return $dir;
}

// Path to read <name> from: the data dir if it has it, else the seed copy.
function ann_read_path($name) {
  $dir = ann_data_ready();
  if ($dir !== '' && file_exists("$dir/$name")) return "$dir/$name";
  return ann_seed_dir() . '/' . $name;
}

function ann_fail_unwritable() {
  http_response_code(500);
  echo json_encode(array('error' => 'data dir not writable: ' . ann_data_dir()));
  exit;
}
