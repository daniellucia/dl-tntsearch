<?php

namespace DL\TNTSearch;

defined('ABSPATH') || exit;

class Plugin
{

    public function init()
    {
        $engine = new Engine(DL_TNTSEARCH_STORAGE_PATH);
        add_filter('posts_pre_query', [$engine, 'replace_search'], 10, 2);

        $settings = new Settings();
        add_action('admin_menu', [$settings, 'add_settings_page']);
        add_action('admin_init', [$settings, 'register_settings']);
    }
}
