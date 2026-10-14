<?php
/* ═══════════════════════════════════════════════════════════════
   Document format — the Procurement Unit's master copy of every form.

   Every fixed word on an official document (logos, letterhead, group
   headings, column headings, signatory block) is stored here ONCE.
   Every editor, reviewer view, print page and Excel export reads from
   it, so when the Procurement Unit changes wording on the Document
   Format page and saves, every department sees the new form on their
   next page load. That is how a new format handed down by a higher
   office gets rolled out: change it once, here.

   This does NOT take anything from users. Only the FORM is centrally
   owned. Everything a department writes INTO the form — entries,
   quantities, funds, signatories, "Prepared by" and its designation,
   notes — stays theirs to edit, exactly as before.

   The defaults below are the wording each page previously had
   hardcoded, so until the Procurement Unit edits something every document
   renders as it always did.
   =============================================================== */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/signatories.php';   // who signs, read from the accounts

/** Document types, in menu order. */
const DOC_FORMAT_TYPES = [
    'ppmp'    => 'PPMP',
    'appcse'  => 'APP-CSE',
    'nonps'   => 'Non-PS Supplies',
    'annex'   => 'Annex',
    'scoping' => 'Market Scoping',
];

/**
 * What the Procurement Unit can edit, per document, grouped for the page.
 * Each entry is  key => [label, kind]  where kind is 'text' or 'image'.
 */
const DOC_FORMAT_SCHEMA = [
    'ppmp' => [
        'Letterhead' => [
            'logo_left'  => ['Left logo', 'image'],
            'logo_right' => ['Right logo', 'image'],
            'rep_line'   => ['Republic line', 'text'],
            'org_name'   => ['Organisation name', 'text'],
            'address'    => ['Address line', 'text'],
            'doc_title'  => ['Document title', 'text'],
        ],
        'Group headings' => [
            'grp_details'  => ['Columns 1-5 heading', 'text'],
            'grp_timeline' => ['Columns 6-8 heading', 'text'],
            'grp_funding'  => ['Columns 9-10 heading', 'text'],
        ],
        'Column headings' => [
            'col_desc'    => ['Column 1', 'text'],
            'col_type'    => ['Column 2', 'text'],
            'col_qty'     => ['Column 3', 'text'],
            'col_mode'    => ['Column 4', 'text'],
            'col_pre'     => ['Column 5', 'text'],
            'col_start'   => ['Column 6', 'text'],
            'col_end'     => ['Column 7', 'text'],
            'col_deliv'   => ['Column 8', 'text'],
            'col_fund'    => ['Column 9', 'text'],
            'col_amount'  => ['Column 10', 'text'],
            'col_docs'    => ['Column 11', 'text'],
            'col_remarks' => ['Column 12', 'text'],
        ],
        'Signatory block' => [
            'sig_prepared'        => ['First block label', 'text'],
            'sig_approved'        => ['Second block label', 'text'],
            'appr_acad_name'      => ['Approving official — Academic units', 'text'],
            'appr_acad_position'  => ['Their position', 'text'],
            'appr_admin_name'     => ['Approving official — Administrative units', 'text'],
            'appr_admin_position' => ['Their position', 'text'],
        ],
        'Other wording' => [
            'col_num_prefix' => ['Numbering row prefix', 'text'],
        ],
        // v94: which columns the table has — built-in ones taken off, columns added (config/ppmp-columns.php)
        'Columns of the table' => [
            'columns' => ['Columns', 'columns'],
        ],
    ],
    'nonps' => [
        'Letterhead' => [
            'logo_left'  => ['Left logo', 'image'],
            'logo_right' => ['Right logo', 'image'],
            'rep_line'   => ['Republic line', 'text'],
            'org_name'   => ['Organisation name', 'text'],
            'address'    => ['Address line', 'text'],
            'unit_line'  => ['Unit line', 'text'],
            'doc_title'  => ['Document title', 'text'],
        ],
        'Column headings' => [
            'col_no'    => ['Column 1', 'text'],
            'col_part'  => ['Column 2', 'text'],
            'col_unit'  => ['Column 3', 'text'],
            'col_qty'   => ['Column 4', 'text'],
            'col_cost'  => ['Column 5', 'text'],
            'col_total' => ['Column 6', 'text'],
        ],
        'Signatory block' => [
            'sig_prepared' => ['"Prepared by" label', 'text'],
            'sig_head_name'     => ['Approving official — blank uses the Procurement Unit head', 'text'],
            'sig_head_position' => ['Approving official position — blank uses their position', 'text'],
        ],
        'Added to the total (each is a % of the TOTAL)' => [
            'lbl_vat'   => ['VAT row label', 'text'],
            'rate_vat'  => ['VAT rate (%)', 'percent'],
            'lbl_log'   => ['Logistics row label', 'text'],
            'rate_log'  => ['Logistics rate (%)', 'percent'],
            'lbl_inf'   => ['Inflation row label', 'text'],
            'rate_inf'  => ['Inflation rate (%)', 'percent'],
            'lbl_grand' => ['Grand total row label', 'text'],
        ],
    ],
    'appcse' => [
        'Column headings' => [
            'col_code'   => ['Code', 'text'],
            'col_item'   => ['Item & specifications', 'text'],
            'col_uom'    => ['Unit of measure', 'text'],
            'col_months' => ['Monthly quantity heading', 'text'],
            'col_totqty' => ['Total quantity', 'text'],
            'col_price'  => ['Unit price', 'text'],
            'col_price'  => ['Unit price', 'text'],
            'col_amount' => ['Total amount', 'text'],
        ],
        'Top notes' => [
            'form_title' => ['Form title', 'html'],
            'intro_label' => ['Introduction label', 'text'],
            'intro_body' => ['Introduction text', 'html'],
            'reminders_label' => ['Reminders label', 'text'],
            'reminders_body' => ['Reminders text', 'html'],
            'note_text' => ['Closing note', 'html'],
        ],
        'Summary under the table (A to E of each Part)' => [
            'sum_part1_title' => ['Part I heading', 'text'],
            'sum_part2_title' => ['Part II heading', 'text'],
            'sum_part2_note'  => ['Part II note', 'html'],
            'sum_a' => ['Row A — the total', 'text'],
            'rate_b' => ['Additional provision for price changes (%)', 'percent'],
            'sum_b' => ['Row B — price changes ({rate} is the % above)', 'text'],
            'sum_c' => ['Row C — transport and freight', 'text'],
            'sum_d' => ['Row D — grand total', 'text'],
            'sum_e' => ['Row E — approved budget', 'html'],
            'sum_warrant' => ['Statement under the summary', 'html'],
        ],
        'Signatory block' => [
            'sig1_label'    => ['Signatory 1 label', 'text'],
            'sig1_name'     => ['Signatory 1 name', 'text'],
            'sig1_position' => ['Signatory 1 position', 'text'],
            'sig2_label'    => ['Signatory 2 label', 'text'],
            'sig2_name'     => ['Signatory 2 name — blank uses the Budget Officer', 'text'],
            'sig2_position' => ['Signatory 2 position — blank uses their position', 'text'],
            'sig3_label'    => ['Signatory 3 label', 'text'],
            'sig3_name'     => ['Signatory 3 name', 'text'],
            'sig3_position' => ['Signatory 3 position', 'text'],
            'sig_date_label' => ['Date label', 'text'],
            'sig_date_value' => ['Date prepared value', 'text'],
        ],
        'Monthly quantity headings' => [
            'mon_1' => ['Month 1', 'text'],
            'mon_2' => ['Month 2', 'text'],
            'mon_3' => ['Month 3', 'text'],
            'mon_4' => ['Month 4', 'text'],
            'mon_5' => ['Month 5', 'text'],
            'mon_6' => ['Month 6', 'text'],
            'mon_7' => ['Month 7', 'text'],
            'mon_8' => ['Month 8', 'text'],
            'mon_9' => ['Month 9', 'text'],
            'mon_10' => ['Month 10', 'text'],
            'mon_11' => ['Month 11', 'text'],
            'mon_12' => ['Month 12', 'text'],
            'qtr_1' => ['Quarter 1', 'text'],
            'qtr_2' => ['Quarter 2', 'text'],
            'qtr_3' => ['Quarter 3', 'text'],
            'qtr_4' => ['Quarter 4', 'text'],
            'qamt_1' => ['Quarter 1 amount', 'html'],
            'qamt_2' => ['Quarter 2 amount', 'html'],
            'qamt_3' => ['Quarter 3 amount', 'html'],
            'qamt_4' => ['Quarter 4 amount', 'html'],
        ],
        'Total rows' => [
            'lbl_part1' => ['Part I total', 'text'],
            'lbl_part2' => ['Part II total', 'text'],
            'lbl_grand' => ['Grand total', 'text'],
            'lbl_grand_d' => ['Grand total with the % (D of Part I + Part II)', 'text'],
        ],
    ],
    'scoping' => [
        'Letterhead' => [
            'logo_left'  => ['Left logo', 'image'],
            'logo_right' => ['Right logo', 'image'],
            'rep_line'   => ['Republic line', 'text'],
            'org_name'   => ['Organisation name', 'text'],
            'address'    => ['Address line', 'text'],
            'doc_title'  => ['Document title', 'text'],
        ],
    ],
    'annex' => [
        'Letterhead' => [
            'logo_left'  => ['Left logo (ASCOT)', 'image'],
            'logo_right' => ['Right logo (Bagong Pilipinas)', 'image'],
            'rep_line'  => ['Republic line', 'text'],
            'org_name'  => ['Organisation name', 'text'],
            'address'   => ['Address line', 'text'],
        ],
        'Column headings' => [
            'col_items' => ['Items and specifications', 'text'],
            'col_qty'   => ['Quantity', 'text'],
            'col_total' => ['Total amount', 'text'],
        ],
        'Field labels' => [
            'lbl_project' => ['Project name', 'text'],
            'lbl_general' => ['General description', 'text'],
            'lbl_nep' => ['NEP account', 'text'],
            'lbl_item' => ['Item of expenditure', 'text'],
            'lbl_alloc' => ['Allocated budget', 'text'],
            'lbl_dates' => ['Inclusive dates', 'text'],
            'lbl_fy' => ['Fiscal year label', 'text'],
            'lbl_totbudget' => ['Total estimated budget', 'text'],
        ],
        'Instructions' => [
            'instr_title' => ['Instructions heading', 'text'],
            'instr_body' => ['Instructions text', 'html'],
        ],
    ],
];

/** Exactly the wording each page carried before this feature existed. */
const DOC_FORMAT_DEFAULTS = [
    'ppmp' => [
        'logo_left'  => 'assets/ascot-logo.png',
        'logo_right' => 'assets/bagong-pilipinas.png',
        'rep_line'   => 'Republic of the Philippines',
        'org_name'   => 'AURORA STATE COLLEGE OF TECHNOLOGY',
        'address'    => 'Brgy. Zabali, 3200 Baler, Aurora, Philippines',
        'doc_title'  => 'PROJECT PROCUREMENT MANAGEMENT PLAN (PPMP) NO.',
        'grp_details'  => 'PROCUREMENT PROJECT DETAILS',
        'grp_timeline' => 'PROJECTED TIMELINE (MM/YYYY)',
        'grp_funding'  => 'FUNDING DETAILS',
        'col_desc'    => 'General Description and Objective of the Project to be Procured',
        'col_type'    => 'Type of the Project to be Procured (whether Goods, Infrastructure and Consulting Services)',
        'col_qty'     => 'Quantity and Size of the Project to be Procured',
        'col_mode'    => 'Recommended Mode of Procurement',
        'col_pre'     => 'Pre-Procurement Conference, if applicable (Yes/No)',
        'col_start'   => 'Start of Procurement Activity',
        'col_end'     => 'End of Procurement Activity',
        'col_deliv'   => 'Expected Delivery/Implementation Period',
        'col_fund'    => 'Source of Funds',
        'col_amount'  => 'Estimated Budget / Authorized Budgetary Allocation (PhP)',
        'col_docs'    => 'ATTACHED SUPPORTING DOCUMENTS',
        'col_remarks' => 'REMARKS',
        // The two blocks at the foot of the form, and the Vice President
        // each kind of department answers to. Typed here once rather than
        // into every department's PPMP; a name typed on a PPMP still wins.
        'sig_prepared'        => 'Prepared by:',
        'sig_approved'        => 'Approved by:',
        'appr_acad_name'      => 'Dr. Mayreen Amazona',
        'appr_acad_position'  => 'Vice President for Academic Affairs',
        'appr_admin_name'     => 'Dr. Oscar C. Barawid Jr.',
        'appr_admin_position' => 'Vice President for Administration, Planning and Finance',
        'col_num_prefix' => 'Column',
        'columns' => '',
    ],
    'nonps' => [
        'logo_left'  => 'assets/ascot-logo.png',
        'logo_right' => '',
        'rep_line'   => 'Republic of the Philippines',
        'org_name'   => 'AURORA STATE COLLEGE OF TECHNOLOGY',
        'address'    => 'Baler, Aurora',
        'unit_line'  => 'PROCUREMENT UNIT',
        'doc_title'  => 'LIST OF NON-PS SUPPLIES AND MATERIALS',
        'col_no'    => 'No.',
        'col_part'  => 'Particulars',
        'col_unit'  => 'Unit',
        'col_qty'   => 'Quantity',
        'col_cost'  => 'Unit Cost',
        'col_total' => 'Total Cost',
        'sig_prepared'      => 'Prepared by:',
        // Blank on purpose: left empty, these take the name and position of
        // the account that holds the Procurement Unit role, so they follow
        // whoever actually holds it. Filling them in here overrides that for
        // the fiscal year being edited.
        'sig_head_name'     => '',
        'sig_head_position' => '',
        // Total Non-PS cost = net price × (1 + 12% VAT + 3.3% logistics + 3.5% inflation) = × 1.188
        'lbl_vat'   => 'VALUE-ADDED TAX',
        'rate_vat'  => '12',
        'lbl_log'   => 'LOGISTICS',
        'rate_log'  => '3.3',
        'lbl_inf'   => 'INFLATION',
        'rate_inf'  => '3.5',
        'lbl_grand' => 'GRAND TOTAL',
    ],
    'appcse' => [
        'reminders_body' => '1.0 The APP-CSE form must be accomplished using Microsoft Excel format. The APP-CSE shall be deemed incorrect or invalid if the form used is other than the prescribed format which is downloadable from the mPhilGEPS and Downloads page of PS-DBM website (www.ps-philgeps.gov.ph).
2.0 All information must be provided accurately.
3.0 Kindly refer to the CSE catalogue on the PS-DBM website (www.ps-philgeps.gov.ph) under the "What We Sell" tab for the detailed technical specifications and sample photo of the items.
4.0 Do not delete, add, or revise any items or rows on this form, otherwise the form will be deemed invalid.
5.0 For items not included on the list of PART II, a separate form with the file name APP-CSE {FY} Form - Other Items, can be fill-out through this link: https://forms.gle/TTh7U282G72P1ArE9
6.0 Once signed and approved by the Property/Supply Officer, Accountant/Budget Officer, and Head of the Agency/Office, kindly upload the soft copy of the APP-CSE in Microsoft Excel format as well as the original signed copy in Portable Document Format (PDF) to the agency\'s mPhilGEPS account on or before the prescribed period or deadline. Any APP-CSE form that is unsigned or has incomplete signature shall be deemed invalid.
7.0 Should there be changes in the agency\'s CSE requirements, the agency may edit their uploaded APP-CSE directly on their mPhilGEPS account. However, the agency must ensure that a signed and approved copy of the supplemental APP-CSE form is available. Note that all CSE requirements in excess of the quantities indicated in the original APP-CSE form will not be served if not covered by a supplemental APP-CSE.
8.0 Please be advised that modifications of product codes may be implemented in the system without prior notification. Should such changes occur, it will be necessary for agencies with CSE requirements to edit and submit a Supplemental APP-CSE on their mPhilGEPS account.
9.0 For further assistance or clarification, agencies may contact the Marketing and Sales Division of PS-DBM through its mobile numbers 0918-2954426, 0962-8255199, 0931-1037867, 0931-1037869, or email psclient@ps-philgeps.gov.ph or visit the PS-DBM website (www.ps-philgeps.gov.ph) for the guide on how to fill-out the APP-CSE.',
        'intro_body' => 'This form contains the common-use supplies and equipment (CSE) being carried by the Procurement Service – Department of Budget and Management (PS-DBM) that shall be purchased by government agencies. Consistent with the guidelines in DBM Circulars, GPPB Resolutions, and Republic Act No. 12009 or the New Government Procurement Act (NGPA), the APP-CSE shall serve as the agency\'s annual procurement request for all its CSE requirements. Only agencies with uploaded APP-CSE in the Modernized Philippine Government Electronic Procurement System (mPhilGEPS) will be able to purchase CSE from the PS-DBM. Note that the items listed on this form have been arranged in accordance with the United Nations Standard Products and Services Code (UNSPSC).',
        'qamt_4' => 'Q4
AMOUNT',
        'qamt_3' => 'Q3
AMOUNT',
        'qamt_2' => 'Q2
AMOUNT',
        'qamt_1' => 'Q1
AMOUNT',
        'lbl_grand' => 'Grand Total (A)',
        'lbl_grand_d' => 'Grand Total (with {rate}%)',
        'lbl_part2' => 'Part II — Total',
        'lbl_part1' => 'Part I — Total',
        'note_text' => 'Note: The APP-CSE for FY {FY} must be submitted on or before 31 August {FY-1}.',
        'form_title' => 'APP-CSE {FY} FORM
ANNUAL PROCUREMENT PLAN – COMMON-USE SUPPLIES AND EQUIPMENT (APP-CSE) {FY} FORM',
        'col_price' => 'Unit Price as
of April 27,
2026',
        'col_amount' => 'Total Amount
for the year',
        'col_totqty' => 'Total
Quantity
for the year',
        'col_uom' => 'Unit of
Measure',
        'col_item' => 'Item & Specifications',
        'col_code'   => 'Code',
        'col_months' => 'Monthly Quantity Requirement',
        'intro_label' => 'Introduction:',
        'reminders_label' => 'Reminders:',
        'mon_1' => 'Jan',
        'mon_2' => 'Feb',
        'mon_3' => 'Mar',
        'mon_4' => 'April',
        'mon_5' => 'May',
        'mon_6' => 'June',
        'mon_7' => 'July',
        'mon_8' => 'Aug',
        'mon_9' => 'Sept',
        'mon_10' => 'Oct',
        'mon_11' => 'Nov',
        'mon_12' => 'Dec',
        'qtr_1' => 'Q1',
        'qtr_2' => 'Q2',
        'qtr_3' => 'Q3',
        'qtr_4' => 'Q4',
        // The summary under the table and the signatures, as on the PS-DBM form
        'sum_part1_title' => 'PART I. AVAILABLE AT PS-DBM (MAIN WAREHOUSE AND DEPOTS)',
        'sum_part2_title' => 'PART II. OTHER ITEMS NOT AVAILABLE AT PS-DBM BUT ARE REGULARLY PURCHASED FROM OTHER SOURCES',
        'sum_part2_note'  => 'Consistent with Section 4.4 of Circular Letter No. 2011-6 and 2011-6, all agencies and concerned units are enjoined to include in the APP-CSE all supplies, commodities or materials and equipment which are consumed and needed in their day-to-day operations. This shall be one of the bases for the PS-DBM in expanding the Electronic Catalogue to include other products commonly purchased by government entities.',
        'sum_a' => 'A. TOTAL',
        'rate_b' => '10',
        'sum_b' => 'B. ADDITIONAL PROVISION FOR PRICE CHANGES ({rate}% of TOTAL)',
        'sum_c' => 'C.  ADDITIONAL PROVISION FOR TRANSPORT AND FREIGHT COST (If Applicable)',
        'sum_d' => 'D. GRAND TOTAL (A + B+ C)',
        'sum_e' => 'E. APPROVED BUDGET BY THE AGENCY HEAD
In Figures and Words:',
        'sum_warrant' => 'We hereby warrant that the total amount reflected in this Annual Procurement Plan to procure the listed common-use supplies, materials, and equipment has been included in or is within our approved budget for the year.',
        'sig1_label' => 'Prepared by:',
        'sig2_label' => 'Certified Funds Available / Certified Appropriate Funds Available:',
        'sig3_label' => 'Approved by:',
        'sig3_position' => 'Head of Office/Agency',
        'sig_date_label' => 'Date Prepared:',
        'sig_date_value' => '',
    ],
    'scoping' => [
        'logo_left'  => 'assets/ascot-logo.png',
        'logo_right' => 'assets/bagong-pilipinas.png',
        'rep_line'   => 'Republic of the Philippines',
        'org_name'   => 'AURORA STATE COLLEGE OF TECHNOLOGY',
        'address'    => 'Brgy. Zabali, 3200 Baler, Aurora, Philippines',
        'doc_title'  => 'MARKET SCOPING CHECKLIST',
    ],
    'annex' => [
        'logo_left'  => 'assets/ascot-logo.png',
        'logo_right' => 'assets/bagong-pilipinas.png',
        'instr_body' => '1. Indicate the Project Name - Title of the procurement activity e.g. Conduct of Seminar on Procurement Planning
2. Indicate the General Description - Item Category of the PAP e.g. B.1 Training and Seminar
3. Indicate the NEP Account (NEP#1) - NEP Expenditure Category wherein the budget for the activity is to be taken e.g. Training and Seminar (almost the same with General Description)
4. Indicate the Allocated Budget - Amount of budget from NEP allocated for the conduct of Activity e.g. 20,000.00
5. In case NEP#1 is less than the estimated budget for the PAP, indicate the alternative NEP Account (NEP#2) wherein additional budget for the PAP shall be taken.
6. Indicate the estimated program/activity/project requirements in the the table provided in each Annex',
        'rep_line'  => 'Republic of the Philippines',
        'org_name'  => 'AURORA STATE COLLEGE OF TECHNOLOGY',
        'address'   => 'Brgy. Zabali, 3200 Baler, Aurora Philippines',
        'unit_line' => 'CASH UNIT',
        'col_items' => 'Items and Specifications',
        'col_qty'   => 'Quantity',
        'col_total' => 'Total Amount',
        'lbl_project' => 'Project Name',
        'lbl_general' => 'General Description',
        'lbl_nep' => 'NEP Account',
        'lbl_item' => 'Item of Expenditure',
        'lbl_alloc' => 'Allocated Budget',
        'lbl_dates' => 'Inclusive Date/s',
        'lbl_fy' => 'For the Fiscal Year',
        'lbl_totbudget' => 'Total Estimated Budget',
        'instr_title' => 'INSTRUCTIONS IN FILLING OUT THE ANNEX FORMS:',
    ],
];

/**
 * Wording that used to be the built-in value and was replaced. A year whose
 * format was saved while it was still the built-in keeps it stored, so it is
 * read as today's built-in value instead.
 */
const DOC_FORMAT_RETIRED = [
    'nonps' => ['lbl_grand' => ['GRAND TOTAL (TOTAL + 10%)']],
    // v94: the APP-CSE's % is set on the Document Format page; its labels follow it
    'appcse' => ['sum_b' => ['B. ADDITIONAL PROVISION FOR PRICE CHANGES (10% of TOTAL)'],
                 'reminders_body' => ['1.0 The APP-CSE form must be accomplished using Microsoft Excel format. The APP-CSE shall be deemed incorrect or invalid if the form used is other than the prescribed format which is downloadable from the mPhilGEPS and Downloads page of PS-DBM website (www.ps-philgeps.gov.ph).
2.0 All information must be provided accurately.
3.0 Kindly refer to the CSE catalogue on the PS-DBM website (www.ps-philgeps.gov.ph) under the "What We Sell" tab for the detailed technical specifications and sample photo of the items.
4.0 Do not delete, add, or revise any items or rows on this form, otherwise the form will be deemed invalid.
5.0 For items not included on the list of PART II, a separate form with the file name APP-CSE Form - Other Items, can be fill-out through this link: https://forms.gle/TTh7U282G72P1ArE9
6.0 Once signed and approved by the Property/Supply Officer, Accountant/Budget Officer, and Head of the Agency/Office, kindly upload the soft copy of the APP-CSE in Microsoft Excel format as well as the original signed copy in Portable Document Format (PDF) to the agency\'s mPhilGEPS account on or before the prescribed period or deadline. Any APP-CSE form that is unsigned or has incomplete signature shall be deemed invalid.
7.0 Should there be changes in the agency\'s CSE requirements, the agency may edit their uploaded APP-CSE directly on their mPhilGEPS account. However, the agency must ensure that a signed and approved copy of the supplemental APP-CSE form is available. Note that all CSE requirements in excess of the quantities indicated in the original APP-CSE form will not be served if not covered by a supplemental APP-CSE.
8.0 Please be advised that modifications of product codes may be implemented in the system without prior notification. Should such changes occur, it will be necessary for agencies with CSE requirements to edit and submit a Supplemental APP-CSE on their mPhilGEPS account.
9.0 For further assistance or clarification, agencies may contact the Marketing and Sales Division of PS-DBM through its mobile numbers 0918-2954426, 0962-8255199, 0931-1037867, 0931-1037869, or email psclient@ps-philgeps.gov.ph or visit the PS-DBM website (www.ps-philgeps.gov.ph) for the guide on how to fill-out the APP-CSE.'],
                 'lbl_grand_d' => ['Grand Total (with 10%)']],
];

/** Flat key => [label, kind] for one document type. */
function doc_fmt_fields(string $type): array {
    $out = [];
    foreach (DOC_FORMAT_SCHEMA[$type] ?? [] as $group => $fields) {
        foreach ($fields as $k => $meta) $out[$k] = $meta;
    }
    return $out;
}

/** Create the store on first use. Safe on every page load. */
function ensure_doc_format_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS doc_formats (
            doc_type   VARCHAR(20)  NOT NULL,
            key_name   VARCHAR(40)  NOT NULL,
            fy         INT          NOT NULL DEFAULT 0,
            value      TEXT,
            updated_at DATETIME NULL,
            updated_by VARCHAR(150) NULL,
            PRIMARY KEY (doc_type, key_name, fy)
        ) ENGINE=InnoDB");

        // Installs made before formats were kept per year have one set of
        // rows with no year. They were edited under the year in force at the
        // time, so that is the year they belong to.
        try {
            $__cols = db()->query("SHOW COLUMNS FROM doc_formats")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('fy', $__cols, true)) {
                $__fy = (int)(db()->query("SELECT value FROM settings WHERE key_name = 'active_fy'")->fetchColumn() ?: 0);
                if ($__fy <= 0) $__fy = (int)date('Y');
                db()->exec("ALTER TABLE doc_formats DROP PRIMARY KEY");
                db()->exec("ALTER TABLE doc_formats ADD COLUMN fy INT NOT NULL DEFAULT 0 AFTER key_name");
                db()->prepare("UPDATE doc_formats SET fy = ? WHERE fy = 0")->execute([$__fy]);
                db()->exec("ALTER TABLE doc_formats ADD PRIMARY KEY (doc_type, key_name, fy)");
            }
        } catch (Throwable $t) { /* already migrated, or fresh install */ }
    } catch (Throwable $t) { /* falls back to defaults */ }
}

/**
 * The fiscal year whose format this page should render with.
 *
 * A document must render with the format of ITS OWN year, so each page
 * declares that year once near the top instead of passing it to every call.
 * Left unset it falls back to the active year, which is right for a page
 * that is not showing one particular filed document.
 */
function doc_fmt_use_year(?int $fy): void {
    $GLOBALS['__doc_fmt_year'] = ($fy !== null && $fy > 1900) ? $fy : null;
}

function doc_fmt_year(): int {
    $fy = $GLOBALS['__doc_fmt_year'] ?? null;
    return $fy !== null ? (int)$fy : active_fy();
}

/**
 * Every stored override, as [doc_type][key][fy] => value.
 * Read once per request; the year is chosen when a value is looked up.
 */
function doc_fmt_overrides(): array {
    static $cache = null;
    // A write during this request clears the cache so later reads see it
    if (!empty($GLOBALS['__doc_fmt_dirty'])) { $cache = null; $GLOBALS['__doc_fmt_dirty'] = false; }
    if ($cache !== null) return $cache;
    ensure_doc_format_table();
    $cache = [];
    try {
        foreach (db()->query('SELECT doc_type, key_name, fy, value FROM doc_formats')->fetchAll() as $r) {
            $cache[$r['doc_type']][$r['key_name']][(int)$r['fy']] = (string)$r['value'];
        }
    } catch (Throwable $t) { $cache = []; }
    return $cache;
}

/** Which years this document type has a format of its own for. */
function doc_fmt_years(string $type): array {
    $years = [];
    foreach (doc_fmt_overrides()[$type] ?? [] as $byYear) {
        foreach (array_keys($byYear) as $y) $years[$y] = true;
    }
    $years = array_keys($years);
    rsort($years);
    return $years;
}

/**
 * One value, for one fiscal year.
 *
 * The year's own value wins. Failing that the nearest EARLIER year is used,
 * so a new fiscal year starts as a copy of the last one. Failing that, the
 * built-in value. A later year never reaches back into an earlier one, so a
 * filed document keeps the form it was filed under.
 */
function doc_fmt(string $type, string $key, ?int $fy = null): string {
    $fy   = $fy ?? doc_fmt_year();
    if ($type === 'appcse' && ($key === 'sum_b' || $key === 'lbl_grand_d')) return doc_fmt_rate_text(doc_fmt_raw($type, $key, $fy), $fy);
    return doc_fmt_raw($type, $key, $fy);
}

/** v94: an APP-CSE label with its % filled in: {rate} — and a "10%" left from before — becomes the year's %. */
function doc_fmt_rate_text(string $v, int $fy): string {
    $r = trim(str_replace('%', '', doc_fmt_raw('appcse', 'rate_b', $fy)));
    if ($r === '' || !is_numeric($r)) $r = '10';
    $r = rtrim(rtrim(number_format((float)$r, 4, '.', ''), '0'), '.');
    $v = str_replace('{rate}', $r, $v);
    return preg_replace('/(?<![\d.])10%/', $r . '%', $v);
}

/** One stored value as it is (doc_fmt() without filling anything in). */
function doc_fmt_raw(string $type, string $key, int $fy): string {
    $over = doc_fmt_overrides()[$type][$key] ?? [];

    if ($over) {
        $best = null;
        foreach (array_keys($over) as $y) {
            if ($y <= $fy && ($best === null || $y > $best)) $best = $y;
        }
        if ($best !== null) {
            $v = $over[$best];
            if (in_array($v, DOC_FORMAT_RETIRED[$type][$key] ?? [], true)) return DOC_FORMAT_DEFAULTS[$type][$key] ?? '';
            // A deliberately blank override is respected (e.g. "no right logo")
            if ($v !== '' || in_array($key, ['logo_left', 'logo_right'], true)) return $v;
        }
    }
    return DOC_FORMAT_DEFAULTS[$type][$key] ?? '';
}

/** Every value for one document type. */
function doc_fmt_all(string $type, ?int $fy = null, bool $raw = false): array {
    $out = [];
    foreach (array_keys(doc_fmt_fields($type)) as $k) $out[$k] = $raw ? doc_fmt_raw($type, $k, $fy ?? doc_fmt_year()) : doc_fmt($type, $k, $fy);
    return $out;
}

/** True when this value differs from the built-in one. */
/** True when THIS year has a value of its own that differs from the built-in. */
function doc_fmt_is_custom(string $type, string $key, ?int $fy = null): bool {
    $fy = $fy ?? doc_fmt_year();
    $v  = doc_fmt_overrides()[$type][$key][$fy] ?? null;
    if ($v !== null && in_array($v, DOC_FORMAT_RETIRED[$type][$key] ?? [], true)) return false;
    return $v !== null && $v !== (DOC_FORMAT_DEFAULTS[$type][$key] ?? '');
}

/** True when anything on this document has been changed. */
function doc_fmt_type_is_custom(string $type, ?int $fy = null): bool {
    foreach (array_keys(doc_fmt_fields($type)) as $k) {
        if (doc_fmt_is_custom($type, $k, $fy)) return true;
    }
    return false;
}

/** A logo's src, cache-stamped so a replacement shows immediately. '' if none. */
function doc_logo(string $type, string $side, ?int $fy = null): string {
    $path = doc_fmt($type, $side === 'right' ? 'logo_right' : 'logo_left', $fy);
    if ($path === '') return '';
    $v = @filemtime(__DIR__ . '/../' . ltrim($path, '/'));
    return $path . ($v ? '?v=' . $v : '');
}

/** Save one value. null removes the override, restoring the built-in. */
/**
 * Pin a year before its first edit.
 *
 * Writes out every value this year currently renders with, so the year is
 * independent from then on. Without this a later correction to an earlier
 * year would quietly reach forward into the years that inherited from it.
 */
function doc_fmt_ensure_year(string $type, int $fy, string $who): void {
    ensure_doc_format_table();
    if (!isset(DOC_FORMAT_DEFAULTS[$type])) return;
    foreach (doc_fmt_overrides()[$type] ?? [] as $byYear) {
        if (isset($byYear[$fy])) return;          // this year is already pinned
    }
    $ins = db()->prepare('INSERT IGNORE INTO doc_formats (doc_type, key_name, fy, value, updated_at, updated_by)
                          VALUES (?, ?, ?, ?, NOW(), ?)');
    foreach (array_keys(doc_fmt_fields($type)) as $k) {
        $ins->execute([$type, $k, $fy, doc_fmt_raw($type, $k, $fy), $who]);
    }
    doc_fmt_reset_cache();
}

/** Forget the cached overrides after a write. */
function doc_fmt_reset_cache(): void {
    $GLOBALS['__doc_fmt_dirty'] = true;
}

function doc_fmt_save(string $type, string $key, ?string $value, string $who, ?int $fy = null): void {
    ensure_doc_format_table();
    $fy = $fy ?? active_fy();
    if (!isset(DOC_FORMAT_DEFAULTS[$type]) || !isset(doc_fmt_fields($type)[$key])) return;
    doc_fmt_reset_cache();
    if ($value === null) {
        db()->prepare('DELETE FROM doc_formats WHERE doc_type = ? AND key_name = ? AND fy = ?')
            ->execute([$type, $key, $fy]);
    } else {
        db()->prepare('INSERT INTO doc_formats (doc_type, key_name, fy, value, updated_at, updated_by)
                            VALUES (?, ?, ?, ?, NOW(), ?)
                       ON DUPLICATE KEY UPDATE value = VALUES(value),
                                               updated_at = VALUES(updated_at),
                                               updated_by = VALUES(updated_by)')
            ->execute([$type, $key, $fy, $value, $who]);
    }
}

/** When this document was last restyled, and by whom. */
function doc_fmt_touched(string $type, ?int $fy = null): ?array {
    ensure_doc_format_table();
    $fy = $fy ?? active_fy();
    try {
        $s = db()->prepare('SELECT updated_at, updated_by FROM doc_formats
                             WHERE doc_type = ? AND fy = ? AND updated_at IS NOT NULL
                          ORDER BY updated_at DESC LIMIT 1');
        $s->execute([$type, $fy]);
        return $s->fetch() ?: null;
    } catch (Throwable $t) { return null; }
}


/**
 * A plain-text format value rendered for HTML: escaped, with new lines
 * turned into line breaks. {FY} and {FY-1} are filled in when a year is
 * given. Use for headings, titles and short notes.
 */
function doc_nl(string $type, string $key, ?int $fy = null): string {
    $v = doc_fmt($type, $key, $fy);
    if ($fy !== null) $v = str_replace(['{FY-1}', '{FY}'], [(string)($fy - 1), (string)$fy], $v);
    return nl2br(htmlspecialchars($v, ENT_QUOTES, 'UTF-8'), false);
}

/**
 * A multi-line plain-text value rendered as one <div> per line — used for
 * the numbered lists (APP-CSE reminders, Annex instructions). Escaped, so
 * nothing typed into the Document Format page can inject markup.
 */
function doc_block(string $type, string $key, ?int $fy = null): string {
    $v = doc_fmt($type, $key, $fy);
    if ($fy !== null) $v = str_replace(['{FY-1}', '{FY}'], [(string)($fy - 1), (string)$fy], $v);
    $out = '';
    foreach (preg_split('/\r?\n/', $v) as $line) {
        $line = trim($line);
        if ($line !== '') $out .= '<div>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return $out;
}

/**
 * v94: the logos at the top of a centred letterhead (Non-PS): the left one as
 * before, and the right one beside it when the Document Format page has one.
 * A logo set to "no image" is left out instead of showing a broken picture.
 */
function doc_logo_pair(string $type, string $imgAttrs, ?int $fy = null): string {
    $l = doc_logo($type, 'left', $fy);
    $r = doc_logo($type, 'right', $fy);
    $img = fn($src) => '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="ASCOT" ' . $imgAttrs . '>';
    if ($r === '') return $l === '' ? '' : $img($l);
    return '<div class="doc-logos" style="display:flex; justify-content:center; align-items:center; gap:18px;">'
         . ($l !== '' ? $img($l) : '') . $img($r) . '</div>';
}
