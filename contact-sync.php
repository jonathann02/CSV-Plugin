<?php
/**
 * Plugin Name: Contact Sync (Ninja Forms → CSV för Mailchimp)
 * Description: Samlar Ninja Forms-inlämningar, normaliserar data, förhindrar dubletter och skickar CSV automatiskt (schemalagt) eller manuellt. CSV: Email Address, First Name, Last Name, Company, Phone Number, Message, Source Form, Submitted At.
 * Version: 2.1.1
 * Author: Jonathan
 * Text Domain: csmc
 */

if (!defined('ABSPATH')) exit;

class CSMC_Plugin {
    const OPT_SETTINGS   = 'csmc_settings';
    const OPT_INBOX      = 'csmc_inbox';
    const OPT_LOG        = 'csmc_log';
    const CRON_HOOK      = 'csmc_cron_digest';
    const CRON_CLEANUP   = 'csmc_cron_cleanup';
    const MAX_INBOX_SIZE = 5000;
    const INBOX_CLEANUP_DAYS = 90;
    const CSV_CLEANUP_DAYS = 180;

    public function __construct() {
        add_action('plugins_loaded', [$this, 'maybe_set_defaults']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'handle_admin_posts']);
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        register_activation_hook(__FILE__, [$this, 'on_activate']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);
        add_action(self::CRON_HOOK, [$this, 'run_digest']);
        add_action(self::CRON_CLEANUP, [$this, 'run_cleanup']);
        add_action('ninja_forms_after_submission', [$this, 'capture_ninja_submission']);
        add_action('admin_post_csmc_download_csv', [$this, 'handle_csv_download']);
    }

    public function maybe_set_defaults() {
        $s = get_option(self::OPT_SETTINGS);
        if (!is_array($s)) {
            $s = [];
        }
        $defaults = [
            'recipient_email' => get_bloginfo('admin_email'),
            'email_subject'   => 'Månadens kontakter (CSV)',
            'schedule'        => 'monthly',
            'delivery'        => 'email',
            'dedupe_mode'     => 'email',
            'allowed_tlds'    => 'se,com,info,nu',
        ];
        $merged = array_merge($defaults, $s);
        if ($merged !== $s) update_option(self::OPT_SETTINGS, $merged, false);

        if (!is_array(get_option(self::OPT_INBOX))) {
            update_option(self::OPT_INBOX, [], false);
        }
        if (!is_array(get_option(self::OPT_LOG))) {
            update_option(self::OPT_LOG, [], false);
        }
    }

    public function admin_menu() {
        add_menu_page(
            'Contact Sync', 'Contact Sync', 'manage_options',
            'csmc_settings', [$this, 'render_settings_page'], 'dashicons-email-alt'
        );
    }

    private function add_log($msg) {
        $log = get_option(self::OPT_LOG, []);
        $log[] = '[' . current_time('mysql') . '] ' . $msg;
        if (count($log) > 200) $log = array_slice($log, -200);
        update_option(self::OPT_LOG, $log, false);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = get_option(self::OPT_SETTINGS, []);
        $log = get_option(self::OPT_LOG, []);
        $inbox = get_option(self::OPT_INBOX, []);
        $inbox_count = count($inbox);
        $inbox_exported = count(array_filter($inbox, function($r) { return !empty($r['exported']); }));
        $inbox_pending = $inbox_count - $inbox_exported;
        
        [$csv_dir, $csv_baseurl] = $this->get_uploads_dir();
        $csv_files = glob($csv_dir . '/*.csv') ?: [];
        ?>
        <div class="wrap">
            <h1>Contact Sync</h1>
            <p>Samlar Ninja Forms-inlämningar, normaliserar fält, tar bort dubletter och levererar CSV för import i Mailchimp.</p>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=csmc_settings')); ?>" class="nav-tab nav-tab-active">Inställningar</a>
            </h2>

            <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
                <div class="notice notice-success"><p>Inställningarna sparades.</p></div>
            <?php elseif (isset($_GET['error']) && $_GET['error'] === 'invalid_email'): ?>
                <div class="notice notice-error"><p><strong>Fel:</strong> Ogiltig e-postadress. Kontrollera mottagar-e-posten.</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['digest']) && $_GET['digest'] === 'ok'): ?>
                <div class="notice notice-success"><p>CSV genererades och (om valt) skickades via e-post.</p></div>
            <?php elseif (isset($_GET['digest']) && $_GET['digest'] === 'fail'): ?>
                <div class="notice notice-error"><p>Kunde inte skapa/skicka CSV. Se loggen nedan.</p></div>
            <?php elseif (isset($_GET['digest']) && $_GET['digest'] === 'empty'): ?>
                <div class="notice notice-info"><p>Inga nya kontakter att exportera.</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['cleanup']) && $_GET['cleanup'] === 'ok'): ?>
                <div class="notice notice-success"><p>Städning utförd.</p></div>
            <?php endif; ?>

            <?php if ($inbox_count > self::MAX_INBOX_SIZE * 0.8): ?>
                <div class="notice notice-warning">
                    <p><strong>Varning:</strong> Inbox närmar sig maxgränsen (<?php echo $inbox_count; ?> / <?php echo self::MAX_INBOX_SIZE; ?>). Kör städning eller nollställ.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('csmc_save_settings', 'csmc_nonce'); ?>
                <input type="hidden" name="action" value="csmc_save_settings" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="recipient_email">Mottagar-e-post</label></th>
                        <td><input type="email" id="recipient_email" name="recipient_email" value="<?php echo esc_attr($s['recipient_email'] ?? ''); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_subject">Ämnesrad</label></th>
                        <td><input type="text" id="email_subject" name="email_subject" value="<?php echo esc_attr($s['email_subject'] ?? ''); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="schedule">Schema</label></th>
                        <td>
                            <select id="schedule" name="schedule">
                                <?php
                                $opts = [
                                    'hourly'  => 'Varje timme (test)',
                                    'daily'   => 'Dagligen',
                                    'weekly'  => 'Veckovis',
                                    'monthly' => 'Månatligen',
                                ];
                                foreach ($opts as $k => $label) {
                                    printf('<option value="%s" %s>%s</option>',
                                        esc_attr($k),
                                        selected($s['schedule'] ?? 'monthly', $k, false),
                                        esc_html($label)
                                    );
                                }
                                ?>
                            </select>
                            <p class="description">WP-Cron används. I produktion rekommenderas riktigt cron-jobb som anropar <code>wp-cron.php</code> var 5:e minut.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="delivery">Leverans</label></th>
                        <td>
                            <select id="delivery" name="delivery">
                                <option value="email" <?php selected($s['delivery'] ?? 'email', 'email'); ?>>Skicka CSV via e-post</option>
                                <option value="link"  <?php selected($s['delivery'] ?? 'email', 'link');  ?>>Skapa CSV och visa länk (ingen e-post)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dedupe_mode">Dublettregler</label></th>
                        <td>
                            <select id="dedupe_mode" name="dedupe_mode">
                                <option value="email" <?php selected($s['dedupe_mode'] ?? 'email', 'email'); ?>>Unik per e-post</option>
                                <option value="email_phone" <?php selected($s['dedupe_mode'] ?? 'email', 'email_phone'); ?>>Unik per e-post + telefon</option>
                            </select>
                            <p class="description">Gäller både inom samma CSV och över tid (återexporteras inte).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="allowed_tlds">Tillåtna e-post-TLDs</label></th>
                        <td>
                            <input type="text" id="allowed_tlds" name="allowed_tlds" value="<?php echo esc_attr($s['allowed_tlds'] ?? 'se,com,info,nu'); ?>" class="regular-text" />
                            <p class="description">Kommaseparerat, t.ex. <code>se,com,info,nu</code></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Spara inställningar'); ?>
            </form>

            <hr/>

            <h2>Inbox-status</h2>
            <table class="widefat" style="max-width:500px;">
                <tbody>
                    <tr>
                        <td><strong>Totalt antal poster:</strong></td>
                        <td><?php echo $inbox_count; ?> / <?php echo self::MAX_INBOX_SIZE; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Redan exporterade:</strong></td>
                        <td><?php echo $inbox_exported; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Väntar på export:</strong></td>
                        <td><?php echo $inbox_pending; ?></td>
                    </tr>
                </tbody>
            </table>

            <h2>Åtgärder</h2>
            <p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px;">
                    <?php wp_nonce_field('csmc_generate_now', 'csmc_nonce'); ?>
                    <input type="hidden" name="action" value="csmc_generate_now"/>
                    <button class="button">Generera CSV och visa länk (ingen e-post)</button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px;">
                    <?php wp_nonce_field('csmc_send_now', 'csmc_nonce'); ?>
                    <input type="hidden" name="action" value="csmc_send_now"/>
                    <button class="button button-primary">Skicka månadens CSV nu</button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px;">
                    <?php wp_nonce_field('csmc_cleanup', 'csmc_nonce'); ?>
                    <input type="hidden" name="action" value="csmc_cleanup"/>
                    <button class="button" onclick="return confirm('Städa gamla poster och CSV-filer?');">Kör städning nu</button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px;">
                    <?php wp_nonce_field('csmc_reset_ledger', 'csmc_nonce'); ?>
                    <input type="hidden" name="action" value="csmc_reset_ledger"/>
                    <button class="button button-secondary" onclick="return confirm('Nollställa exportmarkeringar? Tidigare skickade poster kan då komma med igen.');">Nollställ exportmarkeringar</button>
                </form>
            </p>

            <h2>Befintliga CSV-filer</h2>
            <p class="description">Av säkerhetsskäl är CSV-filerna skyddade och kräver inloggning för nedladdning.</p>
            <?php if (empty($csv_files)): ?>
                <p>Inga CSV:er än.</p>
            <?php else: ?>
                <ul>
                <?php foreach (array_reverse($csv_files) as $f): ?>
                    <?php
                    $basename = basename($f);
                    $download_url = wp_nonce_url(
                        admin_url('admin-post.php?action=csmc_download_csv&file=' . urlencode($basename)),
                        'csmc_download_csv'
                    );
                    printf('<li><a href="%s">%s</a> (%s, %s)</li>',
                        esc_url($download_url),
                        esc_html($basename),
                        esc_html(size_format(filesize($f))),
                        esc_html(date('Y-m-d H:i', filemtime($f)))
                    );
                    ?>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h2>Logg</h2>
            <div style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:300px;overflow:auto;">
                <?php if (empty($log)) : ?>
                    <em>Ingen logg ännu.</em>
                <?php else: ?>
                    <pre style="margin:0;"><?php echo esc_html(implode("\n", array_reverse($log))); ?></pre>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function handle_csv_download() {
        if (!current_user_can('manage_options')) {
            wp_die('Åtkomst nekad');
        }

        check_admin_referer('csmc_download_csv');

        $file = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';
        if (empty($file) || strpos($file, '..') !== false) {
            wp_die('Ogiltig fil');
        }

        [$csv_dir, $csv_baseurl] = $this->get_uploads_dir();
        $filepath = trailingslashit($csv_dir) . $file;

        if (!file_exists($filepath) || !is_file($filepath)) {
            wp_die('Filen finns inte');
        }

        $safe_filename = str_replace(['"', "\n", "\r"], '', $file);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safe_filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($filepath);
        exit;
    }

    public function handle_admin_posts() {
        if (!current_user_can('manage_options')) return;

        if (isset($_POST['action']) && $_POST['action'] === 'csmc_save_settings') {
            check_admin_referer('csmc_save_settings', 'csmc_nonce');
            $s = get_option(self::OPT_SETTINGS, []);
            $fields = [
                'email_subject', 'schedule', 'delivery', 'dedupe_mode', 'allowed_tlds'
            ];
            foreach ($fields as $f) {
                $s[$f] = isset($_POST[$f]) ? sanitize_text_field(wp_unslash($_POST[$f])) : '';
            }
            
            $email = isset($_POST['recipient_email']) ? sanitize_email(wp_unslash($_POST['recipient_email'])) : '';
            if (!empty($email) && !is_email($email)) {
                $this->add_log('Ogiltig e-postadress angiven: ' . $email);
                wp_redirect(admin_url('admin.php?page=csmc_settings&saved=0&error=invalid_email'));
                exit;
            }
            $s['recipient_email'] = $email;
            
            update_option(self::OPT_SETTINGS, $s, false);
            $this->reschedule_cron($s['schedule']);
            wp_redirect(admin_url('admin.php?page=csmc_settings&saved=1'));
            exit;
        }

        if (isset($_POST['action']) && $_POST['action'] === 'csmc_generate_now') {
            check_admin_referer('csmc_generate_now', 'csmc_nonce');
            $result = $this->generate_and_maybe_send(false);
            wp_redirect(admin_url('admin.php?page=csmc_settings&digest=' . $result));
            exit;
        }

        if (isset($_POST['action']) && $_POST['action'] === 'csmc_send_now') {
            check_admin_referer('csmc_send_now', 'csmc_nonce');
            $result = $this->generate_and_maybe_send(true);
            wp_redirect(admin_url('admin.php?page=csmc_settings&digest=' . $result));
            exit;
        }

        if (isset($_POST['action']) && $_POST['action'] === 'csmc_cleanup') {
            check_admin_referer('csmc_cleanup', 'csmc_nonce');
            $this->run_cleanup();
            wp_redirect(admin_url('admin.php?page=csmc_settings&cleanup=ok'));
            exit;
        }

        if (isset($_POST['action']) && $_POST['action'] === 'csmc_reset_ledger') {
            check_admin_referer('csmc_reset_ledger', 'csmc_nonce');
            $inbox = get_option(self::OPT_INBOX, []);
            foreach ($inbox as &$row) { $row['exported'] = false; }
            update_option(self::OPT_INBOX, $inbox, false);
            $this->add_log('Exportmarkeringar nollställdes manuellt.');
            wp_redirect(admin_url('admin.php?page=csmc_settings'));
            exit;
        }
    }

    public function cron_schedules($schedules) {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => __('Once Weekly', 'csmc')
            ];
        }
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __('Once Monthly (approx.)', 'csmc')
            ];
        }
        return $schedules;
    }

    public function on_activate() {
        $s = get_option(self::OPT_SETTINGS, []);
        $schedule = isset($s['schedule']) ? $s['schedule'] : 'monthly';
        $this->reschedule_cron($schedule);
        $this->schedule_cleanup_cron();
        $this->protect_csv_directory();
    }

    public function on_deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_HOOK);
        
        $timestamp = wp_next_scheduled(self::CRON_CLEANUP);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_CLEANUP);
    }

    private function reschedule_cron($freq) {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_HOOK);

        $valid = ['hourly','daily','weekly','monthly'];
        if (!in_array($freq, $valid, true)) $freq = 'monthly';
        
        wp_schedule_event(time() + 60, $freq, self::CRON_HOOK);
        $this->add_log("Cron omschemalagd till: $freq");
    }

    private function schedule_cleanup_cron() {
        if (!wp_next_scheduled(self::CRON_CLEANUP)) {
            wp_schedule_event(time() + 3600, 'daily', self::CRON_CLEANUP);
        }
    }

    private function protect_csv_directory() {
        [$csv_dir, $csv_baseurl] = $this->get_uploads_dir();
        $htaccess = trailingslashit($csv_dir) . '.htaccess';
        
        if (!file_exists($htaccess)) {
            $content = "# Contact Sync - Skydda CSV-filer\n";
            $content .= "Order Deny,Allow\n";
            $content .= "Deny from all\n";
            $result = @file_put_contents($htaccess, $content);
            if ($result === false) {
                $this->add_log('VARNING: Kunde inte skapa .htaccess - CSV-filerna kan vara publikt åtkomliga!');
            } else {
                $this->add_log('CSV-katalog skyddad med .htaccess');
            }
        }

        $index = trailingslashit($csv_dir) . 'index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden\n");
        }
    }

    public function run_digest() {
        $settings = get_option(self::OPT_SETTINGS, []);
        $send = ($settings['delivery'] ?? 'email') === 'email';
        $this->generate_and_maybe_send($send);
    }

    public function run_cleanup() {
        $cleaned_inbox = $this->cleanup_old_inbox_entries();
        $cleaned_csv = $this->cleanup_old_csv_files();
        $this->add_log("Städning klar: $cleaned_inbox inbox-poster och $cleaned_csv CSV-filer raderade.");
    }

    private function cleanup_old_inbox_entries() {
        $inbox = get_option(self::OPT_INBOX, []);
        $cutoff = current_time('timestamp') - (self::INBOX_CLEANUP_DAYS * DAY_IN_SECONDS);
        $original_count = count($inbox);

        $inbox = array_filter($inbox, function($row) use ($cutoff) {
            if (empty($row['exported'])) {
                return true;
            }
            $t = strtotime($row['submitted_at'] ?? '');
            return $t && $t > $cutoff;
        });

        $inbox = array_values($inbox);
        update_option(self::OPT_INBOX, $inbox, false);
        
        return $original_count - count($inbox);
    }

    private function cleanup_old_csv_files() {
        [$csv_dir, $csv_baseurl] = $this->get_uploads_dir();
        $csv_files = glob($csv_dir . '/*.csv') ?: [];
        $cutoff = current_time('timestamp') - (self::CSV_CLEANUP_DAYS * DAY_IN_SECONDS);
        $deleted = 0;

        foreach ($csv_files as $file) {
            if (filemtime($file) < $cutoff) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    public function capture_ninja_submission($form_data) {
        if (!function_exists('Ninja_Forms')) {
            $this->add_log('Ninja Forms ej aktivt vid submission.');
            return;
        }

        $inbox = get_option(self::OPT_INBOX, []);
        
        if (count($inbox) >= self::MAX_INBOX_SIZE) {
            $this->add_log('Inbox full (max ' . self::MAX_INBOX_SIZE . '). Submission ignorerad. Kör städning.');
            return;
        }

        $fields = [];
        if (!empty($form_data['fields'])) {
            $fields = $form_data['fields'];
        } elseif (!empty($form_data['fields_by_key'])) {
            $fields = array_values($form_data['fields_by_key']);
        }

        $map = $this->map_fields($fields);

        if (empty($map['email'])) {
            $this->add_log('Submission ignorerad: ingen giltig e-post hittades.');
            return;
        }

        $row = [
            'email'       => $map['email'],
            'first_name'  => $map['first_name'],
            'last_name'   => $map['last_name'],
            'company'     => $map['company'],
            'phone'       => $map['phone'],
            'message'     => $map['message'],
            'source_form' => isset($form_data['form_title']) ? sanitize_text_field($form_data['form_title']) : 'Ninja Form',
            'submitted_at'=> current_time('mysql'),
            'exported'    => false,
        ];

        $email_lower = strtolower($row['email']);
        $now = current_time('timestamp');
        
        foreach ($inbox as $it) {
            if (strtolower($it['email'] ?? '') === $email_lower) {
                $t = strtotime($it['submitted_at'] ?? '');
                if ($t && ($now - $t) < 172800) {
                    $this->add_log('Ignorerad dublett (inom 48h) för: ' . $row['email']);
                    return;
                }
            }
        }

        $inbox[] = $row;
        update_option(self::OPT_INBOX, $inbox, false);
        $this->add_log('Ny submission infångad: ' . $row['email'] . ' (' . $row['source_form'] . ')');
    }

    private function normalize_header($s) {
        $s = strtolower(trim((string)$s));
        $s = preg_replace('/\s+/', ' ', $s);
        return $s;
    }

    private function extract_email($raw, $allowed_tlds) {
        if (!$raw) return '';
        $text = trim((string)$raw);
        if (!$text) return '';
        
        if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
            if (preg_match('/[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)+/i', $text, $m)) {
                $text = $m[0];
            }
        }
        $email = strtolower($text);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return '';

        if (empty($allowed_tlds)) {
            $this->add_log("VARNING: Allowed TLDs är tom - ingen TLD-filtrering aktiv för: $email");
            return $email;
        }

        $parts = explode('.', substr(strrchr($email, '@'), 1));
        $tld = strtolower(end($parts));
        if ($tld && !in_array($tld, $allowed_tlds, true)) {
            $this->add_log("E-post ignorerad (otillåten TLD '$tld'): $email");
            return '';
        }
        return $email;
    }

    private function normalize_phone_se($raw) {
        if (!$raw) return '';
        $s = preg_replace('/[^\d+]/', '', (string)$raw);
        if ($s === '') return '';

        $s = preg_replace('/^\++/', '+', $s);

        if (preg_match('/^0\d{6,}$/', $s)) {
            $s = '+46' . substr($s, 1);
        }
        if (preg_match('/^46\d+$/', $s)) {
            $s = '+' . $s;
        }

        if (strpos($s, '+46') !== 0) return '';

        $s = '+' . preg_replace('/\D+/', '', substr($s, 1));

        $digits = substr($s, 1);
        $len = strlen($digits);
        if ($len < 9 || $len > 12) return '';
        if (preg_match('/(\d)\1{6,}/', $digits)) return '';

        return $s;
    }

    private function split_name($val) {
        $t = trim((string)$val);
        if ($t === '') return ['',''];
        $parts = preg_split('/\s+/', $t);
        if (count($parts) === 1) return [$parts[0], ''];
        $last = array_pop($parts);
        return [implode(' ', $parts), $last];
    }

    private function map_fields($fields) {
        $settings = get_option(self::OPT_SETTINGS, []);
        $allowed_tlds_str = $settings['allowed_tlds'] ?? 'se,com,info,nu';
        $allowed_tlds = array_filter(array_map('trim', explode(',', $allowed_tlds_str)));
        $allowed_tlds = array_map('strtolower', $allowed_tlds);

        $email = $first = $last = $company = $phone = $message = '';

        foreach ($fields as $f) {
            $label = isset($f['label']) ? $this->normalize_header($f['label']) : '';
            $key   = isset($f['key'])   ? $this->normalize_header($f['key'])   : '';
            $val   = isset($f['value']) ? $f['value'] : '';

            $lk = $label . ' ' . $key;

            if (!$email && preg_match('/\b(e-?post|email|e-?mail|mejl)\b/', $lk)) {
                $email = $this->extract_email($val, $allowed_tlds);
                continue;
            }

            if (!$first && preg_match('/\b(first|förnamn|given)\b/', $lk)) {
                $first = trim((string)$val);
                continue;
            }
            if (!$last && preg_match('/\b(last|efternamn|surname|family)\b/', $lk)) {
                $last = trim((string)$val);
                continue;
            }

            if (($first === '' && $last === '') && preg_match('/\b(namn|name)\b/', $lk)) {
                [$first, $last] = $this->split_name($val);
                continue;
            }

            if (!$company && preg_match('/\b(företag|company|bolag|organisation|org|brand|varumärke)\b/', $lk)) {
                $company = trim((string)$val);
                continue;
            }

            if (!$phone && preg_match('/\b(telefon|phone|mobil|tel|kontakt.*nummer)\b/', $lk)) {
                $phone = $this->normalize_phone_se($val);
                continue;
            }

            if (!$message && preg_match('/\b(message|meddelande|kommentar|ämne|subject|beskriv)\b/', $lk)) {
                $message = wp_strip_all_tags((string)$val);
                continue;
            }
        }

        return [
            'email'      => $email,
            'first_name' => $first,
            'last_name'  => $last,
            'company'    => $company,
            'phone'      => $phone,
            'message'    => $message,
        ];
    }

    private function get_uploads_dir() {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'csmc';
        if (!file_exists($dir)) wp_mkdir_p($dir);
        return [$dir, trailingslashit($uploads['baseurl']) . 'csmc'];
    }

    private function csv_safe($val) {
        $val = is_scalar($val) ? (string)$val : '';
        $val = str_replace(["\r\n", "\r"], "\n", $val);
        return $val;
    }

    private function build_csv_rows($inbox, $dedupe_mode) {
        $rows = array_filter($inbox, function ($r) {
            return empty($r['exported']);
        });

        $seen = [];
        $out = [];
        $emails_to_export = [];
        
        foreach ($rows as $r) {
            $email = strtolower($r['email'] ?? '');
            if ($email === '') continue;

            $key = $email;
            if ($dedupe_mode === 'email_phone') {
                $key .= '|' . ($r['phone'] ?? '');
            }
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $out[] = [
                'Email Address' => $this->csv_safe($email),
                'First Name'    => $this->csv_safe($r['first_name'] ?? ''),
                'Last Name'     => $this->csv_safe($r['last_name'] ?? ''),
                'Company'       => $this->csv_safe($r['company'] ?? ''),
                'Phone Number'  => $this->csv_safe($r['phone'] ?? ''),
                'Message'       => $this->csv_safe($r['message'] ?? ''),
                'Source Form'   => $this->csv_safe($r['source_form'] ?? ''),
                'Submitted At'  => $this->csv_safe($r['submitted_at'] ?? ''),
            ];
            
            $emails_to_export[] = $email;
        }

        return [$out, $emails_to_export];
    }

    private function mark_as_exported(&$inbox, $emails) {
        if (empty($emails)) return;
        
        foreach ($inbox as &$r) {
            if (in_array(strtolower($r['email'] ?? ''), $emails, true)) {
                $r['exported'] = true;
            }
        }
    }

    private function write_csv_file($rows) {
        if (empty($rows)) return [null, null];

        [$dir, $baseurl] = $this->get_uploads_dir();
        $filename = 'contacts-' . date('Y-m') . '-' . wp_generate_password(6, false) . '.csv';
        $path = trailingslashit($dir) . $filename;

        $fh = @fopen($path, 'w');
        if (!$fh) {
            $error = error_get_last();
            $this->add_log('Kunde inte skriva CSV-fil: ' . ($error['message'] ?? 'okänt fel'));
            return [null, null];
        }

        fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF));

        $headers = ['Email Address','First Name','Last Name','Company','Phone Number','Message','Source Form','Submitted At'];
        fputcsv($fh, $headers);

        foreach ($rows as $r) {
            fputcsv($fh, [
                $r['Email Address'] ?? '',
                $r['First Name'] ?? '',
                $r['Last Name'] ?? '',
                $r['Company'] ?? '',
                $r['Phone Number'] ?? '',
                $r['Message'] ?? '',
                $r['Source Form'] ?? '',
                $r['Submitted At'] ?? '',
            ]);
        }

        fclose($fh);
        $url = trailingslashit($baseurl) . $filename;
        return [$path, $url];
    }

    private function send_email_with_attachment($to, $subject, $path, $count) {
        if (!file_exists($path)) {
            $this->add_log('CSV-fil saknas för mejlutskick: ' . basename($path));
            return false;
        }

        if (empty($to) || !is_email($to)) {
            $this->add_log('Ogiltig mottagar-e-post för mejlutskick: ' . $to);
            return false;
        }

        $body  = "Hej!\n\n";
        $body .= "Här kommer CSV med " . $count . " nya kontakter.\n\n";
        $body .= "Fält: Email Address, First Name, Last Name, Company, Phone Number, Message, Source Form, Submitted At.\n";
        $body .= "\nVänligen,\nContact Sync\n";

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $ok = wp_mail($to, $subject, $body, $headers, [$path]);
        
        if (!$ok) {
            $this->add_log('wp_mail() misslyckades. Kontrollera SMTP-konfiguration och server-loggar.');
        }
        
        return (bool)$ok;
    }

    public function generate_and_maybe_send($should_send_email) {
        $s = get_option(self::OPT_SETTINGS, []);
        $inbox = get_option(self::OPT_INBOX, []);

        list($rows, $emails_to_export) = $this->build_csv_rows($inbox, $s['dedupe_mode'] ?? 'email');

        if (empty($rows)) {
            $this->add_log('Inget att exportera denna körning.');
            return 'empty';
        }

        list($path, $url) = $this->write_csv_file($rows);
        if (!$path) {
            $this->add_log('CSV-fil kunde inte skapas - ingen data markerad som exporterad.');
            return 'fail';
        }

        $count = count($rows);
        $this->add_log("CSV skapad ({$count} rader): " . basename($path));

        if ($should_send_email) {
            $ok = $this->send_email_with_attachment(
                $s['recipient_email'] ?? get_bloginfo('admin_email'),
                $s['email_subject']   ?? 'Månadens kontakter (CSV)',
                $path,
                $count
            );
            if ($ok) {
                $this->mark_as_exported($inbox, $emails_to_export);
                update_option(self::OPT_INBOX, $inbox, false);
                $this->add_log('E-post skickad med CSV-bilaga. ' . count($emails_to_export) . ' poster markerade som exporterade.');
                return 'ok';
            } else {
                $this->add_log('E-post kunde inte skickas (wp_mail returnerade false). Data behålls för nästa försök.');
                return 'fail';
            }
        } else {
            $this->mark_as_exported($inbox, $emails_to_export);
            update_option(self::OPT_INBOX, $inbox, false);
            $this->add_log('CSV tillgänglig för nedladdning via admin. ' . count($emails_to_export) . ' poster markerade som exporterade.');
            return 'ok';
        }
    }
}

new CSMC_Plugin();
