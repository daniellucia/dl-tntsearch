<?php

namespace DL\TNTSearch;

use TeamTNT\TNTSearch\TNTSearch;

defined('ABSPATH') || exit;

class Engine
{

    private $tnt;
    private $storage;
    private $index_file;
    private $enabled;

    /**
     * Constructor de la clase
     * @param mixed $storage
     * @author Daniel Lucia
     */
    public function __construct($storage = null)
    {
        if (is_null($storage)) {
            wp_die(__('Storage path is required for Indexer.', 'dl-tntsearch'));
        }

        $this->storage = wp_normalize_path($storage);
        if (substr($this->storage, -1) !== '/') {
            $this->storage .= '/';
        }

        $this->index_file = 'data.index';
        $this->enabled = get_option('dl_tntsearch_enabled', 'no') === 'yes';

        $this->tnt = new TNTSearch;
        $this->tnt->loadConfig([
            'driver'    => 'sqlite',
            'database'  => $this->storage . 'tntsearch.sqlite',
            'storage'   => $this->storage
        ]);
    }

    /**
     * Indexa el contenido
     * @return void
     * @author Daniel Lucia
     */
    public function create_index()
    {
        //Si no esta activo, no creamos indice
        if (!$this->enabled) {
            return;
        }

        $indexed = get_option('dl_tntsearch_indexed_post_types', []);
        if (empty($indexed)) {
            return;
        }

        if (!$this->is_storage_writable()) {
            wp_die(__('The storage path is not writable. Please check the permissions.', 'dl-tntsearch'));
        }


        global $wpdb;

        $post_types_in = implode("','", array_map('esc_sql', $indexed));
        $sql = "SELECT ID, post_title, post_content, post_type
        FROM {$wpdb->posts} 
        WHERE post_status='publish' AND post_type IN ('$post_types_in');";
        $posts = $wpdb->get_results($sql, ARRAY_A);

        if (empty($posts)) {
            return;
        }

        try {

            $indexer = $this->tnt->createIndex($this->index_file);
            $indexer->setPrimaryKey('ID');
            foreach ($posts as &$post) {
                $indexer->insert($post);
            }
        } catch (\Exception $e) {
            error_log('TNTSearch Error: ' . $e->getMessage());
            error_log('Error file: ' . $e->getFile() . ' Line: ' . $e->getLine());
            echo '<div class="notice notice-error"><p>' . esc_html__('Error creating the index: ', 'dl-tntsearch') . $e->getMessage() . '</p></div>';
        }
    }

    /**
     * Realiza una búsqueda utilizando TNTSearch
     * @param mixed $query
     * @param mixed $limit
     * @return array<int|\WP_Post>
     * @author Daniel Lucia
     */
    private function search($query, $limit = 10)
    {
        $this->tnt->selectIndex($this->index_file);
        $this->tnt->fuzziness(true);
        $res = $this->tnt->search($query, $limit);

        //Filtramos por score
        $ids = $this->filter_post_by_score($res);

        if (empty($ids)) {
            return [];
        }

        $args = [
            'post__in' => $ids,
            'orderby'  => 'post__in',
            'posts_per_page' => $limit
        ];

        return get_posts($args);
    }

    /**
     * Filtra los IDs de los posts por su score, devolviendo solo aquellos con score > X
     * @param mixed $res
     * @return array
     * @author Daniel Lucia
     */
    private function filter_post_by_score($res)
    {
        $filtered_ids = [];
        foreach ($res['docScores'] as $id => $score) {
            if ($score > 1.5) {
                $filtered_ids[] = $id;
            }
        }

        if (empty($filtered_ids)) {
            return [];
        }

        return $filtered_ids;
    }

    /**
     * Reemplaza la búsqueda nativa de WordPress con TNTSearch
     * @param mixed $posts
     * @param mixed $query
     * @author Daniel Lucia
     */
    public function replace_search($posts, $query)
    {
        if (!$this->enabled) {
            return $posts; // Si no está activado, devolvemos la búsqueda original
        }

        if (!is_admin() && $query->is_main_query() && $query->is_search()) {

            $search_term = $query->get('s');

            if ($search_term) {
                $results = $this->search($search_term, $query->get('posts_per_page'));

                if (!empty($results)) {
                    return $results;
                } else {
                    return [];
                }
            }
        }

        return $posts;
    }

    /**
     * Comprueba si la ruta de almacenamiento tiene permisos de escritura
     * @return bool
     * @author Daniel Lucia
     */
    private function is_storage_writable(): bool
    {
        if (!file_exists($this->storage)) {
            wp_mkdir_p($this->storage);
        }

        return is_writable($this->storage);
    }
}
