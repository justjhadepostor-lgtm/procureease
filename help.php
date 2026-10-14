<?php
/* ═══════════════════════════════════════════════════════════════
   Help & documentation — the user guide, for every role.

   The guide is kept in config/help-guide.php: the built-in one, or the
   version the Super Admin saved with "✎ Edit the guide" on this page —
   which everyone then sees. The numbers it quotes (auto-save delay,
   session timeout, password length) and the contacts come from the
   Settings page as {{tokens}}, so they are always the ones in force.
   =============================================================== */
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/help-guide.php';
require_login();
$u = current_user();
$activeNav = 'help';
$pageTitle = 'Help';
$role = $u['role'] ?? 'user';
$canEdit = $role === 'admin';

[$S, $meta] = help_guide();
?>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<style>
  .help-top { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
  .help-sec { margin-bottom: 14px; scroll-margin-top: 90px; }
  .help-sec h2 { font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 700; color: var(--ink); margin: 0 0 4px; }
  .help-sec .who { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin-bottom: 10px; }
  .help-body { font-size: 13.5px; color: var(--ink); line-height: 1.7; }
  .help-body p { margin: 0 0 8px; }
  .help-body ul, .help-body ol { margin: 0 0 8px 20px; }
  .help-body li { margin-bottom: 4px; }
  .help-body h3 { font-size: 14px; font-weight: 700; margin: 10px 0 6px; color: var(--ink); }
  .help-body a { color: var(--navy-700); font-weight: 600; }
  body[data-theme="dark"] .help-body a { color: #9fd0ff; }
  .help-none { display: none; text-align: center; color: var(--muted); padding: 30px; }

  /* ── Editing the guide (Super Admin) ── */
  .help-meta { font-size: 12px; color: var(--muted); margin-top: 6px; }
  .he-bar { position: sticky; top: 8px; z-index: 5; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
            padding: 12px 16px; margin-bottom: 14px; border: 1px solid var(--border); border-radius: 12px;
            background: var(--white); box-shadow: 0 8px 24px rgba(15, 43, 74, .12); }
  .he-bar .he-note { font-size: 12.5px; color: var(--muted); flex: 1 1 240px; }
  .he-sec { margin-bottom: 14px; }
  .he-head { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 220px) auto; gap: 8px; align-items: end; margin-bottom: 8px; }
  .he-head label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin-bottom: 3px; }
  .he-head input { width: 100%; }
  .he-move { display: flex; gap: 6px; }
  .he-move .btn { padding: 7px 10px; font-size: 12.5px; }
  .he-tools { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; padding: 6px; border: 1px solid var(--border);
              border-bottom: none; border-radius: 10px 10px 0 0; background: var(--navy-50); }
  .he-tools button { min-width: 32px; height: 30px; padding: 0 8px; border: 1px solid transparent; border-radius: 7px;
                     background: transparent; color: var(--ink); font: inherit; font-size: 13px; cursor: pointer; }
  .he-tools button:hover, .he-tools button:focus-visible { border-color: var(--border); background: var(--white); }
  .he-tools select { height: 30px; font-size: 12.5px; padding: 0 8px; border-radius: 7px; border: 1px solid var(--border);
                     background: var(--white); color: var(--ink); margin-left: auto; max-width: 230px; }
  .he-body { min-height: 110px; padding: 12px 14px; border: 1px solid var(--border); border-radius: 0 0 10px 10px;
             background: var(--white); outline: none; }
  .he-body:focus { border-color: var(--navy-500); box-shadow: 0 0 0 3px rgba(30, 82, 133, .15); }
  .he-err { display: none; margin-bottom: 12px; }
  #helpView[hidden], #helpEdit[hidden] { display: none !important; }
  @media (max-width: 720px) { .he-head { grid-template-columns: 1fr; } }
</style>
<div class="app">
<?php
  if ($role === 'admin')       require __DIR__ . '/includes/sidebar-admin.php';
  elseif ($role === 'budget')  require __DIR__ . '/includes/sidebar-budget.php';
  else                         require __DIR__ . '/includes/sidebar-user.php';
?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Help &amp; Documentation</h1>
        <p class="page-sub">How to use ProcureEase — for departments, the Budget Unit and the Procurement Unit.</p>
        <?php if ($canEdit): ?>
          <div class="help-meta" id="helpMeta">
            <?= $meta ? 'Edited by ' . e($meta['updated_by'] ?: 'the Super Admin') . ($meta['updated_at'] !== '' ? ' on ' . e(date('M j, Y \a\t g:i A', strtotime($meta['updated_at']))) : '') . '. Everyone sees this version.'
                      : 'The built-in guide. Edit it to change what everyone sees.' ?>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($canEdit): ?>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <button type="button" class="btn btn-primary" id="helpEditBtn" style="padding:9px 16px; font-size:13px;">✎ Edit the guide</button>
        </div>
      <?php endif; ?>
    </div>
    <?php render_flashes(); ?>

    <div id="helpView">
      <div class="help-top" role="search">
        <?= search_field(['id' => 'helpFind', 'placeholder' => 'Search the guide…', 'label' => 'Search the guide']) ?>
        <span id="helpCount" style="font-size:12.5px; color:var(--muted);"></span>
      </div>

      <?php foreach ($S as $s): ?>
        <section class="card help-sec" id="<?= e($s['id']) ?>">
          <h2><?= e($s['title']) ?></h2>
          <div class="who">For: <?= e($s['who']) ?></div>
          <div class="help-body"><?= help_fill($s['html'], $u) ?></div>
        </section>
      <?php endforeach; ?>
      <div class="card help-none" id="helpNone">Nothing in the guide matches that. Try another word, or ask Support.</div>
    </div>

    <?php if ($canEdit): ?>
      <div id="helpEdit" hidden>
        <div class="he-bar">
          <button type="button" class="btn btn-primary" id="heSave" style="padding:9px 18px; font-size:13px;">💾 Save the guide</button>
          <button type="button" class="btn btn-ghost" id="heCancel" style="padding:9px 14px; font-size:13px;">Cancel</button>
          <span class="he-note" id="heNote">What you save here is what every user sees on Help. Values in {{ }} are filled in from Settings.</span>
          <form method="POST" action="api/save-help.php" style="margin:0;"
                data-confirm="The guide goes back to the one that came with ProcureEase, for everyone. Your edits are removed."
                data-confirm-title="Reset the Help guide?" data-confirm-label="Reset" data-confirm-tone="danger">
            <input type="hidden" name="action" value="reset">
            <button type="submit" class="btn btn-ghost" id="heReset" style="padding:9px 14px; font-size:13px;"<?= $meta ? '' : ' disabled title="The built-in guide is already in use"' ?>>Reset to the built-in guide</button>
          </form>
        </div>
        <div class="flash flash-error he-err" id="heErr" role="alert"></div>
        <div id="heList"></div>
        <button type="button" class="btn btn-ghost" id="heAdd" style="padding:9px 16px; font-size:13px;">＋ Add a part</button>
        <datalist id="heWho"><?php foreach (HELP_AUDIENCES as $a): ?><option value="<?= e($a) ?>"><?php endforeach; ?></datalist>
      </div>
      <script type="application/json" id="helpRaw"><?= json_encode(array_map(fn($s) => ['id' => $s['id'], 'title' => $s['title'], 'who' => $s['who'], 'html' => trim($s['html'])], $S), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
      <script type="application/json" id="helpTokens"><?= json_encode(HELP_TOKENS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endif; ?>
  </main>
</div>
<script>
  // Search the guide: show only the parts that mention every word typed
  (function(){
    const box = document.getElementById('helpFind'), secs = [...document.querySelectorAll('.help-sec')],
          none = document.getElementById('helpNone'), count = document.getElementById('helpCount');
    box.addEventListener('input', function(){
      const words = box.value.toLowerCase().split(/\s+/).filter(Boolean); let shown = 0;
      secs.forEach(function(s, i){
        const hit = words.every(w => s.textContent.toLowerCase().includes(w));
        s.style.display = hit ? '' : 'none'; if (hit) shown++;
      });
      none.style.display = shown ? 'none' : 'block';
      count.textContent = words.length ? shown + ' of ' + secs.length + ' parts' : '';
    });
  })();
</script>
<?php if ($canEdit): ?>
<script>
  // ✎ Edit the guide — each part: its title, who it is for, and the text
  (function(){
    const raw = JSON.parse(document.getElementById('helpRaw').textContent || '[]');
    const tokens = JSON.parse(document.getElementById('helpTokens').textContent || '{}');
    const view = document.getElementById('helpView'), edit = document.getElementById('helpEdit'),
          list = document.getElementById('heList'), err = document.getElementById('heErr'),
          btnEdit = document.getElementById('helpEditBtn'), btnSave = document.getElementById('heSave');
    let dirty = false;

    const TOOLS = [['bold', '<b>B</b>', 'Bold'], ['italic', '<i>I</i>', 'Italic'], ['underline', '<u>U</u>', 'Underline'],
                   ['h3', 'H', 'Small heading'], ['p', '¶', 'Normal text'],
                   ['insertUnorderedList', '• List', 'Bulleted list'], ['insertOrderedList', '1. List', 'Numbered list'],
                   ['createLink', '🔗 Link', 'Make the selected words a link'], ['unlink', 'Unlink', 'Remove the link'],
                   ['removeFormat', 'Clear', 'Clear the formatting of the selected words']];

    function part(s){
      const sec = document.createElement('section');
      sec.className = 'card he-sec'; sec.dataset.id = s.id || '';
      sec.innerHTML =
        '<div class="he-head">' +
          '<div><label>Title</label><input type="text" class="form-input he-title" maxlength="120" aria-label="Title of this part"></div>' +
          '<div><label>For</label><input type="text" class="form-input he-who" maxlength="60" list="heWho" aria-label="Who this part is for"></div>' +
          '<div class="he-move">' +
            '<button type="button" class="btn btn-ghost he-up" title="Move this part up" aria-label="Move up">↑</button>' +
            '<button type="button" class="btn btn-ghost he-down" title="Move this part down" aria-label="Move down">↓</button>' +
            '<button type="button" class="btn btn-ghost he-del" title="Remove this part">Remove</button>' +
          '</div></div>' +
        '<div class="he-tools" role="toolbar" aria-label="Formatting"></div>' +
        '<div class="help-body he-body" contenteditable="true" role="textbox" aria-multiline="true"></div>';
      sec.querySelector('.he-title').value = s.title || '';
      sec.querySelector('.he-who').value = s.who || 'Everyone';
      const body = sec.querySelector('.he-body'); body.innerHTML = s.html || '<p></p>';
      body.setAttribute('aria-label', 'Text of ' + (s.title || 'this part'));
      const bar = sec.querySelector('.he-tools');
      TOOLS.forEach(function(t){
        const b = document.createElement('button'); b.type = 'button'; b.innerHTML = t[1]; b.title = t[2]; b.setAttribute('aria-label', t[2]);
        b.addEventListener('mousedown', function(e){ e.preventDefault(); });   // keep the selection in the text
        b.addEventListener('click', function(){
          body.focus();
          if (t[0] === 'h3' || t[0] === 'p') document.execCommand('formatBlock', false, t[0]);
          else if (t[0] === 'createLink') {
            const url = prompt('Link to (https://…, or mailto:someone@example.com):', 'https://');
            if (url && /^(https?:\/\/|mailto:|#)/i.test(url.trim())) document.execCommand('createLink', false, url.trim());
          } else document.execCommand(t[0], false, null);
          dirty = true;
        });
        bar.appendChild(b);
      });
      const pick = document.createElement('select');
      pick.setAttribute('aria-label', 'Insert a value from Settings');
      pick.innerHTML = '<option value="">Insert a value…</option>' + Object.keys(tokens).map(function(k){
        return '<option value="' + k.replace(/"/g, '&quot;') + '">' + k.replace(/[{}]/g, '') + ' — ' + tokens[k].replace(/</g, '&lt;') + '</option>'; }).join('');
      pick.addEventListener('change', function(){
        if (!pick.value) return; body.focus(); document.execCommand('insertText', false, pick.value); pick.value = ''; dirty = true;
      });
      bar.appendChild(pick);
      // Pasted text comes in as plain words, so the guide keeps its own look
      body.addEventListener('paste', function(e){
        const t = (e.clipboardData || window.clipboardData).getData('text/plain'); e.preventDefault();
        document.execCommand('insertText', false, t);
      });
      sec.addEventListener('input', function(){ dirty = true; err.style.display = 'none'; });
      sec.querySelector('.he-up').addEventListener('click', function(){ if (sec.previousElementSibling) { list.insertBefore(sec, sec.previousElementSibling); dirty = true; } });
      sec.querySelector('.he-down').addEventListener('click', function(){ if (sec.nextElementSibling) { list.insertBefore(sec.nextElementSibling, sec); dirty = true; } });
      sec.querySelector('.he-del').addEventListener('click', function(){
        swiftConfirm({title: 'Remove this part?', text: '"' + (sec.querySelector('.he-title').value || 'Untitled') + '" is taken out of the guide when you save.',
                      confirmLabel: 'Remove', tone: 'danger', onConfirm: function(){ sec.remove(); dirty = true; }});
      });
      return sec;
    }
    function open(){
      list.innerHTML = ''; raw.forEach(function(s){ list.appendChild(part(s)); });
      view.hidden = true; edit.hidden = false; btnEdit.style.display = 'none'; dirty = false; err.style.display = 'none';
    }
    function close(){ view.hidden = false; edit.hidden = true; btnEdit.style.display = ''; dirty = false; }
    btnEdit.addEventListener('click', open);
    document.getElementById('heCancel').addEventListener('click', function(){
      if (!dirty) { close(); return; }
      swiftConfirm({title: 'Leave without saving?', text: 'Your changes to the guide are not kept.', confirmLabel: 'Leave', tone: 'danger', onConfirm: close});
    });
    document.getElementById('heAdd').addEventListener('click', function(){
      const sec = part({id: '', title: '', who: 'Everyone', html: '<p></p>'}); list.appendChild(sec);
      sec.querySelector('.he-title').focus(); dirty = true;
    });
    function fail(msg){ err.textContent = msg; err.style.display = 'block'; err.scrollIntoView({block: 'center'}); }
    btnSave.addEventListener('click', function(){
      const sections = [...list.querySelectorAll('.he-sec')].map(function(sec){
        return {id: sec.dataset.id, title: sec.querySelector('.he-title').value, who: sec.querySelector('.he-who').value,
                html: sec.querySelector('.he-body').innerHTML};
      });
      if (!sections.length) { fail('The guide needs at least one part. Use "Reset to the built-in guide" to start again.'); return; }
      const blank = sections.findIndex(function(s){ return !s.title.trim(); });
      if (blank > -1) { fail('Part ' + (blank + 1) + ' needs a title.'); list.children[blank].querySelector('.he-title').focus(); return; }
      btnSave.disabled = true; btnSave.textContent = 'Saving…';
      fetch('api/save-help.php', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
                                  body: JSON.stringify({sections: sections})})
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (j && j.ok) { dirty = false; location.replace('help.php?guide_updated=' + Date.now()); return; }
          fail((j && j.error) || 'Could not save the guide.');
        })
        .catch(function(){ fail('Could not reach the server. Nothing was saved; try again.'); })
        .finally(function(){ btnSave.disabled = false; btnSave.textContent = '💾 Save the guide'; });
    });
    window.addEventListener('beforeunload', function(e){ if (dirty && !edit.hidden) { e.preventDefault(); e.returnValue = ''; } });
  })();
</script>
<?php endif; ?>
</body>
</html>
