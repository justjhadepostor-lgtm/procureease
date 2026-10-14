<?php
/* ═══════════════════════════════════════════════════════════════
   Predefined choice lists for the PPMP entry table.

   Kept in one place so the server-rendered rows
   (includes/ppmp-edit-row.php) and the "+ Add row" template inside
   ppmp-edit.php can never drift apart. Editing a list here changes
   both at once.
   =============================================================== */

/** Type of Project column (searchable; "Others" lets a department type its own). */
const PPMP_PROJECT_TYPES = [
    'Goods', 'Infrastructure', 'Consulting Services',
    'Local Travel', 'Foreign Travel', 'Trainings', 'Supply and Delivery of Office Supplies',
    'Official Travel', 'Personnel Development',
];

/** Mode of Procurement column (searchable; "Others" lets a department type its own). */
const PPMP_MODES = [
    'Administration', 'PS-DBM', 'SVP', 'As per Request', 'Negotiated Procurement', 'Accredited Testing Center',
];

/** Quantity and Size of the Project to be Procured (searchable; "Others" lets a
    department type its own). N/A is written into the cell as "N/A". In the two
    "Estimated …" choices the number is the department's to change. */
const PPMP_QUANTITIES = [
    'N/A',
    'As per schedule',
    'As indicated in the attached APP-CSE',
    'As indicated in the attached Non-PS Supplies',
    'Estimated 2 seminars',
    'Estimated 4 travel activities',
    '1 lot',
    'As indicated in the attached form not-available in PS-DBM',
];

/** Pre-Procurement Conference, if applicable. */
const PPMP_YES_NO = ['Yes', 'No'];

/** Start / End of Procurement Activity, and Expected Delivery Period. */
const PPMP_MONTHS = [
    'January', 'February', 'March',     'April',   'May',      'June',
    'July',    'August',   'September', 'October', 'November', 'December',
];

/**
 * Render one PPMP cell as a dropdown.
 *
 * A value already on file that is not on the list — anything typed before
 * these columns became dropdowns — is kept and shown as its own selected
 * option. Opening and saving an older PPMP therefore never rewrites or
 * silently drops what a department recorded earlier.
 *
 * The blank option exists so an empty cell stays empty instead of being
 * stamped with whatever happens to be first in the list.
 */
function ppmp_choice(string $class, ?string $value, array $options): string {
    $value = trim((string)$value);
    $esc   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $html  = '<select class="cell-input ' . $esc($class) . '" onchange="markDirty()">';
    $html .= '<option value=""' . ($value === '' ? ' selected' : '') . '>N/A</option>';
    foreach ($options as $opt) {
        $html .= '<option value="' . $esc($opt) . '"'
               . ($value === $opt ? ' selected' : '') . '>' . $esc($opt) . '</option>';
    }
    if ($value !== '' && !in_array($value, $options, true)) {
        $html .= '<option value="' . $esc($value) . '" selected>' . $esc($value) . '</option>';
    }
    return $html . '</select>';
}

/**
 * A searchable PPMP cell (Type of Project, Mode of Procurement): a text box
 * that lists the choices while typing, with "Others" last so anything not on
 * the list can be typed. The value is simply the text in the box, so a value
 * typed before these lists existed is kept exactly as it is. Empty = N/A.
 * The list itself is drawn by ppmp-edit.php (PPMP_CHOICES[$list]).
 * An empty Quantity cell prints as "as per schedule", so that is what it says.
 */
function ppmp_combo(string $class, ?string $value, string $list): string {
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    return '<input class="cell-input pp-combo ' . $esc($class) . '" data-list="' . $esc($list) . '" value="' . $esc(trim((string)$value)) . '"'
         . ' placeholder="' . ($list === 'quantities' ? 'As per schedule' : 'N/A') . '" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false"'
         . ' aria-controls="ppComboList" oninput="markDirty()">';
}
