<?php /* The Submit window's look — the PPMP page and the four documents that open the same window (config/submit-set.php). Printed inside a <style> block. */ ?>
  /* Modal */
  .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:200; align-items:center; justify-content:center; }
  .modal-overlay.active { display:flex; }
  .modal-box { background:white; border-radius:12px; padding:24px 28px; max-width:480px; width:92%; box-shadow:0 12px 40px rgba(0,0,0,0.25); font-family:'Inter',sans-serif; }
  .modal-title { font-family:'Space Grotesk',sans-serif; font-size:18px; font-weight:700; color:#4A7AAD; margin-bottom:10px; }
  .modal-text { font-size:14px; color:#444; line-height:1.6; margin-bottom:18px; }
  .modal-actions { display:flex; gap:10px; justify-content:flex-end; }
  .modal-btn { padding:9px 18px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; border:none; font-family:inherit; }
  .modal-btn-cancel { background:#f3f4f6; color:#444; }
  .modal-btn-confirm { background:#15803d; color:white; }
  .modal-btn-confirm:hover { background:var(--green-500); }
  .modal-btn-confirm:disabled { background:#e2e8f0; color:#475569; cursor:not-allowed; }
  #submitModal .modal-title { color:#1c4c86; }                     /* 3.67:1 -> 8.4:1 */
  body[data-theme="dark"] #submitModal .modal-title { color:#cfe4f0; }
  body[data-theme="dark"] #submitModal .modal-text,
  body[data-theme="dark"] #submitModal .modal-text strong { color:#e8f0f7; }
  body[data-theme="dark"] #submitModal .set-block { color:#ffd2b0; }
  /* The set that goes with the PPMP */
  .set-list { list-style:none; margin:0 0 14px; padding:0; border:1px solid #d7dee6; border-radius:10px; overflow:hidden; }
  .set-list li { display:grid; grid-template-columns:22px 150px 1fr; gap:8px; align-items:baseline; padding:8px 12px;
                 font-size:12.5px; border-top:1px solid #e5eaf0; color:#133458; background:#fff; }
  .set-list li:first-child { border-top:none; }
  .set-list .set-mark { font-weight:700; }
  .set-list li.ok .set-mark { color:#15803d; }
  .set-list li.miss { background:#fff7ed; }
  .set-list li.miss .set-mark, .set-list li.miss .set-note { color:#9a3412; }
  /* Below the allotment, or no allotment yet: can still be submitted */
  .set-list li.warn { background:#fffbeb; }
  .set-list li.warn .set-mark, .set-list li.warn .set-note { color:#854d0e; }
  .set-list .set-name { font-weight:700; }
  .set-list .set-note { color:#516678; }
  .set-list .set-note a { color:#1c4c86; font-weight:700; }
  .set-block { font-size:12.5px; color:#9a3412; margin:-4px 0 14px; }
  @media (max-width: 560px) { .set-list li { grid-template-columns:22px 1fr; } .set-list .set-note { grid-column:2; } }
