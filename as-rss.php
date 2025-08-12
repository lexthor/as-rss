<?php
/**
 * Plugin Name: RSS Before Footer (AS)
 * Description: Minimal scaffold: metabox to save RSS URLs + options. No frontend output yet.
 * Version: 0.1.0
 * Author: Alexandru S.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('fetch_feed')) {
    require_once ABSPATH . WPINC . '/feed.php';
}

/**
 * Main plugin class
 */
class ASRSS_Plugin {
    
    // Meta keys for posts
    const POST_URLS = '_asrss_urls';
    const POST_LIMIT = '_asrss_limit';
    const POST_ORDER = '_asrss_order';
    const POST_SHOW_IMG = '_asrss_show_img';
    const POST_SHOW_SRC = '_asrss_show_src';
    const POST_TTL = '_asrss_ttl';
    
    // Meta keys for terms
    const TERM_URLS = 'asrss_urls';
    const TERM_LIMIT = 'asrss_limit';
    const TERM_ORDER = 'asrss_order';
    const TERM_TTL = 'asrss_ttl';
    const TERM_SHOW_IMG = 'asrss_show_img';
    const TERM_SHOW_SRC = 'asrss_show_src';
    
    // Defaults
    const DEFAULT_LIMIT = 5;
    const DEFAULT_ORDER = 'desc';
    const DEFAULT_TTL = 600;
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_post_meta']);
        add_action('admin_init', [$this, 'register_tax_fields']);
        add_action('created_term', [$this, 'save_term_meta'], 10, 3);
        add_action('edited_term', [$this, 'save_term_meta'], 10, 3);
        add_filter('the_content', [$this, 'append_feed'], 99);
        add_action('loop_end', [$this, 'render_term_after_loop']);
        add_action('admin_post_asrss_refresh', [$this, 'handle_refresh']);
        add_action('admin_post_asrss_refresh_term', [$this, 'handle_refresh_term']);
        add_action('init', [$this, 'init_woocommerce_hooks']);
    }
    
    /**
     * Get default configuration
     */
    private function get_defaults() {
        return [
            'limit' => self::DEFAULT_LIMIT,
            'order' => self::DEFAULT_ORDER,
            'ttl' => self::DEFAULT_TTL,
            'show_img' => true,
            'show_src' => true
        ];
    }
    
    /**
     * Sanitize and validate URLs from textarea input
     */
    private function sanitize_urls($urls_raw) {
        $lines = preg_split('/\r?\n/', $urls_raw);
        $urls = [];
        foreach ($lines as $line) {
            $url = trim($line);
            if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                $urls[] = esc_url_raw($url);
            }
        }
        return $urls;
    }
    
    /**
     * Get post configuration
     */
    private function get_post_config($post_id) {
        $defaults = $this->get_defaults();
        return [
            'urls' => (array) get_post_meta($post_id, self::POST_URLS, true),
            'limit' => (int) (get_post_meta($post_id, self::POST_LIMIT, true) ?: $defaults['limit']),
            'order' => (string) (get_post_meta($post_id, self::POST_ORDER, true) ?: $defaults['order']),
            'ttl' => (int) (get_post_meta($post_id, self::POST_TTL, true) ?: $defaults['ttl']),
            'show_img' => (get_post_meta($post_id, self::POST_SHOW_IMG, true) !== '0'),
            'show_src' => (get_post_meta($post_id, self::POST_SHOW_SRC, true) !== '0')
        ];
    }
    
    /**
     * Get term configuration
     */
    private function get_term_config($term_id) {
        $defaults = $this->get_defaults();
        return [
            'urls' => (array) get_term_meta($term_id, self::TERM_URLS, true),
            'limit' => (int) (get_term_meta($term_id, self::TERM_LIMIT, true) ?: $defaults['limit']),
            'order' => (string) (get_term_meta($term_id, self::TERM_ORDER, true) ?: $defaults['order']),
            'ttl' => (int) (get_term_meta($term_id, self::TERM_TTL, true) ?: $defaults['ttl']),
            'show_img' => ((int) get_term_meta($term_id, self::TERM_SHOW_IMG, true)) === 1,
            'show_src' => ((int) get_term_meta($term_id, self::TERM_SHOW_SRC, true)) === 1
        ];
    }


    /**
     * Register metabox on public post types
     */
    public function add_meta_boxes() {
        $post_types = get_post_types(['public' => true], 'names');
        foreach ($post_types as $post_type) {
            add_meta_box('asrss_box', 'RSS Feeds (before footer)', [$this, 'render_metabox'], $post_type, 'normal', 'default');
        }
    }

    /**
     * Render metabox for posts
     */
    public function render_metabox($post) {
        if (!current_user_can('edit_post', $post->ID)) return;
        wp_nonce_field('asrss_save', 'asrss_nonce');

        $config = $this->get_post_config($post->ID);
        $this->render_form_fields($config, 'post', $post->ID);
    }
    
    /**
     * Render common form fields
     */
    private function render_form_fields($config, $context = 'post', $id = 0) {
        ?>
        <p><label><strong>Feed URLs (one per line)</strong></label><br>
            <textarea name="asrss_urls" rows="4" style="width:100%" placeholder="https://example.com/feed/rss"><?php
                echo esc_textarea(implode("\n", $config['urls']));
            ?></textarea>
        </p>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
            <p><label>Max items<br><input type="number" name="asrss_limit" min="1" value="<?php echo esc_attr($config['limit']); ?>" style="width:120px"></label></p>
            <p><label>Order<br>
                <select name="asrss_order">
                    <option value="desc" <?php selected($config['order'], 'desc'); ?>>Newest first</option>
                    <option value="asc"  <?php selected($config['order'], 'asc');  ?>>Oldest first</option>
                </select></label>
            </p>
            <p><label>Cache TTL (sec)<br><input type="number" name="asrss_ttl" min="0" value="<?php echo esc_attr($config['ttl']); ?>" style="width:140px"></label></p>
        </div>
        <p>
            <label><input type="checkbox" name="asrss_show_img" value="1" <?php checked($config['show_img']); ?>> Show image</label><br>
            <label><input type="checkbox" name="asrss_show_src" value="1" <?php checked($config['show_src']); ?>> Show source</label>
        </p>
        <?php
        
        if ($context === 'post' && $id) {
            $refresh_url = wp_nonce_url(
                admin_url('admin-post.php?action=asrss_refresh&post_id=' . $id),
                'asrss_refresh_' . $id
            );
            echo '<p><a class="button" href="' . esc_url($refresh_url) . '">Refresh cache now</a></p>';
        } elseif ($context === 'term' && $id) {
            $refresh_url = wp_nonce_url(
                admin_url('admin-post.php?action=asrss_refresh_term&term_id=' . $id),
                'asrss_refresh_term_' . $id
            );
            echo '<p><a class="button" href="' . esc_url($refresh_url) . '">Refresh cache now</a></p>';
        }
    }

    /**
     * Register taxonomy fields
     */
    public function register_tax_fields() {
        $taxonomies = get_taxonomies(['public' => true], 'names');
        foreach ($taxonomies as $taxonomy) {
            add_action($taxonomy . '_add_form_fields', [$this, 'render_term_add_fields']);
            add_action($taxonomy . '_edit_form_fields', [$this, 'render_term_edit_fields']);
        }
    }
    
    /**
     * Render term add form fields
     */
    public function render_term_add_fields($taxonomy) {
        wp_nonce_field('asrss_save_term', 'asrss_term_nonce');
        $defaults = $this->get_defaults();
        ?>
        <div class="form-field">
            <label for="asrss_urls"><?php esc_html_e('RSS Feeds (one per line)', 'asrss'); ?></label>
            <textarea name="asrss_urls" id="asrss_urls" rows="4" style="width:100%" placeholder="https://example.com/feed/rss"></textarea>
            <p class="description"><?php esc_html_e('Items will render before the footer on this archive.', 'asrss'); ?></p>
        </div>
        <div class="form-field">
            <label><?php esc_html_e('Options', 'asrss'); ?></label><br>
            <label><?php esc_html_e('Max items', 'asrss'); ?> <input type="number" name="asrss_limit" min="1" value="<?php echo esc_attr($defaults['limit']); ?>" style="width:120px"></label>
            &nbsp; <label><?php esc_html_e('Order', 'asrss'); ?>
                <select name="asrss_order">
                    <option value="desc"><?php esc_html_e('Newest first','asrss'); ?></option>
                    <option value="asc"><?php esc_html_e('Oldest first','asrss'); ?></option>
                </select>
            </label>
            &nbsp; <label><?php esc_html_e('Cache TTL (sec)','asrss'); ?> <input type="number" name="asrss_ttl" min="0" value="<?php echo esc_attr($defaults['ttl']); ?>" style="width:140px"></label>
            <p>
                <label><input type="checkbox" name="asrss_show_img" value="1" checked> <?php esc_html_e('Show image','asrss'); ?></label>
                &nbsp; <label><input type="checkbox" name="asrss_show_src" value="1" checked> <?php esc_html_e('Show source','asrss'); ?></label>
            </p>
        </div>
        <?php
    }
    
    /**
     * Render term edit form fields
     */
    public function render_term_edit_fields($term) {
        $config = $this->get_term_config($term->term_id);
        wp_nonce_field('asrss_save_term', 'asrss_term_nonce');
        
        $refresh_url = wp_nonce_url(
            admin_url('admin-post.php?action=asrss_refresh_term&term_id=' . $term->term_id),
            'asrss_refresh_term_' . $term->term_id
        );
        ?>
        <tr class="form-field">
            <th scope="row"><label for="asrss_urls"><?php esc_html_e('RSS Feeds (one per line)', 'asrss'); ?></label></th>
            <td>
                <textarea name="asrss_urls" id="asrss_urls" rows="4" style="width:100%"><?php echo esc_textarea(implode("\n", $config['urls'])); ?></textarea>
                <p class="description"><?php esc_html_e('Items will render before the footer on this archive.', 'asrss'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label><?php esc_html_e('Options','asrss'); ?></label></th>
            <td>
                <label><?php esc_html_e('Max items','asrss'); ?> <input type="number" name="asrss_limit" min="1" value="<?php echo esc_attr($config['limit']); ?>" style="width:120px"></label>
                &nbsp; <label><?php esc_html_e('Order','asrss'); ?>
                    <select name="asrss_order">
                        <option value="desc" <?php selected($config['order'], 'desc'); ?>><?php esc_html_e('Newest first','asrss'); ?></option>
                        <option value="asc"  <?php selected($config['order'], 'asc');  ?>><?php esc_html_e('Oldest first','asrss'); ?></option>
                    </select>
                </label>
                &nbsp; <label><?php esc_html_e('Cache TTL (sec)','asrss'); ?> <input type="number" name="asrss_ttl" min="0" value="<?php echo esc_attr($config['ttl']); ?>" style="width:140px"></label>
                <p>
                    <label><input type="checkbox" name="asrss_show_img" value="1" <?php checked($config['show_img']); ?>> <?php esc_html_e('Show image','asrss'); ?></label>
                    &nbsp; <label><input type="checkbox" name="asrss_show_src" value="1" <?php checked($config['show_src']); ?>> <?php esc_html_e('Show source','asrss'); ?></label>
                </p>
                <p><a class="button" href="<?php echo esc_url($refresh_url); ?>"><?php esc_html_e('Refresh cache now','asrss'); ?></a></p>
            </td>
        </tr>
        <?php
    }

    /**
     * Save term meta on create/edit
     */
    public function save_term_meta($term_id, $tt_id = 0, $taxonomy = '') {
        if (!isset($_POST['asrss_term_nonce']) || !wp_verify_nonce($_POST['asrss_term_nonce'], 'asrss_save_term')) return;

        $urls = $this->sanitize_urls(isset($_POST['asrss_urls']) ? $_POST['asrss_urls'] : '');
        $defaults = $this->get_defaults();
        
        $limit = isset($_POST['asrss_limit']) ? max(1, intval($_POST['asrss_limit'])) : $defaults['limit'];
        $order = (isset($_POST['asrss_order']) && in_array($_POST['asrss_order'], ['asc','desc'], true)) ? $_POST['asrss_order'] : $defaults['order'];
        $ttl = isset($_POST['asrss_ttl']) ? max(0, intval($_POST['asrss_ttl'])) : $defaults['ttl'];
        $show_img = !empty($_POST['asrss_show_img']) ? 1 : 0;
        $show_src = !empty($_POST['asrss_show_src']) ? 1 : 0;

        update_term_meta($term_id, self::TERM_URLS, $urls);
        update_term_meta($term_id, self::TERM_LIMIT, $limit);
        update_term_meta($term_id, self::TERM_ORDER, $order);
        update_term_meta($term_id, self::TERM_TTL, $ttl);
        update_term_meta($term_id, self::TERM_SHOW_IMG, $show_img);
        update_term_meta($term_id, self::TERM_SHOW_SRC, $show_src);

        delete_transient($this->get_term_cache_key($term_id));
    }


    /**
     * Save post meta safely
     */
    public function save_post_meta($post_id) {
        if (!isset($_POST['asrss_nonce']) || !wp_verify_nonce($_POST['asrss_nonce'], 'asrss_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $urls = $this->sanitize_urls(isset($_POST['asrss_urls']) ? $_POST['asrss_urls'] : '');
        $defaults = $this->get_defaults();
        
        $limit = isset($_POST['asrss_limit']) ? max(1, intval($_POST['asrss_limit'])) : $defaults['limit'];
        $order = (isset($_POST['asrss_order']) && in_array($_POST['asrss_order'], ['asc','desc'], true)) ? $_POST['asrss_order'] : $defaults['order'];
        $ttl = isset($_POST['asrss_ttl']) ? max(0, intval($_POST['asrss_ttl'])) : $defaults['ttl'];
        $show_img = !empty($_POST['asrss_show_img']) ? '1' : '0';
        $show_src = !empty($_POST['asrss_show_src']) ? '1' : '0';

        update_post_meta($post_id, self::POST_URLS, $urls);
        update_post_meta($post_id, self::POST_LIMIT, $limit);
        update_post_meta($post_id, self::POST_ORDER, $order);
        update_post_meta($post_id, self::POST_TTL, $ttl);
        update_post_meta($post_id, self::POST_SHOW_IMG, $show_img);
        update_post_meta($post_id, self::POST_SHOW_SRC, $show_src);

        delete_transient($this->get_post_cache_key($post_id));
    }

    /**
     * Append RSS feed to post content
     */
    public function append_feed($content) {
        if (is_admin() || is_feed()) return $content;
        if (!is_singular() || !in_the_loop() || !is_main_query()) return $content;

        // Skip WooCommerce products to avoid polluting short description
        if ((function_exists('is_product') && is_product()) || get_post_type() === 'product') {
            return $content;
        }

        $post_id = get_queried_object_id();
        $config = $this->get_post_config($post_id);
        
        if (empty($config['urls'])) return $content;

        $items = $this->get_cached_items('post', $post_id, $config);
        
        if (is_wp_error($items)) {
            $html = '<section class="asrss" style="margin:2rem 0;padding-top:1rem;border-top:1px solid #eee"><em>'
                  . esc_html($items->get_error_message()) . '</em></section>';
            return $content . $html;
        }
        
        if (empty($items)) return $content;

        return $content . $this->render_html($items, $config['show_img'], $config['show_src']);
    }

    /**
     * Initialize WooCommerce hooks
     */
    public function init_woocommerce_hooks() {
        if (class_exists('WooCommerce')) {
            add_action('woocommerce_after_single_product', [$this, 'wc_single_render'], 20);
            add_action('woocommerce_after_shop_loop', [$this, 'wc_archive_render'], 20);
        }
    }

    /**
     * Render RSS on single WooCommerce product
     */
    public function wc_single_render() {
        if (!is_product()) return;
        
        $post_id = get_queried_object_id();
        $config = $this->get_post_config($post_id);
        
        if (empty($config['urls'])) return;

        $items = $this->get_cached_items('post', $post_id, $config);
        if (empty($items) || is_wp_error($items)) return;

        echo $this->render_html($items, $config['show_img'], $config['show_src']);
    }

    /**
     * Render RSS on WooCommerce product archives
     */
    public function wc_archive_render() {
        if (!is_product_category() && !is_product_tag()) return;
        
        $term = get_queried_object();
        if (!$term || empty($term->term_id)) return;

        $config = $this->get_term_config($term->term_id);
        if (empty($config['urls'])) return;

        $items = $this->get_cached_items('term', $term->term_id, $config);
        if (empty($items) || is_wp_error($items)) return;

        echo $this->render_html($items, $config['show_img'], $config['show_src']);
    }


    /**
     * Render RSS after taxonomy loop
     */
    public function render_term_after_loop($query) {
        if (is_admin() || !is_main_query()) return;

        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if (!$term || empty($term->term_id)) return;

            $config = $this->get_term_config($term->term_id);
            if (empty($config['urls'])) return;

            $items = $this->get_cached_items('term', $term->term_id, $config);
            if (is_wp_error($items) || empty($items)) return;

            echo $this->render_html($items, $config['show_img'], $config['show_src']);
        }
    }

    /**
     * Get cached RSS items
     */
    private function get_cached_items($type, $id, $config) {
        $cache_key = ($type === 'post') ? $this->get_post_cache_key($id) : $this->get_term_cache_key($id);
        $items = get_transient($cache_key);
        
        if ($items === false) {
            $items = $this->collect_items($config['urls'], $config['limit'], $config['order'], $config['show_img'], $config['show_src']);
            set_transient($cache_key, $items, max(0, $config['ttl']));
        }
        
        return $items;
    }

    /**
     * Collect RSS items from multiple feeds
     */
    private function collect_items($urls, $limit, $order, $show_img, $show_src) {
        $all_items = [];

        foreach ($urls as $url) {
            $feed = fetch_feed($url);
            if (is_wp_error($feed)) {
                error_log('[asrss] ' . $feed->get_error_message());
                continue;
            }

            $source = '';
            if ($show_src) {
                $source = $feed->get_title();
                if (!$source) {
                    $host = wp_parse_url($url, PHP_URL_HOST);
                    if ($host) $source = $host;
                }
                $source = wp_strip_all_tags((string)$source);
            }

            $max_items = min($feed->get_item_quantity($limit), $limit);
            $items = $feed->get_items(0, $max_items);
            if (!$items) continue;

            foreach ($items as $item) {
                $all_items[] = $this->process_feed_item($item, $source, $show_img, $url);
            }
        }

        if (empty($all_items)) return [];

        // Sort items by date
        usort($all_items, function($a, $b) use ($order) {
            return ($order === 'asc') ? ($a['date'] <=> $b['date']) : ($b['date'] <=> $a['date']);
        });

        return array_slice($all_items, 0, max(1, (int)$limit));
    }

    /**
     * Process individual feed item
     */
    private function process_feed_item($item, $source_label, $want_image, $fallback_url) {
        $date = (int) $item->get_date('U');
        if ($date <= 0) $date = time();

        $title = wp_strip_all_tags($item->get_title());
        $link = esc_url_raw($item->get_link());
        $desc = $item->get_description();
        if (!$desc) $desc = $item->get_content();
        $excerpt = wp_trim_words(wp_strip_all_tags((string)$desc), 30);

        $image = '';
        if ($want_image) {
            $enclosure = $item->get_enclosure();
            if ($enclosure && $enclosure->get_link()) {
                $image = esc_url_raw($enclosure->get_link());
            }
            
            if (!$image && preg_match('/<img\s[^>]*src\s*=\s*["\']([^"\']+)["\']/i', (string) $item->get_content(), $matches)) {
                $image = esc_url_raw($matches[1]);
            }
        }

        return [
            'date' => $date,
            'title' => $title ?: '(no title)',
            'link' => $link ?: $fallback_url,
            'excerpt' => $excerpt,
            'image' => $image,
            'source' => $source_label,
        ];
    }

    /**
     * Get post cache key
     */
    private function get_post_cache_key($post_id) {
        return 'asrss_post_' . (int)$post_id;
    }

    /**
     * Get term cache key
     */
    private function get_term_cache_key($term_id) {
        return 'asrss_term_' . (int)$term_id;
    }

    /**
     * Handle cache refresh for posts
     */
    public function handle_refresh() {
        $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die('No permission');
        }
        
        check_admin_referer('asrss_refresh_' . $post_id);
        delete_transient($this->get_post_cache_key($post_id));
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php'));
        exit;
    }

    /**
     * Handle cache refresh for terms
     */
    public function handle_refresh_term() {
        $term_id = isset($_GET['term_id']) ? (int) $_GET['term_id'] : 0;
        if (!$term_id) wp_die('Invalid term');
        if (!current_user_can('manage_categories')) wp_die('No permission');
        
        check_admin_referer('asrss_refresh_term_' . $term_id);
        delete_transient($this->get_term_cache_key($term_id));
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit-tags.php'));
        exit;
    }


    /**
     * Render RSS items as HTML
     */
    private function render_html($items, $show_img, $show_src) {
        ob_start(); ?>
        <section class="asrss" style="margin:2rem 0;padding-top:1rem;border-top:1px solid #eee">
            <h3>Related from RSS</h3>
            <div class="asrss-list">
            <?php foreach ($items as $item): ?>
                <article class="asrss-item" style="margin:.75rem 0; display:flex; gap:12px;">
                    <?php if ($show_img && !empty($item['image'])): ?>
                        <a href="<?php echo esc_url($item['link']); ?>" target="_blank" rel="noopener">
                            <img src="<?php echo esc_url($item['image']); ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:4px;">
                        </a>
                    <?php endif; ?>
                    <div>
                        <a href="<?php echo esc_url($item['link']); ?>" target="_blank" rel="noopener">
                            <strong><?php echo esc_html($item['title']); ?></strong>
                        </a>
                        <div style="font-size:.85em;color:#666;">
                            <time datetime="<?php echo esc_attr(date('c', $item['date'])); ?>">
                                <?php echo esc_html(date_i18n(get_option('date_format'), $item['date'])); ?>
                            </time>
                            <?php if ($show_src && !empty($item['source'])): ?>
                                · <span><?php echo esc_html($item['source']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($item['excerpt'])): ?>
                            <div><?php echo esc_html($item['excerpt']); ?></div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }
}

// Initialize the plugin
ASRSS_Plugin::get_instance();
