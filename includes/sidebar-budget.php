<?php
// Live counts for badges — unread announcements
$__u = current_user();
$__unreadAnnouncements = 0;
try {
    $__unreadAnnouncements = (int)db()->query("
        SELECT COUNT(*) FROM notifications
         WHERE user_id = " . (int)$__u['id'] . "
           AND type    = 'announcement'
           AND is_read = 0")->fetchColumn();
} catch (Throwable $t) {}

// Pending budget review queue count (existing budget-office duty)
$__pendingBudget = 0;
try {
    $__pendingBudget = (int)db()->query("SELECT COUNT(*) FROM ppmps WHERE status = 'submitted' AND fy >= " . (int)active_fy())->fetchColumn();
} catch (Throwable $t) {}
?>
<aside class="sidebar">
  <div class="brand">ProcureEase</div>
  <div class="brand-sub">Budget</div>
  <ul class="nav">
    <li style="margin-top: 4px; padding: 8px 20px 4px; font-size: 10px; font-weight: 700; letter-spacing: 0.08em; color: rgba(255,255,255,0.5); text-transform: uppercase;">
      Budget Unit
    </li>
    <li><a href="budget-home.php" class="<?= ($activeNav ?? '') === 'budget-home' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a></li>
    <li><a href="budget-dashboard.php" class="<?= ($activeNav ?? '') === 'budget-queue' ? 'active' : '' ?>" style="display:flex; align-items:center; justify-content:space-between;">
      <span style="display:flex; align-items:center; gap:10px;">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
        PPMP Folders
      </span>
      <?php if ($__pendingBudget > 0): ?>
        <span style="background: var(--accent); color: var(--accent-ink); font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 999px; font-family: 'Space Grotesk', sans-serif;">
          <?= $__pendingBudget ?>
        </span>
      <?php endif; ?>
    </a></li>
    <li><a href="budget-allocations.php" class="<?= ($activeNav ?? '') === 'allocations' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      Budget Allocations
    </a></li>
    <li><a href="fund-sources.php" class="<?= ($activeNav ?? '') === 'fund-sources' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
      Fund Sources
    </a></li>
    <li style="margin-top: 12px; padding: 8px 20px 4px; font-size: 10px; font-weight: 700; letter-spacing: 0.08em; color: rgba(255,255,255,0.5); text-transform: uppercase;">
      My documents
    </li>
    <li><a href="user-dashboard.php" class="<?= ($activeNav ?? '') === 'ppmps' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      My PPMPs
    </a></li>
    <li><a href="nonps-list.php" class="<?= ($activeNav ?? '') === 'nonps' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11H3v10h6V11z"/><path d="M15 3h6v18h-6V3z"/><path d="M9 7H3"/><path d="M15 15h6"/></svg>
      Non-PS List
    </a></li>
    <li><a href="annex.php" class="<?= ($activeNav ?? '') === 'annex' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
      Annex
    </a></li>
    <li><a href="appcse-edit.php" class="<?= ($activeNav ?? '') === 'appcse' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="9" x2="9" y2="21"/></svg>
      APP-CSE
    </a></li>
    <li><a href="market-scoping.php" class="<?= ($activeNav ?? '') === 'scoping' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      Market Scoping
    </a></li>
    <li style="margin-top: 12px; padding: 8px 20px 4px; font-size: 10px; font-weight: 700; letter-spacing: 0.08em; color: rgba(255,255,255,0.5); text-transform: uppercase;">
      Department
    </li>
    <?php if (function_exists('can_submit_for_dept') && can_submit_for_dept()): ?>
    <li><a href="dept-tracking.php" class="<?= ($activeNav ?? '') === 'dept-tracking' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
      My Department
    </a></li>
    <?php endif; ?>
    <?php /* v99: "My Sets" is no longer in the menu — the sets are kept from the top of the PPMP (Working on · ＋ Add Set) */ ?>
    <li><a href="archive.php" class="<?= ($activeNav ?? '') === 'archive' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
      Archive
    </a></li>
    <li style="margin-top: 12px; padding: 8px 20px 4px; font-size: 10px; font-weight: 700; letter-spacing: 0.08em; color: rgba(255,255,255,0.5); text-transform: uppercase;">
      Updates
    </li>
    <li><a href="user-announcements.php" class="<?= ($activeNav ?? '') === 'announcements' ? 'active' : '' ?>" style="display:flex; align-items:center; justify-content:space-between;">
      <span style="display:flex; align-items:center; gap:10px;">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
        Announcements
      </span>
      <?php if ($__unreadAnnouncements > 0): ?>
        <span style="background: var(--accent); color: var(--accent-ink); font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 999px; font-family: 'Space Grotesk', sans-serif;">
          <?= $__unreadAnnouncements ?>
        </span>
      <?php endif; ?>
    </a></li>
    <li><a href="report-issue.php" class="<?= ($activeNav ?? '') === 'report' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      Report a problem
    </a></li>
    <li style="margin-top: 12px; padding: 8px 20px 4px; font-size: 10px; font-weight: 700; letter-spacing: 0.08em; color: rgba(255,255,255,0.5); text-transform: uppercase;">
      Account
    </li>
    <li><a href="user-settings.php" class="<?= ($activeNav ?? '') === 'settings' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      Settings
    </a></li>
    <li><a href="guide.php" class="<?= ($activeNav ?? '') === 'guide' ? 'active' : '' ?>"><?php /* v138: video tutorials */ ?>
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg> Guide
    </a></li>
    <li><a href="help.php" class="<?= ($activeNav ?? '') === 'help' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      Help
    </a></li>
    <li><a href="profile.php" class="<?= ($activeNav ?? '') === 'profile' ? 'active' : '' ?>">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Profile
    </a></li>
    <li><a href="logout.php" onclick="return confirmSignOut(event)">
      <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Sign out
    </a></li>
  </ul>
  <div class="sidebar-footer">
    Budget Unit<br>
    <?= e($__u['full_name'] ?? '') ?>
  </div>
</aside>
