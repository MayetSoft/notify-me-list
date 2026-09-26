<?php
/**
 * Admin AJAX endpoint: processes one chunk of the sending queue and returns
 * the progress as JSON. Called repeatedly by the campaign page, so a big
 * campaign never depends on one long request.
 */
require __DIR__ . '/../_boot.php';
nm_require_ready();
nm_require_admin(true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!nm_is_post()) {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}
csrf_check();

$action = nm_post('action');
$cid = (int) nm_post('campaign');

if ($action === 'process') {
    // Stay well below max_execution_time (usually 30 s on shared hosting).
    $limit = (int) ini_get('max_execution_time');
    $budget = $limit > 0 ? max(5, min(25, $limit - 5)) : 25;
    @set_time_limit($budget + 30);
    $result = Queue::process(setting_int('batch_size', 1, 500), $budget, $cid > 0 ? $cid : null);
    echo json_encode([
        'result' => $result,
        'progress' => $cid > 0 ? Queue::progress($cid) : null,
        'messages' => [
            'busy' => t('campaign.js_busy'),
            'throttled' => t('campaign.js_throttled'),
            'smtp_error' => $result['smtp_error'] !== '' ? t('campaign.js_smtp_error', ['error' => $result['smtp_error']]) : '',
        ],
    ]);
    exit;
}

if ($action === 'progress' && $cid > 0) {
    echo json_encode(['progress' => Queue::progress($cid)]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
