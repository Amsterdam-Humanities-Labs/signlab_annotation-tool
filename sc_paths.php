<?php
/**
 * signcollect-lib's install-root resolver, reached from a repository that
 * may or may not be deployed next to the library.
 *
 * VENDORED FILE - byte-identical in every consumer repository, and identical
 * to consumer/sc_paths.php in signlab_signcollect-lib, which is the original.
 * tests/path-test.sh checksums the deployed copies against each other. Do not
 * edit one copy; edit the library's and re-copy.
 *
 *   require_once __DIR__ . '/sc_paths.php';   // or '/../sc_paths.php'
 *   $dir = sc_dir('media_raw');
 *
 * Two jobs, in order:
 *
 *   1. find the real resolver. /web/lib is the deployed location; ../lib and
 *      ../../lib cover a consumer that sits one or two levels below the root,
 *      including a checkout exercised outside /web. Same three-step search
 *      hh/db_config.php and studio_beta/db.php already use for credentials.
 *
 *   2. failing that, define the same four functions here, from the compiled
 *      default. Several of these repositories also deploy to production,
 *      which has no /web/lib at all. There, the honest answer is the one the
 *      code gave before this migration: /web, hardcoded. A missing library
 *      must not turn a path lookup into a 500 - the path was never in doubt.
 *
 * The fallback is deliberately the smaller half of the API: no env file, no
 * named locations beyond the four that matter, no SC_WEB_ROOT from anywhere
 * but the process environment. A host that wants to move the root installs
 * the library; a host that has not moved it needs none of that machinery.
 */

if (!function_exists('sc_path')) {
    // __DIR__/lib first, for a consumer sitting AT the docroot root: there
    // ../lib is the root's parent, outside the deployment entirely. It costs
    // nothing for the deeper consumers - /web/zin/lib does not exist, so they
    // fall through to ../lib as before.
    foreach ([__DIR__ . '/lib/paths.php',
              __DIR__ . '/../lib/paths.php',
              __DIR__ . '/../../lib/paths.php',
              '/web/lib/paths.php'] as $sc_paths_candidate) {
        if (is_file($sc_paths_candidate)) {
            require_once $sc_paths_candidate;
            break;
        }
    }
    unset($sc_paths_candidate);
}

if (!function_exists('sc_path')) {

    function sc_locations(): array
    {
        return [
            'media'      => 'gebarenoverleg_media',
            'media_raw'  => 'gebarenoverleg_media/studioFilesMini/raw',
            'media_post' => 'gebarenoverleg_media/studioFilesMini/post',
            'media_fbx'  => 'gebarenoverleg_media/fbx',
        ];
    }

    function sc_root(): string
    {
        static $root = null;
        if ($root !== null) {
            return $root;
        }
        $candidate = '';
        if (defined('SC_WEB_ROOT') && SC_WEB_ROOT !== '') {
            $candidate = (string) SC_WEB_ROOT;
        }
        if ($candidate === '') {
            $fromEnv = getenv('SC_WEB_ROOT');
            if (is_string($fromEnv) && $fromEnv !== '') {
                $candidate = $fromEnv;
            }
        }
        if ($candidate === '' || $candidate[0] !== '/') {
            $candidate = '/web';
        }
        return $root = ($candidate === '/' ? '' : rtrim($candidate, '/'));
    }

    function sc_path(string ...$parts): string
    {
        if ($parts) {
            $first = $parts[0];
            if (strpos($first, '/') === false && strpos($first, '.') === false) {
                $locations = sc_locations();
                if (isset($locations[$first])) {
                    $parts[0] = $locations[$first];
                }
            }
        }
        $path = sc_root();
        foreach ($parts as $part) {
            $part = trim($part, '/');
            if ($part !== '' && $part !== '.') {
                $path .= '/' . $part;
            }
        }
        return $path;
    }

    function sc_dir(string ...$parts): string
    {
        return sc_path(...$parts) . '/';
    }

    function sc_url(string $diskPath): string
    {
        $root = sc_root();
        if ($root === '') {
            return $diskPath;
        }
        if (strpos($diskPath, $root . '/') === 0) {
            return substr($diskPath, strlen($root));
        }
        return $diskPath === $root ? '/' : '';
    }

}
