<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mr. Cloak Analytics Queue
 *
 * Manages analytics event queuing and batch submission to API
 * Batches events (50 events) or flushes hourly
 */
class MRC_Analytics_Queue {

    private static $instance = null;
    private $max_batch_size = 50;
    private $max_retry_attempts = 3;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Add event to queue
     *
     * @param array $event Event data
     */
    public function add_event($event) {
        $queue = get_option('mrc_analytics_queue', array());
        $queue[] = $event;
        update_option('mrc_analytics_queue', $queue);

        // Auto-flush if batch size reached
        if (count($queue) >= $this->max_batch_size) {
            $this->flush_queue();
        }
    }

    /**
     * Flush queue and submit events to API
     *
     * @return bool True if successful
     */
    public function flush_queue() {
        $queue = get_option('mrc_analytics_queue', array());

        if (empty($queue)) {
            return true;
        }

        // Get license key
        $license_key = MRC_Security::decrypt(get_option('mrc_license_key'));

        if (!$license_key) {
            error_log('Mr. Cloak: Cannot flush analytics queue - no license key');
            return false;
        }

        // Submit events in batches of max 100
        $batches = array_chunk($queue, 100);
        $api_client = MRC_API_Client::get_instance();
        $total_inserted = 0;
        $total_failed = 0;

        foreach ($batches as $batch) {
            $response = $api_client->submit_analytics($license_key, $batch);

            if (isset($response['success']) && $response['success']) {
                $total_inserted += $response['inserted'];
                $total_failed += $response['failed'];
            } else {
                // Submission failed - increment retry count
                $this->handle_failed_submission($batch);
                return false;
            }
        }

        // Clear queue after successful submission
        delete_option('mrc_analytics_queue');
        update_option('mrc_last_analytics_flush', time());

        error_log("Mr. Cloak: Analytics flushed - {$total_inserted} inserted, {$total_failed} failed");

        return true;
    }

    /**
     * Handle failed submission with retry logic
     *
     * @param array $batch Failed batch
     */
    private function handle_failed_submission($batch) {
        // Get retry queue
        $retry_queue = get_option('mrc_analytics_retry_queue', array());

        // Add batch to retry queue with attempt counter
        foreach ($batch as $event) {
            $retry_queue[] = array(
                'event' => $event,
                'attempts' => ($retry_queue[0]['attempts'] ?? 0) + 1,
                'last_attempt' => time()
            );
        }

        // Remove events that exceeded max retry attempts
        $retry_queue = array_filter($retry_queue, function($item) {
            return $item['attempts'] < $this->max_retry_attempts;
        });

        update_option('mrc_analytics_retry_queue', $retry_queue);
    }

    /**
     * Retry failed submissions
     *
     * @return bool True if successful
     */
    public function retry_failed_submissions() {
        $retry_queue = get_option('mrc_analytics_retry_queue', array());

        if (empty($retry_queue)) {
            return true;
        }

        // Extract events from retry queue
        $events = array_map(function($item) {
            return $item['event'];
        }, $retry_queue);

        // Get license key
        $license_key = MRC_Security::decrypt(get_option('mrc_license_key'));

        if (!$license_key) {
            return false;
        }

        // Attempt to submit
        $api_client = MRC_API_Client::get_instance();
        $response = $api_client->submit_analytics($license_key, $events);

        if (isset($response['success']) && $response['success']) {
            // Success - clear retry queue
            delete_option('mrc_analytics_retry_queue');
            return true;
        } else {
            // Failed again - increment retry counters
            foreach ($retry_queue as &$item) {
                $item['attempts']++;
                $item['last_attempt'] = time();
            }

            // Remove events that exceeded max attempts
            $retry_queue = array_filter($retry_queue, function($item) {
                return $item['attempts'] < $this->max_retry_attempts;
            });

            update_option('mrc_analytics_retry_queue', array_values($retry_queue));
            return false;
        }
    }

    /**
     * Get queue statistics
     *
     * @return array Queue stats
     */
    public function get_queue_stats() {
        $queue = get_option('mrc_analytics_queue', array());
        $retry_queue = get_option('mrc_analytics_retry_queue', array());
        $last_flush = get_option('mrc_last_analytics_flush', 0);

        return array(
            'pending_events' => count($queue),
            'failed_events' => count($retry_queue),
            'total_events' => count($queue) + count($retry_queue),
            'last_flush' => $last_flush,
            'last_flush_formatted' => $last_flush > 0 ? date('Y-m-d H:i:s', $last_flush) : 'Never'
        );
    }

    /**
     * Get pending events (for display in dashboard)
     *
     * @param int $limit Number of events to return
     * @return array Array of events
     */
    public function get_pending_events($limit = 10) {
        $queue = get_option('mrc_analytics_queue', array());
        return array_slice($queue, -$limit);
    }

    /**
     * Clear all queues (for testing/debugging)
     */
    public function clear_all_queues() {
        delete_option('mrc_analytics_queue');
        delete_option('mrc_analytics_retry_queue');
    }

    /**
     * Schedule hourly flush cron job
     */
    public static function schedule_cron() {
        if (!wp_next_scheduled('mrc_flush_analytics')) {
            wp_schedule_event(time() + 3600, 'hourly', 'mrc_flush_analytics');
        }
    }

    /**
     * Unschedule cron jobs
     */
    public static function unschedule_cron() {
        wp_clear_scheduled_hook('mrc_flush_analytics');
    }
}
