<?php

/**
 * Plugin Name: TNTSearch for WordPress
 * Description: Replaces the native WordPress search with TNTSearch, providing faster, more accurate and relevant results.
 * Version: 0.0.2
 * Author: Daniel Lúcia
 * Author URI: http://www.daniellucia.es
 * textdomain: dl-tntsearch
 */

use DL\TNTSearch\Plugin;

defined('ABSPATH') || exit;

require_once __DIR__ . '/vendor/autoload.php';

define('DL_TNTSEARCH_VERSION', '0.0.2');
define('DL_TNTSEARCH_FILE', __FILE__);
define('DL_TNTSEARCH_PATH', plugin_dir_path(__FILE__));
define('DL_TNTSEARCH_STORAGE_PATH', plugin_dir_path(__FILE__) . 'storage/');
define('DL_TNTSEARCH_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {

    load_plugin_textdomain('dl-tntsearch', false, dirname(plugin_basename(__FILE__)) . '/languages');

    $plugin = new Plugin();
    $plugin->init();
});
