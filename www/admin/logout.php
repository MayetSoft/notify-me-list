<?php
require __DIR__ . '/../_boot.php';
nm_require_ready();
if (nm_is_post()) {
    csrf_check();
    nm_admin_logout();
    flash('success', t('login.logged_out'));
}
nm_redirect(nm_link('admin/login.php'));
