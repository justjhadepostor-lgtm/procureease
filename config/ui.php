<?php
/* ═══════════════════════════════════════════════════════════════
   Two small pieces of the interface used on many pages.

   search_field()  A search box that reads as one: a magnifier inside the
                   field, the "search" input type (screen readers announce
                   it as a search box), a × to clear it, Esc clears too.
   sort_bar()      "Sort by date: Newest first / Oldest first" above a list
                   of submissions. Each item in the list carries data-ts
                   (when it was submitted); the page sends the list newest
                   first. Where a page has an order of its own (work waiting
                   on the Procurement Unit first) that order is the first
                   choice and stays the default.
   The behaviour is in includes/head.php, the look in assets/style.css.
   =============================================================== */

/** The search boxes that only take letters keep doing so. */
const SEARCH_NO_DIGITS = 'maxlength="60" oninput="this.value=this.value.replace(/[0-9]/g,\'\')"';

/**
 * $o: name, id, value, placeholder, label (for screen readers), attrs (raw,
 * already-escaped extra attributes such as oninput or maxlength), style.
 */
function search_field(array $o): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $label = $o['label'] ?? ($o['placeholder'] ?? 'Search');
    return '<div class="search-field"' . (!empty($o['style']) ? ' style="' . $h($o['style']) . '"' : '') . '>'
         . '<svg class="sf-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><line x1="20.5" y1="20.5" x2="16.2" y2="16.2"/></svg>'
         . '<input type="search" class="search-input"'
         . (!empty($o['name']) ? ' name="' . $h($o['name']) . '"' : '')
         . (!empty($o['id']) ? ' id="' . $h($o['id']) . '"' : '')
         . ' value="' . $h($o['value'] ?? '') . '"'
         . ' placeholder="' . $h($o['placeholder'] ?? 'Search…') . '"'
         . ' aria-label="' . $h($label) . '" autocomplete="off" enterkeyhint="search"'
         . (!empty($o['attrs']) ? ' ' . $o['attrs'] : '')
         . '>'
         . '<button type="button" class="sf-clear" aria-label="Clear the search" title="Clear" hidden>'
         . '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg></button>'
         . '</div>';
}

/**
 * $for: the id of the list — or a selector (".x" / "#x") for several lists
 * sorted the same way. $default: a first choice named for the page's own
 * order, e.g. 'Waiting on you first'; '' when the page is simply newest first.
 */
function sort_bar(string $for, string $default = ''): string {
    $h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $id = 'sort-' . preg_replace('/[^a-z0-9]+/i', '-', trim($for, '.#'));
    return '<div class="sort-bar" data-sort-for="' . $h($for) . '">'
         . '<svg class="sb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3"/></svg>'
         . '<label for="' . $h($id) . '">Sort by date</label>'
         . '<select id="' . $h($id) . '" class="filter-select sort-select">'
         . ($default !== '' ? '<option value="default">' . $h($default) . '</option>' : '')
         . '<option value="new">Newest first</option>'
         . '<option value="old">Oldest first</option>'
         . '</select></div>';
}

/** A date for data-ts: seconds, 0 when there is none. */
function sort_ts($when): int {
    if ($when === null || $when === '') return 0;
    $t = strtotime((string)$when);
    return $t ? (int)$t : 0;
}

/**
 * "✓ Submitted" on a document's own page, with when, by whom and where it is
 * now — so nobody has to go and check whether it went. '' for a draft.
 */
function submitted_stamp(string $status, ?string $when, ?string $who, string $withWhom): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    if (!in_array($status, ['submitted', 'budget_approved', 'approved'], true)) return '';
    $approved = $status === 'approved';
    $t = $when ? strtotime((string)$when) : 0;
    return '<div class="sub-stamp' . ($approved ? ' is-approved' : '') . '" id="submittedStamp" role="status">'
         . '<span class="ss-mark" aria-hidden="true">✓</span><span>'
         . ($approved ? '<strong>Approved.</strong> ' : '<strong>Submitted</strong>')
         . ($t && !$approved ? ' on ' . $h(date('M j, Y \a\t g:i A', $t)) : '')
         . (!$approved && $who ? ' by ' . $h($who) : '')
         . ($approved ? $h($withWhom) : ' — ' . $h($withWhom) . '.')
         . '</span></div>';
}
