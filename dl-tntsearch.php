<?php

/**
 * Plugin Name: TNTSearch for WordPress
 * Description: Replaces the native WordPress search with TNTSearch, providing faster, more accurate and relevant results.
 * Version: 0.0.3
 * Author: Daniel Lúcia
 * Author URI: http://www.daniellucia.es
 * textdomain: dl-tntsearch
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

/*
Copyright (C) 2025  Daniel Lucia (https://daniellucia.es)

Este programa es software libre: puedes redistribuirlo y/o modificarlo
bajo los términos de la Licencia Pública General GNU publicada por
la Free Software Foundation, ya sea la versión 2 de la Licencia,
o (a tu elección) cualquier versión posterior.

Este programa se distribuye con la esperanza de que sea útil,
pero SIN NINGUNA GARANTÍA; ni siquiera la garantía implícita de
COMERCIABILIDAD o IDONEIDAD PARA UN PROPÓSITO PARTICULAR.
Consulta la Licencia Pública General GNU para más detalles.

Deberías haber recibido una copia de la Licencia Pública General GNU
junto con este programa. En caso contrario, consulta <https://www.gnu.org/licenses/gpl-2.0.html>.
*/

use DL\TNTSearch\Plugin;

defined('ABSPATH') || exit;

require_once __DIR__ . '/vendor/autoload.php';

define('DL_TNTSEARCH_VERSION', '0.0.3');
define('DL_TNTSEARCH_FILE', __FILE__);
define('DL_TNTSEARCH_PATH', plugin_dir_path(__FILE__));
define('DL_TNTSEARCH_STORAGE_PATH', plugin_dir_path(__FILE__) . 'storage/');
define('DL_TNTSEARCH_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {

    load_plugin_textdomain('dl-tntsearch', false, dirname(plugin_basename(__FILE__)) . '/languages');

    $plugin = new Plugin();
    $plugin->init();
});
