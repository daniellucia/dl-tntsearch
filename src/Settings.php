<?php


namespace DL\TNTSearch;


defined('ABSPATH') || exit;

class Settings
{

    /**
     * Añade la página de configuración al menú de Ajustes
     * @return void
     * @author Daniel Lucia
     */
    public function add_settings_page()
    {
        add_options_page(
            __('Ajustes de TNTSearch', 'dl-tntsearch'),
            __('TNTSearch', 'dl-tntsearch'),
            'manage_options',
            'dl-tntsearch-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Registra los ajustes
     * @return void
     * @author Daniel Lucia
     */
    public function register_settings()
    {
        register_setting('dl_tntsearch_settings', 'dl_tntsearch_enabled');
        register_setting('dl_tntsearch_settings', 'dl_tntsearch_indexed_post_types');
    }

    /**
     * Renderiza la página de configuración
     * @return void
     * @author Daniel Lucia
     */
    public function render_settings_page()
    {
        $enabled = get_option('dl_tntsearch_enabled', 'no');

        $post_types = get_post_types(['public' => true, '_builtin' => false], 'objects');
        $builtin_types = get_post_types(['public' => true, '_builtin' => true], 'objects');
        $all_types = array_merge($builtin_types, $post_types);

        $indexed = get_option('dl_tntsearch_indexed_post_types', []);
        if (!is_array($indexed)) {
            $indexed = [];
        }

        if (isset($_POST['dl_tntsearch_manual_index']) && check_admin_referer('dl_tntsearch_settings-options')) {
            $engine = new Engine(DL_TNTSEARCH_STORAGE_PATH);
            $engine->create_index();
            
            wp_redirect(add_query_arg('tntsearch_indexed', '1', menu_page_url('dl-tntsearch-settings', false)));
            exit;
        }

        if (isset($_GET['tntsearch_indexed']) && $_GET['tntsearch_indexed'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The index has been generated correctly.', 'dl-tntsearch') . '</p></div>';
        }

        ?>

        <?php if (!$this->is_pdo_sqlite_enabled()): ?>
            <div class="notice notice-error">
                <p><?php esc_html_e('The PDO SQLite extension is not enabled on your server. Please enable it to use TNTSearch.', 'dl-tntsearch'); ?></p>
            </div>
        <?php endif; ?>
        
        <div class="wrap">
            <h1><?php esc_html_e('TNTSearch settings', 'dl-tntsearch'); ?></h1>
            <form method="post" action="options.php" style="display:inline;">
                <?php settings_fields('dl_tntsearch_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable TNTSearch', 'dl-tntsearch'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="dl_tntsearch_enabled" value="yes" <?php checked($enabled, 'yes'); ?> />
                                <?php esc_html_e('Activate', 'dl-tntsearch'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Custom Post Types to index', 'dl-tntsearch'); ?></th>
                        <td>
                            <?php foreach ($all_types as $type): ?>
                                <label style="display:block;">
                                    <input type="checkbox" name="dl_tntsearch_indexed_post_types[]" value="<?php echo esc_attr($type->name); ?>" <?php checked(in_array($type->name, $indexed)); ?> />
                                    <?php echo esc_html($type->labels->singular_name); ?> (<?php echo esc_html($type->name); ?>)
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save', 'dl-tntsearch')); ?>
            </form>

            <form method="post" action="">
                <h1><?php esc_html_e('Index', 'dl-tntsearch'); ?></h1>
                <p><?php esc_html_e('Click the button below to manually index your content.', 'dl-tntsearch'); ?></p>
                <?php wp_nonce_field('dl_tntsearch_settings-options'); ?>
                <input type="submit" name="dl_tntsearch_manual_index" class="button button-secondary" value="<?php esc_attr_e('Index now', 'dl-tntsearch'); ?>" />
            </form>

        </div>

    <?php
    }

    /**
     * Comprueba si la extensión PDO SQLite está habilitada
     * @return bool
     * @author Daniel Lucia
     */
    private function is_pdo_sqlite_enabled(): bool
    {
        if (!class_exists('PDO')) {
            return false;
        }

        try {
            $drivers = \PDO::getAvailableDrivers();
            return in_array('sqlite', $drivers, true);
        } catch (\Exception $e) {
            return false;
        }
    }


}
