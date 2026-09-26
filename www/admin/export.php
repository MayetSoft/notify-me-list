<?php
/**
 * Admin: CSV export of subscribers with their consent trail and lists.
 */
require __DIR__ . '/../_boot.php';
nm_require_ready();
nm_require_admin();

/** Neutralises spreadsheet formulas (CSV injection). */
function nm_csv_cell($v): string
{
    $v = (string) $v;
    if ($v !== '' && strpos('=+-@' . "\t\r", $v[0]) !== false) {
        $v = "'" . $v;
    }
    return $v;
}

if (nm_get('download') === '1') {
    $delim = nm_get('delimiter') === 'comma' ? ',' : ';';
    $includePending = nm_get('pending') === '1';
    $feedNames = [];
    foreach (db_all('SELECT sf.subscriber_id, f.name FROM subscriber_feeds sf JOIN feeds f ON f.id = sf.feed_id ORDER BY f.name') as $r) {
        $feedNames[$r['subscriber_id']][] = $r['name'];
    }
    $filename = 'subscribers-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM: lets Excel detect UTF-8
    fputcsv($out, [
        t('field.email'), t('field.status'), t('field.signup_date'), t('field.confirmed_date'), t('field.ip'),
        t('field.consent_text'), t('field.source'), nm_general_label(), t('field.feeds'), t('field.signup_lists'),
    ], $delim, '"', '\\');
    $st = db()->prepare('SELECT * FROM subscribers' . ($includePending ? '' : " WHERE status = 'active'") . ' ORDER BY id');
    $st->execute();
    while ($s = $st->fetch()) {
        fputcsv($out, array_map('nm_csv_cell', [
            $s['email'],
            t('status.' . $s['status']),
            date('Y-m-d H:i:s', (int) $s['created_at']),
            $s['confirmed_at'] ? date('Y-m-d H:i:s', (int) $s['confirmed_at']) : '',
            $s['ip'],
            $s['consent_text'],
            t('source.' . $s['source']),
            (int) $s['general_list'] === 1 ? t('common.yes') : t('common.no'),
            isset($feedNames[$s['id']]) ? implode(' | ', $feedNames[$s['id']]) : '',
            $s['signup_lists'],
        ]), $delim, '"', '\\');
    }
    fclose($out);
    nm_log('app', 'CSV export downloaded by admin');
    exit;
}

$stats = Subscribers::stats();
nm_layout_start('admin', t('nav.export'), 'export');
?>
<h1><?= e(t('nav.export')) ?></h1>
<p class="muted"><?= e(t('export.intro', ['n' => $stats['active']])) ?></p>
<form method="get" action="<?= e(nm_link('admin/export.php')) ?>" class="form card">
  <input type="hidden" name="download" value="1">
  <label for="delimiter"><?= e(t('export.delimiter')) ?></label>
  <select id="delimiter" name="delimiter">
    <option value="semicolon"><?= e(t('export.delimiter_semicolon')) ?></option>
    <option value="comma"><?= e(t('export.delimiter_comma')) ?></option>
  </select>
  <label class="check"><input type="checkbox" name="pending" value="1"> <span><?= e(t('export.include_pending', ['n' => $stats['pending']])) ?></span></label>
  <button type="submit" class="btn btn-primary"><?= e(t('export.submit')) ?></button>
  <p class="help"><?= e(t('export.help')) ?></p>
</form>
<?php
nm_layout_end();
