<?php
// CRON — starts the scheduled messages that are due, then sends the next
// batch of queued e-mails (manual campaigns and feed digests), respecting
// batch size, delay between e-mails and hourly/daily limits (Admin > Settings).
//
// cPanel > Cron Jobs, every 5 minutes:
//   */5 * * * * php /home/YOUR_USER/notifyme/cron/send-queue.php >/dev/null 2>&1
//
// Options:
//   --batches=N    send up to N batches in this run (default 1)
//   -v             print what happens
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require dirname(__DIR__) . '/bootstrap.php';
nm_require_ready();
@set_time_limit(0);

$args = array_slice($argv, 1);
$verbose = in_array('-v', $args, true);
$batches = 1;
foreach ($args as $a) {
    if (preg_match('/^--batches=(\d+)$/', $a, $m)) {
        $batches = max(1, min(100, (int) $m[1]));
    }
}

setting_set('cron_last_queue', (string) nm_now());
Queue::startDueScheduled();
$total = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
for ($i = 0; $i < $batches; $i++) {
    if (Queue::remaining() === 0) {
        break;
    }
    // 240 s budget: finishes before the next run of a 5-minute cron.
    $r = Queue::process(setting_int('batch_size', 1, 500), 240);
    foreach ($total as $k => $_) {
        $total[$k] += $r[$k];
    }
    if ($verbose) {
        printf("Batch %d — sent: %d, failed: %d, skipped: %d, remaining: %d%s\n", $i + 1, $r['sent'], $r['failed'], $r['skipped'], $r['remaining'],
            $r['busy'] ? ' (another process is sending)' : ($r['throttled'] ? ($r['host_limit'] !== '' ? ' (paused: SMTP server sending limit — ' . $r['host_limit'] . ')' : ' (hourly/daily limit reached)') : ($r['smtp_error'] ? ' SMTP: ' . $r['smtp_error'] : '')));
    }
    if ($r['busy'] || $r['throttled'] || $r['smtp_error'] !== '') {
        break;
    }
}
if ($total['sent'] + $total['failed'] > 0) {
    nm_log('cron', sprintf('send-queue: sent %d, failed %d, skipped %d', $total['sent'], $total['failed'], $total['skipped']));
}
db_housekeeping();
