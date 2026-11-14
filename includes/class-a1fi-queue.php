<?php
if (!defined('ABSPATH')) { exit; }

/** Persistent, bounded-batch import queue and progress endpoint. */
class A1FI_Queue {
    const BATCH_SIZE = 10;
    const LOCK_SECONDS = 240;

    public static function boot() {
        self::install();
        add_action('init', [__CLASS__, 'ensure_cron']);
        add_action('a1fi_process_queue', [__CLASS__, 'process_queue'], 10);
    }

    /** Register before A1FI_Plugin::boot() so the Run action enqueues instead of importing inline. */
    public static function register_run_handler() {
        add_action('admin_post_a1fi_run', [__CLASS__, 'enqueue_manual_run'], 0);
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $jobs_table = self::jobs_table();
        $rows_table = self::rows_table();
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE $jobs_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            feed_id bigint(20) unsigned NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'queued',
            total_rows int unsigned NOT NULL DEFAULT 0,
            processed_rows int unsigned NOT NULL DEFAULT 0,
            created_count int unsigned NOT NULL DEFAULT 0,
            updated_count int unsigned NOT NULL DEFAULT 0,
            skipped_count int unsigned NOT NULL DEFAULT 0,
            failed_count int unsigned NOT NULL DEFAULT 0,
            started_at datetime NULL,
            finished_at datetime NULL,
            message longtext NULL,
            errors longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY feed_status (feed_id, status),
            KEY status_started (status, started_at)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $rows_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            row_number int unsigned NOT NULL DEFAULT 0,
            payload longtext NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'queued',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY job_row (job_id, row_number),
            KEY status_job (status, job_id)
        ) $charset_collate;");
    }

    public static function ensure_cron() {
        if (!wp_next_scheduled('a1fi_process_queue')) {
            wp_schedule_event(time() + 60, 'a1fi_minute', 'a1fi_process_queue');
        }
    }

    /** Verify and enqueue a feed from the existing Run form. */
    public static function enqueue_manual_run() {
        $id = absint($_POST['feed_id'] ?? 0);
        check_admin_referer('a1fi_run_' . $id);
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('Unauthorized.', 'allinonefeedimporter'));

        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $feed = $feeds[$id] ?? null;
        if (!$feed) self::run_redirect(__('Feed not found; save it and try again.', 'allinonefeedimporter'));

        $queued = self::queue_feed($id, $feed);
        if (is_wp_error($queued)) self::run_redirect($queued->get_error_message(), (int) $queued->get_error_data());
        self::run_redirect(sprintf(__('Import job #%d was queued with %d product rows.', 'allinonefeedimporter'), $queued['job_id'], $queued['stored']), $queued['job_id']);
    }

    /** Enqueue a saved feed from the scheduler without relying on an admin request. */
    public static function enqueue_scheduled_run($id) {
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $feed = $feeds[$id] ?? null;
        if (!$feed) return new WP_Error('a1fi_queue_feed', __('Feed not found; save it and try again.', 'allinonefeedimporter'));
        return self::queue_feed((int) $id, $feed);
    }

    private static function queue_feed($id, $feed) {
        $file = self::get_feed_file($feed);
        if (is_wp_error($file)) return $file;
        $previous_path = $_POST['item_path'] ?? null;
        $had_path = array_key_exists('item_path', $_POST);
        if (!empty($feed['item_path'])) $_POST['item_path'] = $feed['item_path'];
        $result = A1FI_Feed_Reader::read($file, $feed['file_type'] ?? 'csv', $feed['delimiter'] ?? ',');
        if ($had_path) $_POST['item_path'] = $previous_path; else unset($_POST['item_path']);
        if (!empty($file['temporary']) && is_file($file['path'])) @unlink($file['path']);
        if (is_wp_error($result)) return $result;

        $errors = A1FI_Feed_Reader::validate($feed['mapping'] ?? [], $result['headers']);
        if ($errors) return new WP_Error('a1fi_queue_mapping', implode(' ', $errors));
        if (empty($result['rows'])) return new WP_Error('a1fi_queue_empty', __('The feed contains no product rows to import.', 'allinonefeedimporter'));

        global $wpdb;
        $jobs = self::jobs_table();
        $rows_table = self::rows_table();
        $now = current_time('mysql');
        if ($wpdb->insert($jobs, [
            'feed_id' => $id,
            'status' => 'queued',
            'total_rows' => count($result['rows']),
            'processed_rows' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'started_at' => $now,
            'created_at' => $now,
        ], ['%d','%s','%d','%d','%d','%d','%d','%d','%s','%s']) === false) {
            return new WP_Error('a1fi_queue_job', sprintf(__('Could not create import job: %s', 'allinonefeedimporter'), $wpdb->last_error ?: __('database insert failed', 'allinonefeedimporter')));
        }
        $job_id = (int) $wpdb->insert_id;
        $batch = [];
        foreach ($result['rows'] as $index => $row) {
            $payload = wp_json_encode($row);
            if ($payload === false) {
                return self::fail_enqueue($job_id, $rows_table, __('A feed row could not be encoded as JSON.', 'allinonefeedimporter'));
            }
            $batch[] = $wpdb->prepare('(%d,%d,%s,%s)', $job_id, $index + 1, $payload, 'queued');
            if (count($batch) >= self::BATCH_SIZE) {
                if (!self::insert_rows($rows_table, $batch)) {
                    return self::fail_enqueue($job_id, $rows_table, sprintf(__('Could not save feed rows: %s', 'allinonefeedimporter'), $wpdb->last_error ?: __('database insert failed', 'allinonefeedimporter')));
                }
                $batch = [];
            }
        }
        if ($batch && !self::insert_rows($rows_table, $batch)) {
            return self::fail_enqueue($job_id, $rows_table, sprintf(__('Could not save feed rows: %s', 'allinonefeedimporter'), $wpdb->last_error ?: __('database insert failed', 'allinonefeedimporter')));
        }
        $stored = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rows_table WHERE job_id=%d", $job_id));
        if ($stored !== count($result['rows'])) {
            return self::fail_enqueue($job_id, $rows_table, sprintf(__('Queue saved %1$d of %2$d feed rows. Check the database error log and start a new import.', 'allinonefeedimporter'), $stored, count($result['rows'])));
        }

        wp_schedule_single_event(time() + 5, 'a1fi_process_queue');
        return ['job_id' => $job_id, 'stored' => $stored];
    }

    private static function get_feed_file($feed) {
        if (($feed['source_type'] ?? '') === 'upload') {
            $path = $feed['file_path'] ?? '';
            if (!$path || !is_readable($path)) return new WP_Error('a1fi_queue_file', __('The uploaded feed file is missing or unreadable.', 'allinonefeedimporter'));
            return ['path' => $path, 'temporary' => false];
        }
        $url = $feed['source_url'] ?? '';
        if (!$url || !wp_http_validate_url($url)) return new WP_Error('a1fi_queue_url', __('Enter a valid feed URL.', 'allinonefeedimporter'));
        $response = wp_safe_remote_get($url, ['timeout' => 60, 'redirection' => 3, 'limit_response_size' => A1FI_Feed_Reader::MAX_BYTES]);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) return new WP_Error('a1fi_queue_http', __('Feed URL returned an unsuccessful HTTP status.', 'allinonefeedimporter'));
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > A1FI_Feed_Reader::MAX_BYTES) return new WP_Error('a1fi_queue_size', __('Feed exceeds 20 MB.', 'allinonefeedimporter'));
        $path = wp_tempnam('a1fi-feed');
        if (!$path || file_put_contents($path, $body) === false) return new WP_Error('a1fi_queue_temp', __('Could not save downloaded feed.', 'allinonefeedimporter'));
        return ['path' => $path, 'temporary' => true];
    }

    private static function insert_rows($table, $batch) {
        global $wpdb;
        return $wpdb->query('INSERT INTO ' . $table . ' (job_id,row_number,payload,status) VALUES ' . implode(',', $batch)) !== false;
    }

    private static function fail_enqueue($job_id, $rows_table, $message) {
        global $wpdb;
        $wpdb->delete($rows_table, ['job_id' => $job_id], ['%d']);
        $wpdb->update(self::jobs_table(), [
            'status' => 'failed',
            'finished_at' => current_time('mysql'),
            'message' => $message,
            'errors' => wp_json_encode([$message]),
        ], ['id' => $job_id], ['%s','%s','%s','%s'], ['%d']);
        return new WP_Error('a1fi_queue_rows', $message, $job_id);
    }

    private static function run_redirect($message, $job_id = 0) {
        $args = ['page' => 'a1fi', 'message' => rawurlencode($message)];
        if ($job_id) $args['a1fi_job'] = (int) $job_id;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /** Process one bounded batch; cron and the authenticated admin fallback call this action. */
    public static function process_queue() {
        if (get_transient('a1fi_queue_lock')) return;
        set_transient('a1fi_queue_lock', 1, self::LOCK_SECONDS);
        try {
            self::process_batch();
        } finally {
            delete_transient('a1fi_queue_lock');
        }
    }

    private static function process_batch() {
        global $wpdb;
        $jobs = self::jobs_table();
        $rows_table = self::rows_table();
        $job = $wpdb->get_row("SELECT * FROM $jobs WHERE status IN ('queued','running') ORDER BY id ASC LIMIT 1");
        if (!$job) return;
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $feed = $feeds[$job->feed_id] ?? null;
        if (!$feed) {
            self::finish_job($job, 'failed', __('Feed configuration no longer exists.', 'allinonefeedimporter'));
            return;
        }
        $stored = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rows_table WHERE job_id=%d", (int) $job->id));
        if ($stored !== (int) $job->total_rows) {
            self::finish_job($job, 'failed', sprintf(__('Queue contains %1$d of %2$d expected rows.', 'allinonefeedimporter'), $stored, (int) $job->total_rows));
            return;
        }
        $wpdb->update($jobs, ['status' => 'running'], ['id' => (int) $job->id, 'status' => 'queued'], ['%s'], ['%d','%s']);
        $batch = $wpdb->get_results($wpdb->prepare(
            "SELECT id,`row_number`,payload FROM $rows_table WHERE job_id=%d AND status='queued' ORDER BY `row_number` ASC LIMIT %d",
            (int) $job->id,
            self::BATCH_SIZE
        ));
        if (!$batch) {
            $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rows_table WHERE job_id=%d AND status='queued'", (int) $job->id));
            if (!$pending) self::finish_job($job, 'completed', __('Import completed.', 'allinonefeedimporter'));
            return;
        }
        $errors = $job->errors ? json_decode($job->errors, true) : [];
        if (!is_array($errors)) $errors = [];
        foreach ($batch as $queued_row) {
            $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $jobs WHERE id=%d", (int) $job->id));
            if ($wpdb->last_error) {
                error_log('All In One Feed Importer: could not check job status: ' . $wpdb->last_error);
                return;
            }
            if (!in_array($status, ['queued', 'running'], true)) return;
            $data = json_decode($queued_row->payload, true);
            if (!is_array($data)) {
                $wpdb->update($rows_table, ['status' => 'failed'], ['id' => (int) $queued_row->id], ['%s'], ['%d']);
                $wpdb->query($wpdb->prepare("UPDATE $jobs SET processed_rows=processed_rows+1,failed_count=failed_count+1 WHERE id=%d", (int) $job->id));
                $errors[] = sprintf(__('Row %d: stored feed row is invalid JSON.', 'allinonefeedimporter'), (int) $queued_row->row_number);
                continue;
            }
            $outcome = self::import_product($data, $feed['mapping'] ?? []);
            if (is_wp_error($outcome)) {
                $wpdb->update($rows_table, ['status' => 'failed'], ['id' => (int) $queued_row->id], ['%s'], ['%d']);
                $wpdb->query($wpdb->prepare("UPDATE $jobs SET processed_rows=processed_rows+1,failed_count=failed_count+1 WHERE id=%d", (int) $job->id));
                if (count($errors) < 100) $errors[] = sprintf(__('Row %d: %s', 'allinonefeedimporter'), (int) $queued_row->row_number, $outcome->get_error_message());
                continue;
            }
            $wpdb->update($rows_table, ['status' => 'completed'], ['id' => (int) $queued_row->id], ['%s'], ['%d']);
            $counter = [
                'created' => 'created_count',
                'updated' => 'updated_count',
                'skipped' => 'skipped_count',
            ][$outcome['outcome']];
            $wpdb->query($wpdb->prepare("UPDATE $jobs SET processed_rows=processed_rows+1,`$counter`=`$counter`+1 WHERE id=%d", (int) $job->id));
        }
        $wpdb->update($jobs, ['errors' => $errors ? wp_json_encode($errors) : null, 'updated_at' => current_time('mysql')], ['id' => (int) $job->id], ['%s','%s'], ['%d']);
        $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM $jobs WHERE id=%d", (int) $job->id));
        if ($current && (int) $current->processed_rows >= (int) $current->total_rows) {
            self::finish_job($current, 'completed', sprintf(__('Created %1$d, updated %2$d, skipped %3$d, failed %4$d.', 'allinonefeedimporter'), (int) $current->created_count, (int) $current->updated_count, (int) $current->skipped_count, (int) $current->failed_count));
        } else {
            wp_schedule_single_event(time() + 5, 'a1fi_process_queue');
        }
    }

    private static function import_product($row, $mapping) {
        $values = [];
        foreach ($mapping as $field => $column) {
            if ($column !== '' && array_key_exists($column, $row)) $values[$field] = $row[$column];
        }
        $name = trim(wp_strip_all_tags((string) ($values['name'] ?? '')));
        if ($name === '') return new WP_Error('a1fi_queue_name', __('Missing product name.', 'allinonefeedimporter'));
        $sku = trim((string) ($values['sku'] ?? ''));
        $product_id = $sku !== '' ? wc_get_product_id_by_sku($sku) : 0;
        $product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();
        if (!$product) return new WP_Error('a1fi_queue_product', __('The matching product could not be loaded.', 'allinonefeedimporter'));
        try {
            $created = !$product_id;
            $changed = $created;
            if ($created) {
                $product->set_name($name);
                if ($sku !== '') $product->set_sku($sku);
                foreach (['description' => 'set_description', 'short_description' => 'set_short_description'] as $field => $method) {
                    if (isset($values[$field])) $product->$method(wp_kses_post((string) $values[$field]));
                }
                $status = strtolower((string) ($values['status'] ?? 'publish'));
                $product->set_status(in_array($status, ['publish','draft','pending','private'], true) ? $status : 'publish');
            }
            foreach (['regular_price' => 'set_regular_price', 'sale_price' => 'set_sale_price'] as $field => $method) {
                if (!isset($values[$field]) || !is_numeric($values[$field])) continue;
                $getter = 'get_' . $field;
                $current = $product->$getter('edit');
                if ($created || $current === '' || (float) $current !== (float) $values[$field]) {
                    $product->$method((string) $values[$field]);
                    $changed = true;
                }
            }
            if (isset($values['stock_quantity']) && is_numeric($values['stock_quantity'])) {
                $quantity = (int) $values['stock_quantity'];
                if ($created || !$product->get_manage_stock('edit') || $product->get_stock_quantity('edit') === null || (int) $product->get_stock_quantity('edit') !== $quantity) {
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($quantity);
                    $changed = true;
                }
            }
            if ($created && !empty($values['categories'])) {
                $term_ids = [];
                foreach (explode(',', (string) $values['categories']) as $category) {
                    $category = trim($category);
                    if ($category === '') continue;
                    $term = term_exists($category, 'product_cat');
                    if (!$term) $term = wp_insert_term($category, 'product_cat');
                    if (!is_wp_error($term)) $term_ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
                }
                if ($term_ids) $product->set_category_ids(array_values(array_unique($term_ids)));
            }
            if ($created) {
                $product_id = $product->save();
                if (!$product_id) return new WP_Error('a1fi_queue_save', __('WooCommerce could not save product.', 'allinonefeedimporter'));
            }
            $images_changed = false;
            if (!empty($values['images'])) {
                $images_changed = self::sync_images($product, (int) $product_id, (string) $values['images']);
                if (is_wp_error($images_changed)) return $images_changed;
                $changed = $changed || $images_changed;
            }
            if ((!$created && $changed) || ($created && $images_changed)) {
                if (!$product->save()) return new WP_Error('a1fi_queue_save', __('WooCommerce could not save product.', 'allinonefeedimporter'));
            }
            return ['id' => (int) $product_id, 'outcome' => $created ? 'created' : ($changed ? 'updated' : 'skipped')];
        } catch (Throwable $error) {
            return new WP_Error('a1fi_queue_product', __('Product could not be imported; check the feed values and WooCommerce logs.', 'allinonefeedimporter'));
        }
    }

    private static function sync_images($product, $product_id, $value) {
        $urls = array_slice(array_map('trim', explode(',', $value)), 0, 10);
        $urls = array_values(array_filter($urls, static function ($url) { return $url !== ''; }));
        if (!$urls) return false;
        $existing_ids = array_values(array_filter(array_map('intval', array_merge([(int) $product->get_image_id('edit')], $product->get_gallery_image_ids('edit')))));
        $by_url = [];
        foreach ($existing_ids as $id) {
            if (!wp_attachment_is_image($id)) continue;
            $source = get_post_meta($id, '_source_url', true);
            if ($source !== '') $by_url[$source] = $id;
        }
        $searched = false;
        $ids = [];
        foreach ($urls as $url) {
            if (!wp_http_validate_url($url)) return new WP_Error('a1fi_queue_image_url', __('A mapped image URL is invalid.', 'allinonefeedimporter'));
            $url = esc_url_raw($url);
            if (!isset($by_url[$url]) && !$searched) {
                $searched = true;
                $attachments = get_posts([
                    'post_type' => 'attachment',
                    'post_status' => 'inherit',
                    'post_parent' => $product_id,
                    'post_mime_type' => 'image',
                    'fields' => 'ids',
                    'posts_per_page' => -1,
                    'no_found_rows' => true,
                ]);
                foreach ($attachments as $id) {
                    if (!wp_attachment_is_image($id)) continue;
                    $source = get_post_meta($id, '_source_url', true);
                    if ($source !== '' && !isset($by_url[$source])) $by_url[$source] = (int) $id;
                }
            }
            if (!isset($by_url[$url])) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $id = media_sideload_image($url, $product_id, null, 'id');
                if (is_wp_error($id)) return new WP_Error('a1fi_queue_image', sprintf(__('Could not import a product image: %s', 'allinonefeedimporter'), $id->get_error_message()));
                $by_url[$url] = (int) $id;
            }
            $ids[] = $by_url[$url];
        }
        if ($ids === $existing_ids) return false;
        $product->set_image_id($ids[0]);
        $product->set_gallery_image_ids(array_slice($ids, 1));
        return true;
    }

    private static function finish_job($job, $status, $message) {
        global $wpdb;
        $jobs = self::jobs_table();
        $wpdb->query($wpdb->prepare(
            "UPDATE $jobs SET status=%s, finished_at=%s, message=%s WHERE id=%d AND status IN ('queued','running')",
            $status, current_time('mysql'), $message, (int) $job->id
        ));
    }

    public static function jobs_table() {
        global $wpdb;
        return $wpdb->prefix . 'a1fi_jobs';
    }

    public static function rows_table() {
        global $wpdb;
        return $wpdb->prefix . 'a1fi_job_rows';
    }
}
