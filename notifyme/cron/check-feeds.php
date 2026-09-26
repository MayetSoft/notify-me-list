<?php
// CRON — checks the RSS/Atom feeds that are due, queues one digest per
// subscriber for the new items, then sends one batch of the queue.
//
// cPanel > Cron Jobs, every 15 minutes:
//   */15 * * * * php /home/YOUR_USER/notifyme/cron/check-feeds.php >/dev/null 2>&1
//
// Options:
//   --force        check every active feed now, ignoring their frequency
//   --feed=ID      check only this feed
//   --no-send      only queue, do not send (let send-queue.php do it)
//   -v             print what happens
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require dirname(__DIR__) . '/bootstrap.php';
nm_require_ready();
@set_time_limit(0);

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$noSend = in_array('--no-send', $args, true);
$verbose = in_array('-v', $args, true);
$onlyId = null;
foreach ($args as $a) {
    if (preg_match('/^--feed=(\d+)$/', $a, $m)) {
        $onlyId = (int) $m[1];
    }
}

$report = Feeds::checkDue($force, $onlyId);
setting_set('cron_last_feeds', (string) nm_now());
$summary = sprintf('Feeds checked: %d, errors: %d, new items: %d%s',
    $report['checked'], $report['errors'], $report['new_items'],
    $report['campaign_id'] ? ', digest campaign #' . $report['campaign_id'] : '');
if ($report['checked'] > 0) {
    nm_log('cron', 'check-feeds: ' . $summary);
}
if ($verbose) {
    foreach ($report['lines'] as $l) {
        echo ' - ' . $l . PHP_EOL;
    }
    echo $summary . PHP_EOL;
}

// Send a first batch right away (same function and limits as send-queue.php).
if (!$noSend && Queue::remaining() > 0) {
    $r = Queue::process(setting_int('batch_size', 1, 500), 240);
    setting_set('cron_last_queue', (string) nm_now());
    if ($verbose) {
        printf("Sent: %d, failed: %d, skipped: %d, remaining: %d%s\n", $r['sent'], $r['failed'], $r['skipped'], $r['remaining'],
            $r['busy'] ? ' (another process is sending)' : ($r['throttled'] ? ($r['host_limit'] !== '' ? ' (paused: SMTP server sending limit — ' . $r['host_limit'] . ')' : ' (hourly/daily limit reached)') : ($r['smtp_error'] ? ' SMTP: ' . $r['smtp_error'] : '')));
    }
}

db_housekeeping();
exit($report['errors'] > 0 && $report['errors'] === $report['checked'] ? 2 : 0);
