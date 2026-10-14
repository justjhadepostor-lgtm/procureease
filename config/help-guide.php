<?php
/* ═══════════════════════════════════════════════════════════════
   The Help & documentation guide.

   The Super Admin edits it on the Help page itself; what is saved there
   is what every user sees. Until it is edited, the built-in guide below
   is shown. "Reset to the built-in guide" goes back to it.

   Stored as one JSON document in settings.help_guide:
     {"sections": [{"id", "title", "who", "html"}, …],
      "updated_at": "Y-m-d H:i:s", "updated_by": "Name"}

   Values that come from Settings are written as {{tokens}} and filled in
   when the page is shown, so the guide always quotes the numbers and
   contacts in force (the session timeout, the password length, …) even
   after an edit.
   =============================================================== */
require_once __DIR__ . '/app-settings.php';
require_once __DIR__ . '/richtext.php';

const HELP_KEY = 'help_guide';
const HELP_MAX_SECTIONS = 40;
const HELP_MAX_SECTION_BYTES = 20000;
const HELP_MAX_TOTAL_BYTES = 60000;          // settings.value is a TEXT column (64 KB)

/** The values the guide can quote, as {{Name}} => what it shows. */
const HELP_TOKENS = [
    '{{Institution}}'               => 'The institution name (Settings)',
    '{{Governing law}}'             => 'The governing law (Settings)',
    '{{Your role}}'                 => 'The role of whoever is reading',
    '{{Auto-save delay}}'           => 'How long the editors wait before saving on their own, e.g. "1.5 seconds"',
    '{{Session timeout}}'           => 'How long before an idle session signs out, e.g. "30 minutes"',
    '{{Password length}}'           => 'The shortest password accepted, e.g. "8 characters"',
    '{{ICT support email}}'         => 'The ICT support email, as a link (Settings)',
    '{{Procurement support email}}' => 'The Procurement support email, as a link (Settings)',
];

/** Who a part of the guide is mostly for — offered as suggestions. */
const HELP_AUDIENCES = ['Everyone', 'Departments', 'Department Admin', 'Budget Unit', 'Procurement Unit'];

/** The guide as it ships, written to match the system as it works now. */
function help_builtin(): array {
    $S = [];
    $S[] = ['id' => 'start', 'title' => 'Getting started', 'who' => 'Everyone', 'html' => "
  <p>ProcureEase is {{Institution}}'s system for preparing, reviewing and consolidating each unit's procurement plans, under <strong>{{Governing law}}</strong>.</p>
  <p>You are signed in as <strong>{{Your role}}</strong>. The menu on the left shows what your role can open.</p>
  <ul>
    <li><strong>Department user</strong> — prepares the department's documents. Everything saves on its own.</li>
    <li><strong>Department Admin</strong> — does the same, and is the one who <em>submits</em> for the department.
        (A department with no Department Admin yet lets any of its users submit.)</li>
    <li><strong>Budget Unit</strong> — reviews each PPMP first, approves it or sends it back.</li>
    <li><strong>Procurement Unit (Super Admin)</strong> — reviews everything next, may edit before approving, gives final approval,
        consolidates, and manages the system.</li>
  </ul>"];

    $S[] = ['id' => 'set', 'title' => 'The five documents of the year', 'who' => 'Departments', 'html' => "
  <p>Each fiscal year a department prepares one set:</p>
  <ul>
    <li><strong>PPMP</strong> — the Project Procurement Management Plan (<em>My PPMPs</em>).</li>
    <li><strong>APP-CSE</strong> — the common-use supplies you need, by month (<em>APP-CSE</em>). It is submitted with the PPMP and goes straight to the Procurement Unit.</li>
    <li><strong>Non-PS Supplies list</strong> (<em>Non-PS List</em>).</li>
    <li><strong>Annex</strong> — one per project (<em>Annex</em>).</li>
    <li><strong>Market Scoping</strong> — the NGPA Market Scoping Checklist (<em>Market Scoping</em>). Click a ☐ to tick it (☑).</li>
  </ul>
  <p>All five are required, and they are submitted together with one <strong>Submit</strong> on the PPMP.</p>"];

    $S[] = ['id' => 'ppmp', 'title' => 'Preparing the PPMP', 'who' => 'Departments', 'html' => "
  <ol>
    <li>Open <em>My PPMPs</em> and the year's PPMP. Add a row for each procurement item with <strong>＋ Add a procurement item</strong>.</li>
    <li>Fill in each column; the ones with a fixed list of answers are drop-downs. The <strong>TOTAL BUDGET</strong> adds itself up.</li>
    <li>Changes save on their own about {{Auto-save delay}} after you stop typing (<strong>💾 Save</strong> saves at once).
        <strong>🖨️ Print</strong> saves first, so the printout always matches the screen.</li>
  </ol>"];

    $S[] = ['id' => 'submit', 'title' => 'Submitting', 'who' => 'Department Admin', 'html' => "
  <p>Click <strong>📤 Submit</strong> on the PPMP. The window lists the whole set with ✓ or ✗, and Submit stays off until everything is ready:</p>
  <ul>
    <li>APP-CSE — at least one quantity entered;</li>
    <li>Non-PS list — this year's list exists (it may be empty);</li>
    <li>Annex — at least one, with its project name;</li>
    <li>Market Scoping — at least one activity ticked (☑).</li>
  </ul>
  <p>One Submit sends the PPMP to the Budget Unit, and the APP-CSE, Non-PS list, Annex and Market Scoping to the Procurement Unit — at once.
     You land on the PPMP, marked <strong>Submitted</strong>; each of the other pages shows it too, with the date. There is nothing else to check.</p>
  <p>Changed your mind? <strong>Unsubmit</strong> on the PPMP brings the whole set back, as long as the Budget Unit has not opened it yet.</p>"];

    $S[] = ['id' => 'status', 'title' => 'Where your documents are', 'who' => 'Departments', 'html' => "
  <p><em>My Department</em> shows the year's documents and where each one is:</p>
  <ul>
    <li><strong>With us</strong> / <strong>Draft</strong> — still being prepared.</li>
    <li><strong>With Budget</strong> — the Budget Unit is reviewing the PPMP.</li>
    <li><strong>With Procurement</strong> — with the Procurement Unit.</li>
    <li><strong>Sent back</strong> — changes were asked for.</li>
    <li><strong>Approved</strong> — final approval given; it can no longer be edited.</li>
  </ul>"];

    $S[] = ['id' => 'revision', 'title' => 'When something is sent back', 'who' => 'Departments', 'html' => "
  <p>Only the document that was sent back comes back to you. Its page shows the reviewer's note.</p>
  <ul>
    <li>Make the changes, then <strong>Submit</strong> on that document. It goes back <em>on its own</em> to whoever sent it back.</li>
    <li>A PPMP that is sent back is resubmitted on its own; your APP-CSE, Non-PS list, Annex and Market Scoping are not sent again.</li>
    <li>An APP-CSE the Procurement Unit sent back has its own <strong>📤 Submit</strong> on the APP-CSE page.</li>
  </ul>"];

    $S[] = ['id' => 'budget', 'title' => 'Reviewing as the Budget Unit', 'who' => 'Budget Unit', 'html' => "
  <ol>
    <li>Open <em>PPMP Folders</em>. The newest submission is first; <strong>Sort by date</strong> switches to oldest first.</li>
    <li>Open a PPMP. The APP-CSE does not come here: it goes straight to the Procurement Unit.</li>
    <li>Approve the PPMP (it goes on to the Procurement Unit), or send it back with a note.</li>
  </ol>"];

    $S[] = ['id' => 'pu', 'title' => 'The Procurement Unit (Super Admin)', 'who' => 'Procurement Unit', 'html' => "
  <ul>
    <li><strong>PPMP Folders</strong> — Administrative and Academic; what the Budget Unit approved waits at the top, newest first.
        To change a department's PPMP, ask the department first; you can edit it once they grant it.</li>
    <li><strong>APP-CSE Folders</strong> — every APP-CSE comes here straight from the department. Open one to <strong>✓ Approve</strong> it
        or <strong>↩ Request revision</strong>. To change it yourself, <strong>✎ Request to edit</strong> — the department grants it first.</li>
    <li><strong>Non-PS Supplies, Annex, Market Scoping</strong> — review, approve or send back. To change a Non-PS list,
        <strong>✎ Request to edit</strong> first; once the department grants it, <strong>✎ Edit Non-PS</strong> opens it until you approve it.</li>
    <li><strong>APP-CSE (Consolidated)</strong> — adds up every APP-CSE you approved in APP-CSE Folders; every line of it can still be edited.</li>
    <li><strong>Document Format</strong> — the wording, logos and columns of every form, kept per fiscal year. The Market Scoping tab holds
        the checklist itself (<strong>✎ Edit the form</strong>); the APP-CSE tab holds its categories and items.</li>
    <li><strong>Set Year</strong> — moves the system to a new fiscal year and archives everything of the years before.</li>
    <li><strong>Help</strong> — this guide. <strong>✎ Edit the guide</strong> changes it for everyone.</li>
    <li><strong>Backup &amp; Restore</strong>, <strong>Settings</strong>, <strong>User Management</strong>, <strong>Departments</strong>, <strong>Activity logs</strong>.</li>
  </ul>"];

    $S[] = ['id' => 'archive', 'title' => 'The Archive', 'who' => 'Everyone', 'html' => "
  <ul>
    <li>When the fiscal year changes, every PPMP, APP-CSE, Non-PS list, Annex and Market Scoping of the years before is archived on its own.</li>
    <li>A document can also be archived by hand with <strong>🗄️ Archive</strong> on its page. The Super Admin may archive anything, for everyone;
        a department may archive its own while it is a draft or once it is approved — not while it is being reviewed.
        The PPMP and its APP-CSE are archived together.</li>
    <li>Archived documents leave <em>My Department</em> and the lists, and cannot be edited. Open them from <em>Archive</em>, where
        <strong>↩ Restore</strong> brings them back as they were.</li>
  </ul>"];

    $S[] = ['id' => 'account', 'title' => 'Your account and security', 'who' => 'Everyone', 'html' => "
  <ul>
    <li>You are signed out after <strong>{{Session timeout}}</strong> without using ProcureEase. A notice a minute before lets you stay signed in.</li>
    <li>Passwords need at least <strong>{{Password length}}</strong>, with an uppercase letter, a lowercase letter, a number and a symbol.
        Change yours in <em>Settings</em>; forgot it? Use <strong>Forgot password</strong> on the sign-in page.</li>
    <li>Dark mode is the ☀/☾ button on the top bar; <strong>High contrast</strong> is in <em>Settings</em>.</li>
    <li>The 🔔 bell shows every notification; the Procurement Unit may also get them by email.</li>
  </ul>"];

    $S[] = ['id' => 'faq', 'title' => 'Questions', 'who' => 'Everyone', 'html' => "
  <p><strong>Why is Submit greyed out?</strong> Something in the set is not ready. The Submit window marks it ✗ with an <em>Open</em> link.</p>
  <p><strong>I cannot edit my document.</strong> It is submitted (wait for the review, or Unsubmit the PPMP), approved (final), or in the Archive (restore it).</p>
  <p><strong>Only the Department Admin can submit?</strong> Yes — everyone prepares, the Department Admin files. The page names who that is.</p>
  <p><strong>Did it save?</strong> The line next to the buttons says <em>All changes saved</em> once it has.</p>"];

    $S[] = ['id' => 'support', 'title' => 'Support', 'who' => 'Everyone', 'html' => "
  <p>Technical problems: <strong>{{Procurement support email}}</strong><br>
     Procurement and policy questions: <strong>{{Procurement support email}}</strong><br>
     Or use <em>Report issue</em> in the menu.</p>"];
    return $S;
}

/** The saved guide, or null when the built-in one is in use. */
function help_stored(): ?array {
    $raw = app_settings_all(true)[HELP_KEY] ?? '';
    if ($raw === '') return null;
    $j = json_decode($raw, true);
    if (!is_array($j) || empty($j['sections']) || !is_array($j['sections'])) return null;
    return $j;
}

/** The guide everyone sees now: [sections, meta] — meta is null for the built-in guide. */
function help_guide(): array {
    $st = help_stored();
    if (!$st) return [help_builtin(), null];
    $out = [];
    foreach ($st['sections'] as $s) {
        if (!is_array($s)) continue;
        $out[] = ['id' => (string)($s['id'] ?? ''), 'title' => (string)($s['title'] ?? ''),
                  'who' => (string)($s['who'] ?? ''), 'html' => help_clean_html((string)($s['html'] ?? ''))];
    }
    if (!$out) return [help_builtin(), null];
    return [$out, ['updated_at' => (string)($st['updated_at'] ?? ''), 'updated_by' => (string)($st['updated_by'] ?? '')]];
}

/**
 * What an edited part of the guide may contain: headings, paragraphs, lists,
 * bold/italic/underline, links, code. No colours or sizes of its own (the
 * guide follows the theme, light, dark or high contrast), no images.
 */
function help_clean_html(string $html): string {
    $h = rt_sanitize($html);
    $h = preg_replace('#<\s*/?\s*(font|img|table|thead|tbody|tfoot|tr|td|th|mark)\b[^>]*>#i', '', $h);
    $h = preg_replace('#\s(style|width|height|border|cellpadding|cellspacing|face|size|color)="[^"]*"#i', '', $h);
    $h = preg_replace("#\s(style|width|height|border|cellpadding|cellspacing|face|size|color)='[^']*'#i", '', $h);
    return trim($h);
}

/** A section id that can be used as a link target (#id). */
function help_clean_id(string $id, array $taken): string {
    $id = strtolower(preg_replace('/[^a-z0-9\-]+/i', '-', $id));
    $id = trim(substr($id, 0, 40), '-');
    if ($id === '' || in_array($id, ['helpfind', 'helpnone', 'helpedit', 'helpview'], true)) $id = 'part';
    $base = $id; $n = 2;
    while (in_array($id, $taken, true)) $id = $base . '-' . $n++;
    return $id;
}

/**
 * Checks and tidies what the editor sent. Returns [sections, error] —
 * error is '' when it can be saved.
 */
function help_validate(array $in): array {
    if (!$in) return [[], 'The guide needs at least one part. Use "Reset to the built-in guide" to start again.'];
    if (count($in) > HELP_MAX_SECTIONS) return [[], 'The guide can have at most ' . HELP_MAX_SECTIONS . ' parts.'];
    $out = []; $taken = [];
    foreach (array_values($in) as $i => $s) {
        if (!is_array($s)) return [[], 'Part ' . ($i + 1) . ' could not be read.'];
        $title = trim(preg_replace('/\s+/u', ' ', (string)($s['title'] ?? '')));
        $who   = trim(preg_replace('/\s+/u', ' ', (string)($s['who'] ?? '')));
        $html  = help_clean_html((string)($s['html'] ?? ''));
        if ($title === '') return [[], 'Part ' . ($i + 1) . ' needs a title.'];
        if (mb_strlen($title) > 120) return [[], 'The title of part ' . ($i + 1) . ' is too long (at most 120 characters).'];
        if (mb_strlen($who) > 60) return [[], '"For" in part ' . ($i + 1) . ' is too long (at most 60 characters).'];
        if (trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) === '') return [[], '"' . $title . '" is empty. Write something in it, or remove the part.'];
        if (strlen($html) > HELP_MAX_SECTION_BYTES) return [[], '"' . $title . '" is too long. Split it into two parts.'];
        $id = help_clean_id((string)($s['id'] ?? '') !== '' ? (string)$s['id'] : $title, $taken);
        $taken[] = $id;
        $out[] = ['id' => $id, 'title' => $title, 'who' => $who !== '' ? $who : 'Everyone', 'html' => $html];
    }
    if (strlen(json_encode($out, JSON_UNESCAPED_UNICODE)) > HELP_MAX_TOTAL_BYTES) {
        return [[], 'The guide is too long to keep. Shorten or remove a part.'];
    }
    return [$out, ''];
}

/** Keeps the guide for everyone; null goes back to the built-in guide. */
function help_save(?array $sections, string $by): void {
    $pdo = db();
    if ($sections === null) {
        $pdo->prepare('DELETE FROM settings WHERE key_name = ?')->execute([HELP_KEY]);
    } else {
        $value = json_encode([
            'sections' => $sections,
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => $by,
        ], JSON_UNESCAPED_UNICODE);
        if (!is_string($value)) {
            throw new RuntimeException('Could not encode the Help guide.');
        }
        // Update the existing setting in-place so an edit cannot leave the
        // guide missing if the insert fails after a delete.
        $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute([HELP_KEY, $value]);
    }
    // Clear/reload the in-request settings cache after saving.
    app_settings_all(true);
}

/** The {{tokens}} filled in with what is in force now, for the reader $u. */
function help_fill(string $html, array $u): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $role = ['admin' => 'Procurement Unit (Super Admin)', 'budget' => 'Budget Unit', 'dept_admin' => 'Department Admin',
             'user' => 'Department user'][$u['role'] ?? 'user'] ?? 'Department user';
    $sec = rtrim(rtrim(number_format(autosave_ms() / 1000, 1), '0'), '.');
    $min = session_timeout_min(); $pw = password_min_length();
    $mail = function (string $a) use ($h) { return $a !== '' ? '<a href="mailto:' . $h($a) . '">' . $h($a) . '</a>' : '—'; };
    return strtr($html, [
        '{{Institution}}'               => $h(app_setting('institution_name')),
        '{{Governing law}}'             => $h(app_setting('governing_law')),
        '{{Your role}}'                 => $h($role),
        '{{Auto-save delay}}'           => $h($sec . ' second' . ($sec === '1' ? '' : 's')),
        '{{Session timeout}}'           => $h($min . ' minute' . ($min === 1 ? '' : 's')),
        '{{Password length}}'           => $h($pw . ' characters'),
        '{{ICT support email}}'         => $mail(app_setting('support_procurement_email')),
        '{{Procurement support email}}' => $mail(app_setting('support_procurement_email')),
    ]);
}
