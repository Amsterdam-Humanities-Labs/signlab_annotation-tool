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
