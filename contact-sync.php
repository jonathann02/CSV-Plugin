<?php
/**
 * Plugin Name: Contact Sync (Ninja Forms → CSV för Mailchimp)
 * Description: Samlar Ninja Forms-inlämningar (även historik via backfill), normaliserar data, förhindrar dubletter och levererar CSV (en fil eller två filer Privat/Företag) automatiskt (schemalagt) eller manuellt. Fält: Email Address, First Name, Last Name, Company, Phone Number, Message, Source Form, Submitted At.
 * Version: 2.3.0
 * Author: Jonathan
 * Text Domain: csmc
 */

if (!defined('ABSPATH')) exit;

class CSMC_Plugin {
  const OPT_SETTINGS        = 'csmc_settings';
  const OPT_INBOX           = 'csmc_inbox';
  const OPT_LOG             = 'csmc_log';

  const CRON_HOOK           = 'csmc_cron_digest';
  const CRON_CLEANUP        = 'csmc_cron_cleanup';

  const MAX_INBOX_SIZE      = 50000; // höjd för större historik
  const INBOX_CLEANUP_DAYS  = 90;
  const CSV_CLEANUP_DAYS    = 180;

  public function __construct() {
    add_action('plugins_loaded', [$this, 'maybe_set_defaults']);
    add_action('admin_menu',     [$this, 'admin_menu']);
    add_action('admin_init',     [$this, 'handle_admin_posts']);
    add_filter('cron_schedules', [$this, 'cron_schedules']);

    register_activation_hook(__FILE__,   [$this, 'on_activate']);
    register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);

    add_action(self::CRON_HOOK,    [$this, 'run_digest']);
    add_action(self::CRON_CLEANUP, [$this, 'run_cleanup']);

    // Fångar NYA NF-submissions efter aktivering
    add_action('ninja_forms_after_submission', [$this, 'capture_ninja_submission']);

    // Säker nedladdning av CSV via admin-post
    add_action('admin_post_csmc_download_csv', [$this, 'handle_csv_download']);
  }

  /* ----------------------- Setup & Settings ----------------------- */

  public function maybe_set_defaults() {
    $s = get_option(self::OPT_SETTINGS);
    if (!is_array($s)) $s = [];

    $defaults = [
      'recipient_email' => get_bloginfo('admin_email'),
      'email_subject'   => 'Kontakter (CSV)',
      'schedule'        => 'monthly',     // hourly|daily|weekly|monthly
      'delivery'        => 'email',       // email|link
      'dedupe_mode'     => 'email',       // email|email_phone
      'allowed_tlds'    => 'se,com,info,nu',
      // Nytt:
      'freemail_domains'=> 'gmail.com,outlook.com,hotmail.com,live.com,icloud.com,yahoo.com,proton.me,protonmail.com,gmx.com,mail.com',
      'split_mode'      => 'off',         // on = schemalagd export skapar 2 CSV (Privat/Företag)
    ];
    $merged = array_merge($defaults, $s);
    if ($merged !== $s) update_option(self::OPT_SETTINGS, $merged, false);

    if (!is_array(get_option(self::OPT_INBOX))) update_option(self::OPT_INBOX, [], false);
    if (!is_array(get_option(self::OPT_LOG)))   update_option(self::OPT_LOG,   [], false);
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
    if (count($log) > 500) $log = array_slice($log, -500);
    update_option(self::OPT_LOG, $log, false);
  }

  public function render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $s           = get_option(self::OPT_SETTINGS, []);
    $log         = get_option(self::OPT_LOG, []);
    $inbox       = get_option(self::OPT_INBOX, []);
    $inbox_count = count($inbox);
    $inbox_exp   = count(array_filter($inbox, fn($r)=>!empty($r['exported'])));
    $inbox_pend  = $inbox_count - $inbox_exp;

    [$csv_dir, $csv_baseurl] = $this->get_uploads_dir();
    $csv_files = glob($csv_dir.'/*.csv') ?: [];
    ?>
    <div class="wrap">
      <h1>Contact Sync</h1>
      <p>Samlar Ninja Forms-inlämningar (även historik via backfill), normaliserar fält, tar bort dubletter och levererar CSV. Du kan skapa
      <em>en</em> fil eller <em>två</em> filer (Privat/Företag). Schemakörning använder WP-Cron.</p>

      <h2 class="nav-tab-wrapper">
        <a href="<?php echo esc_url(admin_url('admin.php?page=csmc_settings')); ?>" class="nav-tab nav-tab-active">Inställningar</a>
      </h2>

      <?php if (isset($_GET['saved']) && $_GET['saved']==='1'): ?>
        <div class="notice notice-success"><p>Inställningarna sparades.</p></div>
      <?php elseif (isset($_GET['error']) && $_GET['error']==='invalid_email'): ?>
        <div class="notice notice-error"><p><strong>Fel:</strong> Ogiltig e-postadress för mottagare.</p></div>
      <?php endif; ?>

      <?php if (isset($_GET['digest'])): ?>
        <?php if ($_GET['digest']==='ok'): ?>
          <div class="notice notice-success"><p>Export klar.</p></div>
        <?php elseif ($_GET['digest']==='fail'): ?>
          <div class="notice notice-error"><p>Kunde inte skapa/skicka CSV. Se loggen.</p></div>
        <?php elseif ($_GET['digest']==='empty'): ?>
          <div class="notice notice-info"><p>Inga kontakter att exportera.</p></div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if (isset($_GET['cleanup']) && $_GET['cleanup']==='ok'): ?>
        <div class="notice notice-success"><p>Städning utförd.</p></div>
      <?php endif; ?>

      <?php if ($inbox_count > self::MAX_INBOX_SIZE * 0.8): ?>
        <div class="notice notice-warning"><p><strong>Varning:</strong> Inbox närmar sig maxgränsen (<?php echo $inbox_count; ?> / <?php echo self::MAX_INBOX_SIZE; ?>).</p></div>
      <?php endif; ?>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('csmc_save_settings', 'csmc_nonce'); ?>
        <input type="hidden" name="action" value="csmc_save_settings" />
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="recipient_email">Mottagar-e-post</label></th>
            <td><input type="email" id="recipient_email" name="recipient_email" class="regular-text" required
              value="<?php echo esc_attr($s['recipient_email'] ?? ''); ?>" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="email_subject">Ämnesrad</label></th>
            <td><input type="text" id="email_subject" name="email_subject" class="regular-text"
              value="<?php echo esc_attr($s['email_subject'] ?? ''); ?>" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="schedule">Schema</label></th>
            <td>
              <select id="schedule" name="schedule">
                <?php
                  $opts = ['hourly'=>'Varje timme (test)','daily'=>'Dagligen','weekly'=>'Veckovis','monthly'=>'Månatligen'];
                  foreach ($opts as $k=>$label) {
                    printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($s['schedule'] ?? 'monthly', $k, false), esc_html($label));
                  }
                ?>
              </select>
              <p class="description">I produktion rekommenderas riktig cron som anropar <code>wp-cron.php</code> var 5:e minut.</p>
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
                <option value="email"       <?php selected($s['dedupe_mode'] ?? 'email', 'email'); ?>>Unik per e-post</option>
                <option value="email_phone" <?php selected($s['dedupe_mode'] ?? 'email', 'email_phone'); ?>>Unik per e-post + telefon</option>
              </select>
              <p class="description">Gäller både inom körning och över tid (markeras exporterad).</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="allowed_tlds">Tillåtna e-post-TLDs</label></th>
            <td>
              <input type="text" id="allowed_tlds" name="allowed_tlds" class="regular-text"
                value="<?php echo esc_attr($s['allowed_tlds'] ?? 'se,com,info,nu'); ?>" />
              <p class="description">Kommaseparerat, t.ex. <code>se,com,info,nu</code>. Tomt fält = ingen TLD-filtrering.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="freemail_domains">Freemail-domäner (Privat)</label></th>
            <td>
              <input type="text" id="freemail_domains" name="freemail_domains" class="regular-text"
                value="<?php echo esc_attr($s['freemail_domains'] ?? ''); ?>" />
              <p class="description">Kommaseparerat, t.ex. <code>gmail.com,outlook.com,icloud.com,proton.me</code>. Används för att dela upp i Privat vs Företag.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="split_mode">Dela upp i Privat/Företag (schemalagd export)</label></th>
            <td>
              <label><input type="checkbox" id="split_mode" name="split_mode" value="on" <?php checked(($s['split_mode'] ?? 'off'),'on'); ?> />
              Aktivera split-läge för schemakörning</label>
              <p class="description">När detta är ikryssat skapar schemakörningen två filer (Privat & Företag). Manuella knappar finns oavsett.</p>
            </td>
          </tr>
        </table>
        <?php submit_button('Spara inställningar'); ?>
      </form>

      <hr/>

      <h2>Inbox-status</h2>
      <table class="widefat" style="max-width:600px;">
        <tbody>
          <tr><td><strong>Totalt antal poster:</strong></td><td><?php echo $inbox_count; ?> / <?php echo self::MAX_INBOX_SIZE; ?></td></tr>
          <tr><td><strong>Redan exporterade:</strong></td><td><?php echo $inbox_exp; ?></td></tr>
          <tr><td><strong>Väntar på export:</strong></td><td><?php echo $inbox_pend; ?></td></tr>
        </tbody>
      </table>
      <p class="description">
        <strong>Obs:</strong> “Generera CSV”/“Skicka nu” använder endast <em>oexporterade</em> poster.
        Använd “Exportera ALLA (ignorera exported)” för en full historikfil.
      </p>

      <h2>Åtgärder</h2>
      <p>
        <!-- Generera 1 CSV (oexporterade) -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px;">
          <?php wp_nonce_field('csmc_generate_now', 'csmc_nonce'); ?>
          <input type="hidden" name="action" value="csmc_generate_now"/>
          <button class="button">Generera CSV och visa länk (ingen e-post)</button>
        </form>

        <!-- Skicka 1 CSV (oexporterade) -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px;">
          <?php wp_nonce_field('csmc_send_now', 'csmc_nonce'); ?>
          <input type="hidden" name="action" value="csmc_send_now"/>
          <button class="button button-primary">Skicka CSV nu (e-post)</button>
        </form>

        <!-- Generera 2 CSV (split, oexporterade) -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px;">
          <?php wp_nonce_field('csmc_generate_split', 'csmc_nonce'); ?>
          <input type="hidden" name="action" value="csmc_generate_split"/>
          <button class="button">Generera 2 CSV (Privat/Företag)</button>
        </form>

        <!-- Skicka 2 CSV (split, oexporterade) -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px;">
          <?php wp_nonce_field('csmc_send_split_now', 'csmc_nonce'); ?>
          <input type="hidden" name="action" value="csmc_send_split_now"/>
          <button class="button button-primary">Skicka 2 CSV nu (e-post)</button>
        </form>

        <!-- Export ALL (ignorera exported) -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px;">
          <?php wp_nonce_field('csmc_export_all', 'csmc_nonce'); ?>
          <input type="hidden" name="action" value="csmc_export_all"/>
          <button class="button button-secondary">Exportera ALLA (ignorera exported)</button>
        </form>

        <!-- Backfill (historik) -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px;">
          <?php wp_nonce_field('csmc_backfill', 'csmc_nonce'); ?>
          <input type="hidden" name="action" value="csmc_backfill"/>
          <button class="button" onclick="return confirm('Importera alla sparade Ninja Forms-inlämningar till inbox?');">Importera historik (Backfill)</button>
        </form>

        <!-- Städning & Reset -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px;">
          <?php wp_nonce_field('csmc_cleanup', 'csmc_nonce'); ?>
          <input type="hidden" name="action" value="csmc_cleanup"/>
          <button class="button" onclick="return confirm('Städa gamla poster och CSV-filer?');">Kör städning nu</button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px;">
          <?php wp_nonce_field('csmc_reset_ledger', 'csmc_nonce'); ?>
          <input type="hidden" name="action" value="csmc_reset_ledger"/>
          <button class="button" onclick="return confirm('Nollställa exportmarkeringar? Tidigare skickade poster kan då komma med igen.');">Nollställ exportmarkeringar</button>
        </form>
      </p>

      <h2>Befintliga CSV-filer</h2>
      <p class="description">CSV-filerna är skyddade och kräver inloggning för nedladdning.</p>
      <?php if (empty($csv_files)): ?>
        <p>Inga CSV:er än.</p>
      <?php else: ?>
        <ul>
          <?php foreach (array_reverse($csv_files) as $f):
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
          endforeach; ?>
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

      <p class="description">
        <strong>Städning:</strong> export <em>markerade</em> inbox-poster rensas efter <?php echo intval(self::INBOX_CLEANUP_DAYS); ?> dagar.
        CSV-filer rensas efter <?php echo intval(self::CSV_CLEANUP_DAYS); ?> dagar.
      </p>
    </div>
    <?php
  }

  /* ----------------------- Admin actions ----------------------- */

  public function handle_csv_download() {
    if (!current_user_can('manage_options')) wp_die('Åtkomst nekad');
    check_admin_referer('csmc_download_csv');

    $file = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';
    if (empty($file) || strpos($file, '..') !== false) wp_die('Ogiltig fil');

    [$csv_dir] = $this->get_uploads_dir();
    $filepath = trailingslashit($csv_dir) . $file;
    if (!file_exists($filepath) || !is_file($filepath)) wp_die('Filen finns inte');

    $safe = str_replace(['"', "\n", "\r"], '', $file);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($filepath);
    exit;
  }

  public function handle_admin_posts() {
    if (!current_user_can('manage_options')) wp_die('Åtkomst nekad');

    // Save settings
    if (isset($_POST['action']) && $_POST['action'] === 'csmc_save_settings') {
      check_admin_referer('csmc_save_settings', 'csmc_nonce');
      $s = get_option(self::OPT_SETTINGS, []);
      $fields = ['email_subject','schedule','delivery','dedupe_mode','allowed_tlds','freemail_domains','split_mode'];
      foreach ($fields as $f) {
        if ($f==='split_mode') {
          $s[$f] = isset($_POST[$f]) && $_POST[$f]==='on' ? 'on' : 'off';
        } else {
          $s[$f] = isset($_POST[$f]) ? sanitize_text_field(wp_unslash($_POST[$f])) : '';
        }
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

    // Generera en CSV (oexporterade)
    if (isset($_POST['action']) && $_POST['action'] === 'csmc_generate_now') {
      check_admin_referer('csmc_generate_now', 'csmc_nonce');
      $r = $this->generate_single(false);
      wp_redirect(admin_url('admin.php?page=csmc_settings&digest='.$r));
      exit;
    }

    // Skicka en CSV nu (oexporterade)
    if (isset($_POST['action']) && $_POST['action'] === 'csmc_send_now') {
      check_admin_referer('csmc_send_now', 'csmc_nonce');
      $r = $this->generate_single(true);
      wp_redirect(admin_url('admin.php?page=csmc_settings&digest='.$r));
      exit;
    }

    // Generera två CSV (Privat/Företag, oexporterade)
    if (isset($_POST['action']) && $_POST['action'] === 'csmc_generate_split') {
      check_admin_referer('csmc_generate_split', 'csmc_nonce');
      $r = $this->generate_split(false);
      wp_redirect(admin_url('admin.php?page=csmc_settings&digest='.$r));
      exit;
    }

    // Skicka två CSV nu (Privat/Företag, oexporterade)
    if (isset($_POST['action']) && $_POST['action'] === 'csmc_send_split_now') {
      check_admin_referer('csmc_send_split_now', 'csmc_nonce');
      $r = $this->generate_split(true);
      wp_redirect(admin_url('admin.php?page=csmc_settings&digest='.$r));
      exit;
    }

    // Exportera ALLT (ignorera exported)
    if (isset($_POST['action']) && $_POST['action'] === 'csmc_export_all') {
      check_admin_referer('csmc_export_all', 'csmc_nonce');
      $r = $this->export_all_single();
      wp_redirect(admin_url('admin.php?page=csmc_settings&digest='.$r));
      exit;
    }

    // Backfill (historik)
    if (isset($_POST['action']) && $_POST['action'] === 'csmc_backfill') {
      check_admin_referer('csmc_backfill', 'csmc_nonce');
      [$added,$noemail,$dupes] = $this->backfill_from_ninjaforms();
      $this->add_log("Backfill klart: +$added, utan e-post: $noemail, dubletter: $dupes.");
      wp_redirect(admin_url('admin.php?page=csmc_settings'));
      exit;
    }

    // Städning och reset
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

  /* ----------------------- Cron & FS ----------------------- */

  public function cron_schedules($schedules) {
    if (!isset($schedules['weekly']))  $schedules['weekly']  = ['interval'=>7*DAY_IN_SECONDS,  'display'=>__('Once Weekly','csmc')];
    if (!isset($schedules['monthly'])) $schedules['monthly'] = ['interval'=>30*DAY_IN_SECONDS, 'display'=>__('Once Monthly (approx.)','csmc')];
    return $schedules;
  }

  public function on_activate() {
    $s = get_option(self::OPT_SETTINGS, []);
    $this->reschedule_cron($s['schedule'] ?? 'monthly');
    $this->schedule_cleanup_cron();
    $this->protect_csv_directory();
  }

  public function on_deactivate() {
    $t = wp_next_scheduled(self::CRON_HOOK);
    if ($t) wp_unschedule_event($t, self::CRON_HOOK);
    $t = wp_next_scheduled(self::CRON_CLEANUP);
    if ($t) wp_unschedule_event($t, self::CRON_CLEANUP);
  }

  private function reschedule_cron($freq) {
    $t = wp_next_scheduled(self::CRON_HOOK);
    if ($t) wp_unschedule_event($t, self::CRON_HOOK);
    $valid = ['hourly','daily','weekly','monthly'];
    if (!in_array($freq, $valid, true)) $freq = 'monthly';
    wp_schedule_event(time()+60, $freq, self::CRON_HOOK);
    $this->add_log("Cron omschemalagd till: $freq");
  }

  private function schedule_cleanup_cron() {
    if (!wp_next_scheduled(self::CRON_CLEANUP)) {
      wp_schedule_event(time()+3600, 'daily', self::CRON_CLEANUP);
    }
  }

  private function protect_csv_directory() {
    [$csv_dir] = $this->get_uploads_dir();
    $htaccess = trailingslashit($csv_dir) . '.htaccess';
    if (!file_exists($htaccess)) {
      $content = "Order Deny,Allow\nDeny from all\n";
      @file_put_contents($htaccess, $content);
    }
    $index = trailingslashit($csv_dir) . 'index.php';
    if (!file_exists($index)) @file_put_contents($index, "<?php\n// Silence is golden\n");
    $this->add_log('CSV-katalog skyddad (om möjligt).');
  }

  public function run_digest() {
    $s = get_option(self::OPT_SETTINGS, []);
    $send = (($s['delivery'] ?? 'email') === 'email');

    if (($s['split_mode'] ?? 'off') === 'on') {
      $this->generate_split($send);
    } else {
      $this->generate_single($send);
    }
  }

  public function run_cleanup() {
    $a = $this->cleanup_old_inbox_entries();
    $b = $this->cleanup_old_csv_files();
    $this->add_log("Städning klar: $a inbox-poster och $b CSV-filer raderade.");
  }

  private function cleanup_old_inbox_entries() {
    $inbox = get_option(self::OPT_INBOX, []);
    $cutoff = current_time('timestamp') - (self::INBOX_CLEANUP_DAYS * DAY_IN_SECONDS);
    $orig = count($inbox);
    $inbox = array_values(array_filter($inbox, function($row) use ($cutoff) {
      if (empty($row['exported'])) return true; // rör ej oexporterade
      $t = strtotime($row['submitted_at'] ?? '');
      return $t && $t > $cutoff;
    }));
    update_option(self::OPT_INBOX, $inbox, false);
    return $orig - count($inbox);
  }

  private function cleanup_old_csv_files() {
    [$csv_dir] = $this->get_uploads_dir();
    $files = glob($csv_dir.'/*.csv') ?: [];
    $cut = current_time('timestamp') - (self::CSV_CLEANUP_DAYS * DAY_IN_SECONDS);
    $del = 0;
    foreach ($files as $file) {
      if (filemtime($file) < $cut) { if (@unlink($file)) $del++; }
    }
    return $del;
  }

  /* ----------------------- Capture & Backfill ----------------------- */

  public function capture_ninja_submission($form_data) {
    if (!function_exists('Ninja_Forms')) { $this->add_log('Ninja Forms ej aktivt vid submission.'); return; }

    $inbox = get_option(self::OPT_INBOX, []);
    if (count($inbox) >= self::MAX_INBOX_SIZE) { $this->add_log('Inbox full. Submission ignorerad.'); return; }

    $fields = [];
    if (!empty($form_data['fields']))          $fields = $form_data['fields'];
    elseif (!empty($form_data['fields_by_key'])) $fields = array_values($form_data['fields_by_key']);

    $map = $this->map_fields($fields);
    if (empty($map['email'])) { $this->add_log('Submission ignorerad: ingen giltig e-post.'); return; }

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

    // 48h “spam”-dedupe på fångstnivå (kan justeras/avlägsnas vid behov)
    $email_lower = strtolower($row['email']);
    $now = current_time('timestamp');
    foreach ($inbox as $it) {
      if (strtolower($it['email'] ?? '') === $email_lower) {
        $t = strtotime($it['submitted_at'] ?? '');
        if ($t && ($now - $t) < 172800) { // 48h
          $this->add_log('Ignorerad dublett (inom 48h): ' . $row['email']);
          return;
        }
      }
    }

    $inbox[] = $row;
    update_option(self::OPT_INBOX, $inbox, false);
    $this->add_log('Ny submission infångad: ' . $row['email'] . ' (' . $row['source_form'] . ')');
  }

  private function backfill_from_ninjaforms() {
    if (!function_exists('Ninja_Forms')) { $this->add_log('Backfill: Ninja Forms ej aktivt.'); return [0,0,0]; }
    if (function_exists('set_time_limit')) @set_time_limit(300);

    $inbox     = get_option(self::OPT_INBOX, []);
    $settings  = get_option(self::OPT_SETTINGS, []);
    $dedupe    = $settings['dedupe_mode'] ?? 'email';

    $added = 0; $noemail = 0; $dupes = 0;

    // Snabbt index över redan existerande nycklar
    $seen = [];
    foreach ($inbox as $row) {
      $k = strtolower($row['email'] ?? '');
      if ($dedupe === 'email_phone') $k .= '|' . ($row['phone'] ?? '');
      if ($k !== '') $seen[$k] = true;
    }

    // Hämta formulärlistan
    $forms = [];
    try {
      $forms_api = Ninja_Forms()->form();
      if (is_object($forms_api) && method_exists($forms_api, 'get_forms')) {
        $forms = $forms_api->get_forms();
      }
    } catch (\Throwable $e) {}

    if (empty($forms)) { $this->add_log('Backfill: Hittade inga formulär.'); return [0,0,0]; }

    foreach ($forms as $form) {
      $form_id = method_exists($form,'get_id') ? (int)$form->get_id() : 0;
      if (!$form_id) continue;
      $form_title = method_exists($form,'get_setting') ? (string)$form->get_setting('title') : 'Ninja Form';

      $subs = [];
      try { $subs = Ninja_Forms()->form($form_id)->get_subs(); } catch (\Throwable $e) {}

      if (empty($subs)) continue;

      foreach ($subs as $sub) {
        if (count($inbox) >= self::MAX_INBOX_SIZE) { $this->add_log('Backfill: Inbox full – avbröt.'); break 2; }

        $values = method_exists($sub,'get_field_values') ? (array)$sub->get_field_values() : [];
        $fields = [];
        foreach ($values as $key => $val) {
          if (is_array($val)) $val = implode(', ', array_map('strval', $val));
          $fields[] = ['label'=>'', 'key'=>(string)$key, 'value'=>$val];
        }

        $map = $this->map_fields($fields);
        if (empty($map['email'])) { $noemail++; continue; }

        $k = strtolower($map['email']);
        if ($dedupe === 'email_phone') $k .= '|' . ($map['phone'] ?? '');
        if (isset($seen[$k])) { $dupes++; continue; }

        $sub_date = current_time('mysql');
        if (method_exists($sub,'get_sub_date')) {
          $d = $sub->get_sub_date('Y-m-d H:i:s');
          if ($d) $sub_date = $d;
        } elseif (method_exists($sub,'get_date_submitted')) {
          $d = $sub->get_date_submitted('Y-m-d H:i:s');
          if ($d) $sub_date = $d;
        }

        $inbox[] = [
          'email'       => $map['email'],
          'first_name'  => $map['first_name'],
          'last_name'   => $map['last_name'],
          'company'     => $map['company'],
          'phone'       => $map['phone'],
          'message'     => $map['message'],
          'source_form' => $form_title,
          'submitted_at'=> $sub_date,
          'exported'    => false,
        ];
        $seen[$k] = true; $added++;
      }
    }

    update_option(self::OPT_INBOX, $inbox, false);
    return [$added,$noemail,$dupes];
  }

  /* ----------------------- Mapping & Helpers ----------------------- */

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
      if (preg_match('/[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)+/i', $text, $m)) $text = $m[0];
    }
    $email = strtolower($text);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return '';

    if (empty($allowed_tlds)) return $email; // ingen filtrering

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
    if ($s==='') return '';
    $s = preg_replace('/^\++/', '+', $s);
    if (preg_match('/^0\d{6,}$/', $s)) $s = '+46' . substr($s,1);
    if (preg_match('/^46\d+$/', $s))  $s = '+' . $s;
    if (strpos($s,'+46')!==0) return '';
    $s = '+' . preg_replace('/\D+/', '', substr($s,1));
    $digits = substr($s,1);
    $len = strlen($digits);
    if ($len < 9 || $len > 12) return '';
    if (preg_match('/(\d)\1{6,}/', $digits)) return '';
    return $s;
  }

  private function split_name($val) {
    $t = trim((string)$val);
    if ($t==='') return ['',''];
    $parts = preg_split('/\s+/', $t);
    if (count($parts)===1) return [$parts[0],''];
    $last = array_pop($parts);
    return [implode(' ', $parts), $last];
  }

  private function map_fields($fields) {
    $settings = get_option(self::OPT_SETTINGS, []);
    $allowed_tlds_str = $settings['allowed_tlds'] ?? 'se,com,info,nu';
    $allowed_tlds = array_map('strtolower', array_filter(array_map('trim', explode(',', $allowed_tlds_str))));

    $email = $first = $last = $company = $phone = $message = '';

    foreach ($fields as $f) {
      $label = isset($f['label']) ? $this->normalize_header($f['label']) : '';
      $key   = isset($f['key'])   ? $this->normalize_header($f['key'])   : '';
      $val   = isset($f['value']) ? $f['value'] : '';
      $lk = $label . ' ' . $key;

      if (!$email && preg_match('/\b(e-?post|email|e-?mail|mejl)\b/', $lk)) {
        $email = $this->extract_email($val, $allowed_tlds); continue;
      }

      if (!$first && preg_match('/\b(first|förnamn|given)\b/', $lk)) { $first = trim((string)$val); continue; }
      if (!$last  && preg_match('/\b(last|efternamn|surname|family)\b/', $lk)) { $last = trim((string)$val); continue; }

      if (($first==='' && $last==='') && preg_match('/\b(namn|name)\b/', $lk)) {
        [$first, $last] = $this->split_name($val); continue;
      }

      if (!$company && preg_match('/\b(företag|company|bolag|organisation|org|brand|varumärke)\b/', $lk)) { $company = trim((string)$val); continue; }

      if (!$phone && preg_match('/\b(telefon|phone|mobil|tel|kontakt.*nummer)\b/', $lk)) { $phone = $this->normalize_phone_se($val); continue; }

      if (!$message && preg_match('/\b(message|meddelande|kommentar|ämne|subject|beskriv)\b/', $lk)) { $message = wp_strip_all_tags((string)$val); continue; }
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
    $val = str_replace(["\r\n","\r"], "\n", $val);
    return $val;
  }

  /* ----------------------- Build rows & files ----------------------- */

  private function build_rows($inbox, $dedupe_mode, $include_exported=false) {
    $rows = array_filter($inbox, function($r) use ($include_exported) {
      return $include_exported ? true : empty($r['exported']);
    });

    $seen = []; $out = []; $emails = [];
    foreach ($rows as $r) {
      $email = strtolower($r['email'] ?? ''); if ($email==='') continue;
      $key = $email;
      if ($dedupe_mode==='email_phone') $key .= '|' . ($r['phone'] ?? '');
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
      $emails[] = $email;
    }
    return [$out, $emails];
  }

  private function build_split_rows($inbox, $dedupe_mode, $freemail_csv, $include_exported=false) {
    $rows = array_filter($inbox, function($r) use ($include_exported) {
      return $include_exported ? true : empty($r['exported']);
    });

    $freemails = array_filter(array_map('strtolower', array_map('trim', explode(',', (string)$freemail_csv))));
    $seen = []; $priv = []; $corp = [];
    foreach ($rows as $r) {
      $email = strtolower($r['email'] ?? ''); if ($email==='') continue;
      $key = $email;
      if ($dedupe_mode==='email_phone') $key .= '|' . ($r['phone'] ?? '');
      if (isset($seen[$key])) continue;
      $seen[$key] = true;

      $row = [
        'Email Address' => $this->csv_safe($email),
        'First Name'    => $this->csv_safe($r['first_name'] ?? ''),
        'Last Name'     => $this->csv_safe($r['last_name'] ?? ''),
        'Company'       => $this->csv_safe($r['company'] ?? ''),
        'Phone Number'  => $this->csv_safe($r['phone'] ?? ''),
        'Message'       => $this->csv_safe($r['message'] ?? ''),
        'Source Form'   => $this->csv_safe($r['source_form'] ?? ''),
        'Submitted At'  => $this->csv_safe($r['submitted_at'] ?? ''),
      ];

      $domain = substr(strrchr($email, '@'), 1);
      if ($domain && in_array(strtolower($domain), $freemails, true)) $priv[] = $row; else $corp[] = $row;
    }

    $emails = array_unique(array_merge(array_column($priv,'Email Address'), array_column($corp,'Email Address')));
    return [$priv, $corp, $emails];
  }

  private function write_csv_file($rows, $suffix='') {
    if (empty($rows)) return [null, null];

    [$dir, $baseurl] = $this->get_uploads_dir();
    $filename = 'contacts-' . date('Y-m') . $suffix . '-' . wp_generate_password(6, false) . '.csv';
    $path = trailingslashit($dir) . $filename;

    $fh = @fopen($path, 'w');
    if (!$fh) { $error = error_get_last(); $this->add_log('Kunde inte skriva CSV: ' . ($error['message'] ?? 'okänt fel')); return [null, null]; }

    // BOM för Excel
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
    if (!file_exists($path)) { $this->add_log('CSV saknas för mejl: ' . basename($path)); return false; }
    if (empty($to) || !is_email($to)) { $this->add_log('Ogiltig mottagare: ' . $to); return false; }

    $body  = "Hej!\n\nHär kommer CSV med $count kontakter.\n\n";
    $body .= "Fält: Email Address, First Name, Last Name, Company, Phone Number, Message, Source Form, Submitted At.\n\nVänligen,\nContact Sync\n";
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    $ok = wp_mail($to, $subject, $body, $headers, [$path]);
    if (!$ok) $this->add_log('wp_mail() misslyckades (1 bilaga).');
    return (bool)$ok;
  }

  private function send_email_with_attachments($to, $subject, $paths, $counts_text='') {
    $paths = array_values(array_filter($paths, 'file_exists'));
    if (empty($paths)) { $this->add_log('Inga bilagor att skicka.'); return false; }
    if (empty($to) || !is_email($to)) { $this->add_log('Ogiltig mottagare: ' . $to); return false; }

    $body  = "Hej!\n\nHär kommer CSV-filerna " . ($counts_text ? "($counts_text)" : "") . ".\n\n";
    $body .= "Fält: Email Address, First Name, Last Name, Company, Phone Number, Message, Source Form, Submitted At.\n\nVänligen,\nContact Sync\n";
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    $ok = wp_mail($to, $subject, $body, $headers, $paths);
    if (!$ok) $this->add_log('wp_mail() misslyckades (flera bilagor).');
    return (bool)$ok;
  }

  private function mark_as_exported(&$inbox, $emails_lower) {
    if (empty($emails_lower)) return;
    $set = array_flip(array_map('strtolower', $emails_lower));
    foreach ($inbox as &$r) {
      if (isset($set[strtolower($r['email'] ?? '')])) $r['exported'] = true;
    }
  }

  /* ----------------------- Export flows ----------------------- */

  // En CSV, oexporterade
  private function generate_single($should_send_email) {
    $s = get_option(self::OPT_SETTINGS, []);
    $inbox = get_option(self::OPT_INBOX, []);
    [$rows, $emails] = $this->build_rows($inbox, $s['dedupe_mode'] ?? 'email', false);

    if (empty($rows)) { $this->add_log('Inget att exportera.'); return 'empty'; }

    [$path, $url] = $this->write_csv_file($rows);
    if (!$path) { $this->add_log('Kunde inte skapa CSV.'); return 'fail'; }
    $count = count($rows);
    $this->add_log("CSV skapad ($count rader): " . basename($path));

    if ($should_send_email) {
      $ok = $this->send_email_with_attachment(
        $s['recipient_email'] ?? get_bloginfo('admin_email'),
        $s['email_subject']   ?? 'Kontakter (CSV)',
        $path,
        $count
      );
      if ($ok) {
        $this->mark_as_exported($inbox, $emails);
        update_option(self::OPT_INBOX, $inbox, false);
        $this->add_log('E-post skickad. ' . count($emails) . ' poster markerade som exporterade.');
        return 'ok';
      }
      $this->add_log('E-post misslyckades. Data lämnas oexporterad.');
      return 'fail';
    } else {
      $this->mark_as_exported($inbox, $emails);
      update_option(self::OPT_INBOX, $inbox, false);
      $this->add_log('CSV tillgänglig via admin. ' . count($emails) . ' poster markerade som exporterade.');
      return 'ok';
    }
  }

  // Två CSV (Privat/Företag), oexporterade
  private function generate_split($should_send_email) {
    $s = get_option(self::OPT_SETTINGS, []);
    $inbox = get_option(self::OPT_INBOX, []);
    [$priv, $corp, $emails] = $this->build_split_rows($inbox, $s['dedupe_mode'] ?? 'email', $s['freemail_domains'] ?? '', false);

    if (empty($priv) && empty($corp)) { $this->add_log('Inget att exportera (split).'); return 'empty'; }

    [$p1] = $this->write_csv_file($priv, '-private');
    [$p2] = $this->write_csv_file($corp, '-company');
    $c1 = count($priv); $c2 = count($corp);
    $this->add_log('CSV (Privat/Företag) skapade: ' . ($p1?basename($p1):'–') . " ($c1) / " . ($p2?basename($p2):'–') . " ($c2)");

    if ($should_send_email) {
      $ok = $this->send_email_with_attachments(
        $s['recipient_email'] ?? get_bloginfo('admin_email'),
        ($s['email_subject'] ?? 'Kontakter (CSV)') . ' – Privat/Företag',
        array_filter([$p1,$p2]),
        "Privat: $c1, Företag: $c2"
      );
      if ($ok) {
        $this->mark_as_exported($inbox, $emails);
        update_option(self::OPT_INBOX, $inbox, false);
        $this->add_log('E-post skickad (2 bilagor). ' . count($emails) . ' poster markerade som exporterade.');
        return 'ok';
      }
      $this->add_log('E-post misslyckades (split). Data lämnas oexporterad.');
      return 'fail';
    } else {
      $this->mark_as_exported($inbox, $emails);
      update_option(self::OPT_INBOX, $inbox, false);
      $this->add_log('CSV (split) tillgängliga i admin. ' . count($emails) . ' poster markerade som exporterade.');
      return 'ok';
    }
  }

  // Export ALL (ignorera exported) – en CSV
  private function export_all_single() {
    $s = get_option(self::OPT_SETTINGS, []);
    $inbox = get_option(self::OPT_INBOX, []);
    [$rows] = $this->build_rows($inbox, $s['dedupe_mode'] ?? 'email', true); // include_exported = true
    if (empty($rows)) { $this->add_log('Inga poster i inbox (ALL).'); return 'empty'; }
    [$path] = $this->write_csv_file($rows, '-ALL');
    if (!$path) return 'fail';
    $this->add_log('CSV (ALL) skapad: ' . basename($path) . ' (' . count($rows) . ' rader)');
    return 'ok';
  }

  /* ----------------------- Utils ----------------------- */

  private function get_setting($key, $default='') {
    $s = get_option(self::OPT_SETTINGS, []);
    return isset($s[$key]) ? $s[$key] : $default;
  }
}

new CSMC_Plugin();

