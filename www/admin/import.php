<?php
/**
 * Admin: CSV import. Imported people are active immediately; their consent
 * field records explicitly that they were imported by the admin, when, and
 * the note typed here, so the audit trail stays honest.
 */
require __DIR__ . '/../_boot.php';
nm_require_ready();
nm_require_admin();

/** Extracts e-mail addresses from CSV text. Returns [line number => email]. */
function nm_import_parse(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $csv = str_replace(["\r\n", "\r"], "\n", $csv);
    $lines = explode("\n", $csv);
    $first = '';
    foreach ($lines as $l) {
        if (trim($l) !== '') {
            $first = $l;
            break;
        }
    }
    // Delimiter: the most frequent of ; , TAB on the first line (French Excel uses ;).
    $delims = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
    arsort($delims);
    $delim = (string) key($delims);
    if (reset($delims) === 0) {
        $delim = ',';
    }
    $col = null;
    $out = [];
    $n = 0;
    foreach ($lines as $line) {
        $n++;
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line, $delim, '"', '\\');
        if ($col === null) {
            // Header row?
            foreach ($cells as $i => $c) {
                if (in_array(mb_strtolower(trim($c)), ['email', 'e-mail', 'mail', 'courriel', 'adresse e-mail', 'adresse email', 'email address'], true)) {
                    $col = $i;
                    continue 2;
                }
            }
            // No header: first cell that looks like an address.
            foreach ($cells as $i => $c) {
                if (strpos($c, '@') !== false) {
                    $col = $i;
                    break;
                }
            }
            if ($col === null) {
                $col = 0;
            }
        }
        $out[$n] = isset($cells[$col]) ? trim($cells[$col], " \t\"'<>") : '';
    }
    return $out;
}

$feeds = Feeds::all();
$errors = [];
$result = null;
$v = ['general' => true, 'feeds' => [], 'note' => '', 'paste' => ''];

if (nm_is_post()) {
    csrf_check();
    $v['general'] = nm_post('general') === '1';
    $v['feeds'] = nm_post_ids('feeds');
    $v['note'] = mb_substr(nm_post('note'), 0, 300);
    $v['paste'] = isset($_POST['paste']) && is_string($_POST['paste']) ? $_POST['paste'] : '';
    $csv = '';
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        if ($_FILES['file']['size'] > 5 * 1024 * 1024) {
            $errors[] = t('import.err_too_big');
        } else {
            $csv = (string) file_get_contents($_FILES['file']['tmp_name']);
        }
    } elseif (!empty($_FILES['file']['name']) && $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = t('import.err_upload');
    }
    if ($csv === '') {
        $csv = $v['paste'];
    }
    if (trim($csv) === '' && !$errors) {
        $errors[] = t('import.err_empty');
    }
    if (!$v['general'] && !$v['feeds']) {
        $errors[] = t('signup.err_no_list');
    }
    if (nm_post('attest') !== '1') {
        $errors[] = t('import.err_attest');
    }
    if (!$errors) {
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        $result = Subscribers::import(nm_import_parse($csv), $v['general'], $v['feeds'], $v['note']);
        $v['paste'] = '';
    }
}

nm_layout_start('admin', t('nav.import'), 'import');
?>
<h1><?= e(t('nav.import')) ?></h1>
<p class="muted"><?= e(t('import.intro')) ?></p>

<?php if ($errors): ?>
  <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($result): ?>
  <div class="alert alert-success"><?= e(t('import.result', ['added' => $result['added'], 'updated' => $result['updated'], 'invalid' => count($result['invalid']), 'dup' => $result['duplicates']])) ?></div>
  <?php if ($result['invalid']): ?>
    <details class="card"><summary><?= e(t('import.invalid_lines')) ?></summary>
      <ul class="small"><?php foreach (array_slice($result['invalid'], 0, 200) as $inv): ?><li><?= e(t('import.line', ['n' => $inv[0]])) ?> : <?= e($inv[1]) ?></li><?php endforeach; ?></ul>
    </details>
  <?php endif; ?>
<?php endif; ?>

<form method="post" action="<?= e(nm_link('admin/import.php')) ?>" enctype="multipart/form-data" class="form card">
  <?= csrf_field() ?>
  <label for="file"><?= e(t('import.file')) ?></label>
  <input id="file" name="file" type="file" accept=".csv,.txt,text/csv,text/plain">
  <p class="help"><?= e(t('import.file_help')) ?></p>

  <label for="paste"><?= e(t('import.paste')) ?></label>
  <textarea id="paste" name="paste" rows="6" class="mono" placeholder="alice@example.com&#10;bob@example.org"><?= e($v['paste']) ?></textarea>

  <fieldset>
    <legend><?= e(t('import.target')) ?></legend>
    <label class="check"><input type="checkbox" name="general" value="1"<?= $v['general'] ? ' checked' : '' ?>> <span><?= e(nm_general_label()) ?></span></label>
    <?php foreach ($feeds as $f): ?>
      <label class="check"><input type="checkbox" name="feeds[]" value="<?= (int) $f['id'] ?>"<?= in_array((int) $f['id'], $v['feeds'], true) ? ' checked' : '' ?>> <span><?= e($f['name']) ?></span></label>
    <?php endforeach; ?>
  </fieldset>

  <label for="note"><?= e(t('import.note')) ?></label>
  <input id="note" name="note" maxlength="300" value="<?= e($v['note']) ?>" placeholder="<?= e(t('import.note_placeholder')) ?>">
  <p class="help"><?= e(t('import.note_help', ['example' => t('import.consent_text', ['date' => nm_format_date(nm_now()), 'admin' => (string) setting('admin_email')])])) ?></p>

  <label class="check"><input type="checkbox" name="attest" value="1" required> <span><?= e(t('import.attest')) ?></span></label>

  <button type="submit" class="btn btn-primary"><?= e(t('import.submit')) ?></button>
</form>
<?php
nm_layout_end();
