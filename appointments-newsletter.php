<?php
/**
 * Plugin Name: Appointments → Newsletter
 * Description: Integrates Appointments+ with jan-newsletter. Adds marketing consent checkbox, auto-subscribes on booking, and provides WP-CLI export.
 * Version: 1.0.0
 * Author: Jan
 * Requires Plugins: appointments, jan-newsletter
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Marketing consent checkbox on booking form
 */
add_filter('app_additional_fields', function ($html) {
    $checkbox = '<div class="appointments-field appointments-marketing-consent-field">';
    $checkbox .= '<label for="appointments-marketing-consent">';
    $checkbox .= '<input type="checkbox" id="appointments-marketing-consent" '
        . 'class="appointments-field-entry" '
        . 'data-name="additional_fields[marketing_consent]" value="1" checked="checked" /> ';
    $checkbox .= '<span>I\'d like to receive special offers and discounts</span>';
    $checkbox .= '</label>';
    $checkbox .= '</div>';

    return $html . $checkbox;
});

/**
 * Auto-subscribe on new appointment (runs after additional fields are saved at priority 2)
 */
add_action('wpmudev_appointments_insert_appointment', function ($app_id) {
    $fields = function_exists('appointments_get_app_additional_fields')
        ? appointments_get_app_additional_fields($app_id)
        : [];

    $consented = !empty($fields['marketing_consent']);
    if (!$consented) {
        return;
    }

    $app = appointments_get_appointment($app_id);
    if (!$app || empty($app->email)) {
        return;
    }

    app_newsletter_subscribe($app);
}, 10);

/**
 * Subscribe an appointment customer to the "Appointments" newsletter list
 */
function app_newsletter_subscribe($app) {
    $subscriber_repo = new JanNewsletter\Repositories\SubscriberRepository();
    $list_repo = new JanNewsletter\Repositories\ListRepository();

    // Get or create "Appointments" list
    $list = $list_repo->find_by_slug('appointments');
    if (!$list) {
        $list_id = $list_repo->create([
            'name' => 'Appointments',
            'description' => 'Customers who booked via the website',
            'double_optin' => 0,
        ]);
    } else {
        $list_id = $list->id;
    }

    // Parse name
    $name_parts = explode(' ', trim($app->name), 2);
    $first_name = $name_parts[0] ?? '';
    $last_name = $name_parts[1] ?? '';

    // Check if subscriber exists
    $existing = $subscriber_repo->find_by_email($app->email);

    if ($existing) {
        if ($existing->status === 'unsubscribed') {
            $subscriber_repo->update($existing->id, ['status' => 'subscribed']);
        }
        if (!empty($app->phone)) {
            $custom = $existing->custom_fields ?? [];
            $custom['phone'] = $app->phone;
            $subscriber_repo->update($existing->id, ['custom_fields' => $custom]);
        }
        $subscriber_repo->add_to_list($existing->id, $list_id);
        return;
    }

    // Create new subscriber
    $custom_fields = [];
    if (!empty($app->phone)) {
        $custom_fields['phone'] = $app->phone;
    }

    $subscriber_id = $subscriber_repo->create([
        'email' => sanitize_email($app->email),
        'first_name' => sanitize_text_field($first_name),
        'last_name' => sanitize_text_field($last_name),
        'status' => 'subscribed',
        'source' => 'appointment',
        'custom_fields' => $custom_fields,
        'ip_address' => app_newsletter_get_client_ip(),
    ]);

    $subscriber_repo->add_to_list($subscriber_id, $list_id);
}

/**
 * Get validated client IP (Cloudflare-aware)
 */
function app_newsletter_get_client_ip(): string {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '';
}

/**
 * WP-CLI commands
 */
if (defined('WP_CLI') && WP_CLI) {
    class Appointments_Newsletter_CLI {

        /**
         * Export all appointment customers to the "Appointments" newsletter list.
         *
         * ## EXAMPLES
         *     wp appointments-newsletter export
         *
         * @subcommand export
         */
        public function export($args, $assoc_args) {
            global $wpdb;

            $subscriber_repo = new JanNewsletter\Repositories\SubscriberRepository();
            $list_repo = new JanNewsletter\Repositories\ListRepository();

            $list = $list_repo->find_by_slug('appointments');
            if (!$list) {
                $list_id = $list_repo->create([
                    'name' => 'Appointments',
                    'description' => 'Customers who booked via the website',
                    'double_optin' => 0,
                ]);
                WP_CLI::log('Created "Appointments" list.');
            } else {
                $list_id = $list->id;
                WP_CLI::log('Using existing "Appointments" list.');
            }

            $table = $wpdb->prefix . 'app_appointments';
            $customers = $wpdb->get_results(
                "SELECT name, email, phone
                 FROM {$table}
                 WHERE email != ''
                 GROUP BY email
                 ORDER BY MIN(ID) ASC"
            );

            $created = 0;
            $skipped = 0;

            foreach ($customers as $customer) {
                $existing = $subscriber_repo->find_by_email($customer->email);

                if ($existing) {
                    $subscriber_repo->add_to_list($existing->id, $list_id);
                    $skipped++;
                    continue;
                }

                $name_parts = explode(' ', trim($customer->name), 2);
                $custom_fields = [];
                if (!empty($customer->phone)) {
                    $custom_fields['phone'] = $customer->phone;
                }

                $subscriber_id = $subscriber_repo->create([
                    'email' => sanitize_email($customer->email),
                    'first_name' => sanitize_text_field($name_parts[0] ?? ''),
                    'last_name' => sanitize_text_field($name_parts[1] ?? ''),
                    'status' => 'subscribed',
                    'source' => 'appointment',
                    'custom_fields' => $custom_fields,
                ]);

                $subscriber_repo->add_to_list($subscriber_id, $list_id);
                $created++;
            }

            $total = count($customers);
            WP_CLI::success("Done. Total: {$total}, Created: {$created}, Skipped (existing): {$skipped}");
        }

        /**
         * Create a "Test" list and add a test subscriber.
         *
         * ## OPTIONS
         *
         * [--email=<email>]
         * : Email address for the test subscriber.
         * ---
         * default: janokapapa2002@gmail.com
         * ---
         *
         * ## EXAMPLES
         *     wp appointments-newsletter create-test-list
         *     wp appointments-newsletter create-test-list --email=test@example.com
         *
         * @subcommand create-test-list
         */
        public function create_test_list($args, $assoc_args) {
            $subscriber_repo = new JanNewsletter\Repositories\SubscriberRepository();
            $list_repo = new JanNewsletter\Repositories\ListRepository();

            $list = $list_repo->find_by_slug('test');
            if (!$list) {
                $list_id = $list_repo->create([
                    'name' => 'Test',
                    'description' => 'Test list for newsletter testing',
                    'double_optin' => 0,
                ]);
                WP_CLI::log('Created "Test" list.');
            } else {
                $list_id = $list->id;
                WP_CLI::log('Using existing "Test" list.');
            }

            $email = $assoc_args['email'] ?? 'janokapapa2002@gmail.com';
            $existing = $subscriber_repo->find_by_email($email);

            if ($existing) {
                $subscriber_repo->add_to_list($existing->id, $list_id);
                WP_CLI::success("Subscriber {$email} already exists, added to Test list.");
            } else {
                $subscriber_id = $subscriber_repo->create([
                    'email' => $email,
                    'first_name' => 'Test',
                    'last_name' => 'Subscriber',
                    'status' => 'subscribed',
                    'source' => 'cli',
                ]);
                $subscriber_repo->add_to_list($subscriber_id, $list_id);
                WP_CLI::success("Created subscriber {$email} and added to Test list.");
            }
        }
    }

    WP_CLI::add_command('appointments-newsletter', 'Appointments_Newsletter_CLI');
}
