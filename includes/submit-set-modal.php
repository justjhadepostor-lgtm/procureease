<?php
/* The Submit window on the APP-CSE, Non-PS list, Annex and Market Scoping
   pages — the same one the PPMP page shows (config/submit-set.php).

   The page sets window.submitSetSave (saves what is on screen; resolves
   true when saved) and calls openSubmitSet(event) from its Submit button.
   The window is filled from api/submit-set.php after saving, so its ✓ and ✗
   are what was just typed. Submit posts to api/submit-ppmp.php, which checks
   everything again.
     $submitSetFy — the fiscal year of the set
     $submitSetAction — v112: where Submit posts (an end user: api/submit-to-head.php) */
?>
<style>
<?php require __DIR__ . '/submit-set-css.php'; ?>
  #submitModal .modal-box { max-height:90vh; overflow-y:auto; }
</style>
<div class="modal-overlay" id="submitModal" onclick="if(event.target===this)closeSubmitSet()">
  <div class="modal-box" style="max-width:560px;" role="dialog" aria-modal="true">
    <div id="submitSetBody"></div>
    <div class="modal-actions">
      <button type="button" class="modal-btn modal-btn-cancel" onclick="closeSubmitSet()">Not yet</button>
      <button type="button" class="modal-btn modal-btn-confirm" id="submitConfirm" onclick="submitSetGo()" disabled>✓ Submit</button></div></div></div>
<form id="submitSetForm" method="POST" action="<?= htmlspecialchars($submitSetAction ?? 'api/submit-ppmp.php', ENT_QUOTES, 'UTF-8') ?>" style="display:none;">
  <input type="hidden" name="ppmp_id" value="">
  <input type="hidden" name="fy" value="<?= (int)$submitSetFy ?>"></form>
<script>
(function(){
  const FY_SET = <?= (int)$submitSetFy ?>;
  let busy = false;
  window.closeSubmitSet = function(){ document.getElementById('submitModal').classList.remove('active'); };
  window.openSubmitSet = async function(ev){
    if (ev) ev.preventDefault();
    if (busy) return false;
    busy = true;
    const btn = ev && ev.currentTarget ? ev.currentTarget : null;
    const label = btn ? btn.textContent : '';
    if (btn) { btn.setAttribute('aria-busy', 'true'); btn.textContent = 'Checking…'; }
    try {
      // Save what is on this page first, so the check sees it
      if (typeof window.submitSetSave === 'function') {
        const saved = await window.submitSetSave();
        if (saved === false) return false;           // the save has already said why
      }
      const r = await fetch('api/submit-set.php?fy=' + FY_SET, { credentials: 'same-origin', cache: 'no-store' });
      const d = await r.json();
      if (!d.ok) {
        alert(d.error || 'This cannot be submitted now.');
        return false;
      }
      document.getElementById('submitSetBody').innerHTML = d.html;
      document.querySelector('#submitSetForm [name="ppmp_id"]').value = d.ppmp_id;
      const go = document.getElementById('submitConfirm');
      go.disabled = !d.ready; go.textContent = '✓ Submit';
      document.getElementById('submitModal').classList.add('active');
    } catch (e) {
      alert('Could not check your documents. Check your connection and try again.');
    } finally {
      busy = false;
      if (btn) { btn.removeAttribute('aria-busy'); btn.textContent = label; }
    }
    return false;
  };
  window.submitSetGo = function(){
    const go = document.getElementById('submitConfirm');
    go.disabled = true; go.textContent = 'Submitting…';
    document.getElementById('submitSetForm').submit();
  };
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') window.closeSubmitSet(); });
})();
</script>
