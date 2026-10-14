<?php
/**
 * Non-PS Supplies — entry point.
 *
 * There is one Non-PS list per department per fiscal year, so there is nothing
 * to choose between. Clicking "Non-PS List" in the sidebar goes straight into
 * the document itself; nonps-edit.php finds this year's list, or starts one.
 */
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_login();
ensure_admin_tables();

$u = current_user();

// The Procurement Unit reviews submissions instead of writing one
if (($u['role'] ?? '') === 'admin') { header('Location: nonps-inbox.php'); exit; }

header('Location: nonps-edit.php');
exit;
