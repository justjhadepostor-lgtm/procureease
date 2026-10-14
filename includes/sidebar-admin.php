<?php
// Count pending user registrations and open reports for the sidebar badges
$__pendingUsers = 0;
$__openReports  = 0;
$__pendingNonps = 0;
try {
    $__pendingUsers = (int)db()->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
} catch (Throwable $t) { /* status column may not exist yet */ }
try {
    $__openReports = (int)db()->query("SELECT COUNT(*) FROM user_reports WHERE status = 'open'")->fetchColumn();
} catch (Throwable $t) { /* table may not exist yet */ }
try {
    $__pendingNonps = (int)db()->query("SELECT COUNT(*) FROM nonps_submissions
                                         WHERE status = 'submitted' AND fy >= " . (int)active_fy() . "
                                           AND (archived IS NULL OR archived = 0)")->fetchColumn();
} catch (Throwable $t) { /* table may not exist yet */ }
// APP-CSEs waiting for the Procurement Unit (v77, config/appcse-review.php); those from
// before v77 follow their PPMP until the Procurement Unit acts on them
$__pendingAppcse = 0;
try {
    require_once __DIR__ . '/../config/appcse-review.php';
    appcse_flow_columns();
    $__afy = (int)active_fy();
    $__pendingAppcse = (int)db()->query("SELECT COUNT(*) FROM appcse_header WHERE fy = $__afy AND status = 'submitted'
                                           AND (archived IS NULL OR archived = 0)")->fetchColumn()
                     + (int)db()->query("SELECT COUNT(*) FROM ppmps p
                                          WHERE p.fy = $__afy AND p.status IN ('submitted','budget_approved')
                                            AND NOT EXISTS (SELECT 1 FROM appcse_header h WHERE h.fy = p.fy AND h.dept_code = p.dept_code
                                                              AND ((h.status IS NOT NULL AND h.status <> '') OR h.revision = 1 OR h.archived = 1))")->fetchColumn();
} catch (Throwable $t) { /* table may not exist yet */ }
?>
<aside class="sidebar">
  <div class="brand">ProcureEase</div>
  <div class="brand-sub">Procurement Unit</div>
  <?php $__u = $__u ?? current_user(); ?>
  <ul class="nav">
    <!-- ─── PROCUREMENT ─── -->
    <li style="margin-top: 4px; padding: 8px 20px 4px; font-size: 10px; font-weight: 700; letter-spacing: 0.08em; color: rgba(255,255,255,0.5); text-transform: uppercase;"> Procurement
    </li>
    <li><a href="admin-home.php" class="<?= ($activeNav ?? '') === 'home' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg> Dashboard
    </a></li>
    <li><a href="admin-dashboard.php" class="<?= ($activeNav ?? '') === 'ppmps' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> PPMP Folders
    </a></li>
    <li><a href="consolidation.php" class="<?= ($activeNav ?? '') === 'consolidation' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg> Non-PS (Consolidated)
    </a></li>
    <li><a href="admin-tracking.php" class="<?= ($activeNav ?? '') === 'tracking' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> PPMP Tracking
    </a></li>
    <li><a href="nonps-consolidated.php" class="<?= ($activeNav ?? '') === 'nonps-consolidated' ? 'active' : '' ?>" style="display:flex; align-items:center; justify-content:space-between;">
      <span style="display:flex; align-items:center; gap:10px;">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg> Non-PS Supplies Folders
      </span>
      <?php if ($__pendingNonps > 0): ?>
        <span style="background: var(--accent); color: var(--accent-ink); font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 999px; font-family: 'Space Grotesk', sans-serif;">
          <?= $__pendingNonps ?></span>
      <?php endif; ?></a></li>
    <li><a href="annex.php" class="<?= ($activeNav ?? '') === 'annex' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg> Annex Folders
    </a></li>
    <li><a href="market-scoping.php" class="<?= ($activeNav ?? '') === 'scoping' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Market Scoping Folders
    </a></li>
    <li><a href="appcse-folders.php" class="<?= ($activeNav ?? '') === 'appcse-folders' ? 'active' : '' ?>" style="display:flex; align-items:center; justify-content:space-between;">
      <span style="display:flex; align-items:center; gap:10px;">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg> APP-CSE Folders
      </span>
      <?php if ($__pendingAppcse > 0): ?>
        <span style="background: var(--accent); color: var(--accent-ink); font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 999px; font-family: 'Space Grotesk', sans-serif;">
          <?= $__pendingAppcse ?></span>
      <?php endif; ?></a></li>
    <li><a href="appcse-consolidated.php" class="<?= ($activeNav ?? '') === 'appcse-consolidated' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/><path d="M13 13h5M13 17h5"/></svg> APP-CSE (Consolidated)
    </a></li>
    <li><a href="deadlines.php" class="<?= ($activeNav ?? '') === 'deadlines' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Deadlines
    </a></li>
    <li><a href="reports.php" class="<?= ($activeNav ?? '') === 'reports' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> Reports
    </a></li>
    <li><a href="set-year.php" class="<?= ($activeNav ?? '') === 'set-year' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg> Set Year
    </a></li>

    <!-- ─── MY DEPARTMENT ─── -->
    <!-- The Procurement Unit is a department too, and prepares its own
         documents with the same forms the other units use. -->
    <li style="margin-top: 12px; padding: 8px 20px 4px; font-size: 10px; font-weight: 700; letter-spacing: 0.08em; color: rgba(255,255,255,0.5); text-transform: uppercase;"> My department
    </li>
    <li><a href="ppmp-edit.php" class="<?= ($activeNav ?? '') === 'my-ppmp' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> PPMP
    </a></li>
    <li><a href="nonps-edit.php" class="<?= ($activeNav ?? '') === 'my-nonps' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg> Non-PS List
    </a></li>
    <li><a href="appcse-edit.php" class="<?= ($activeNav ?? '') === 'appcse' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="9" x2="9" y2="21"/></svg> APP-CSE
    </a></li>
    <li><a href="annex-edit.php" class="<?= ($activeNav ?? '') === 'my-annex' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg> Annex
    </a></li>
    <li><a href="market-scoping-edit.php" class="<?= ($activeNav ?? '') === 'my-scoping' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Market Scoping
    </a></li>
    <?php /* v99: "My Sets" is no longer in the menu — the sets are kept from the top of the PPMP (Working on · ＋ Add Set) */ ?>

    <!-- ─── USER MANAGEMENT ─── -->
    <li style="margin-top: 12px; padding: 8px 20px 4px; font-size: 10px; font-weight: 700; letter-spacing: 0.08em; color: rgba(255,255,255,0.5); text-transform: uppercase;"> User management
    </li>
    <li><a href="pending-users.php" class="<?= ($activeNav ?? '') === 'pending-users' ? 'active' : '' ?>" style="display:flex; align-items:center; justify-content:space-between;">
      <span style="display:flex; align-items:center; gap:10px;">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg> User Management
      </span>
      <?php if ($__pendingUsers > 0): ?>
        <span style="background: var(--accent); color: var(--accent-ink); font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 999px; font-family: 'Space Grotesk', sans-serif;">
          <?= $__pendingUsers ?></span>
      <?php endif; ?></a></li>
    <li><a href="document-format.php" class="<?= ($activeNav ?? '') === 'doc-format' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><circle cx="12" cy="15" r="2"/><path d="M9 12h.01"/></svg> Document Format
    </a></li>
    <li><a href="departments.php" class="<?= ($activeNav ?? '') === 'departments' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M10 21v-5h4v5"/><path d="M9 10h.01M15 10h.01"/></svg> Departments
    </a></li>
    <li><a href="admin-reports.php" class="<?= ($activeNav ?? '') === 'user-reports' ? 'active' : '' ?>" style="display:flex; align-items:center; justify-content:space-between;">
      <span style="display:flex; align-items:center; gap:10px;">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> User reports
      </span>
      <?php if ($__openReports > 0): ?>
        <span style="background: var(--accent); color: var(--accent-ink); font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 999px; font-family: 'Space Grotesk', sans-serif;">
          <?= $__openReports ?></span>
      <?php endif; ?></a></li>
    <li><a href="announcement.php" class="<?= ($activeNav ?? '') === 'announcement' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l18-8v18l-18-8v-2z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg> Announcement
    </a></li>

    <!-- ─── SYSTEM ─── -->
    <li style="margin-top: 12px; padding: 8px 20px 4px; font-size: 10px; font-weight: 700; letter-spacing: 0.08em; color: rgba(255,255,255,0.5); text-transform: uppercase;"> System
    </li>
    <li><a href="activity-logs.php" class="<?= ($activeNav ?? '') === 'activity' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Activity logs
    </a></li>
    <li><a href="archive.php" class="<?= ($activeNav ?? '') === 'archive' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg> Archive
    </a></li>
    <li><a href="backup.php" class="<?= ($activeNav ?? '') === 'backup' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg> Backup &amp; Restore
    </a></li>
    <li><a href="settings.php" class="<?= ($activeNav ?? '') === 'settings' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg> Settings
    </a></li>
    <li><a href="guide.php" class="<?= ($activeNav ?? '') === 'guide' ? 'active' : '' ?>"><?php /* v138: video tutorials */ ?>
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg> Guide
    </a></li>
    <li><a href="help.php" class="<?= ($activeNav ?? '') === 'help' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Help
    </a></li>

    <!-- ─── ACCOUNT ─── -->
    <li style="margin-top: 12px; padding: 8px 20px 4px; font-size: 10px; font-weight: 700; letter-spacing: 0.08em; color: rgba(255,255,255,0.5); text-transform: uppercase;"> Account
    </li>
    <li><a href="profile.php" class="<?= ($activeNav ?? '') === 'profile' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Profile
    </a></li>
    <li><a href="logout.php" onclick="return confirmSignOut(event)">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Sign out
    </a></li></ul>
  <div class="sidebar-footer"> Procurement Unit<br> FY 2027 · NGPA
  </div></aside>
