<?php
/**
 * Plugin Name: AIB oneshot grep fs_force (self-deleting)
 * Description: Finds which file handles ?fs_force and emits {"cleared":..}. Writes uploads/fastsan-content/grep-fsforce.log, then unlinks itself.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) return;
add_action('muplugins_loaded', static function () {
    $roots = [WP_CONTENT_DIR . '/mu-plugins', WP_CONTENT_DIR . '/plugins', WP_CONTENT_DIR . '/themes', ABSPATH];
    $hits = [];
    foreach ($roots as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        $depth = ($root === ABSPATH) ? 0 : 6;
        foreach ($it as $f) {
            if ($root === ABSPATH && $it->getDepth() > 0) continue;
            if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') continue;
            $c = @file_get_contents($f->getPathname());
            if ($c === false) continue;
            $a = strpos($c, 'fs_force') !== false; $b = strpos($c, "'cleared'") !== false || strpos($c, '"cleared"') !== false;
            if ($a || $b) $hits[] = ($a ? 'F' : '-') . ($b ? 'C' : '-') . ' ' . $f->getPathname() . ' ' . $f->getSize();
        }
    }
    @file_put_contents(WP_CONTENT_DIR . '/uploads/fastsan-content/grep-fsforce.log', '[' . gmdate('c') . "]\n" . implode("\n", $hits) . "\n");
    @unlink(__FILE__);
}, 0);
