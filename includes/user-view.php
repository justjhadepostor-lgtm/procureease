<?php
/* ═══════════════════════════════════════════════════════════════
   v104: User Management › View — a window with what the user filled in
   on their Profile. Superadmin can edit profile, department, role and username.

   Used by pending-users.php; reads $activeUsers (that page's list).
   =============================================================== */
$__uvData = [];
foreach (($activeUsers ?? []) as $__vu) {
    // Older accounts have only the display name — split it the way Profile does
    $__f = trim((string)($__vu['first_name'] ?? ''));
    $__m = trim((string)($__vu['middle_initial'] ?? ''));
    $__l = trim((string)($__vu['last_name'] ?? ''));
    if ($__f === '' && $__l === '' && trim((string)($__vu['full_name'] ?? '')) !== '') {
        $__p = preg_split('/\s+/', trim((string)$__vu['full_name']));
        $__f = array_shift($__p) ?: '';
        $__l = $__p ? array_pop($__p) : '';
        $__m = $__p ? implode(' ', $__p) : '';
    }
    // Contact in the 11-digit form Profile shows
    $__c = preg_replace('/\D/', '', (string)($__vu['contact'] ?? ''));
    if (strlen($__c) === 12 && strpos($__c, '63') === 0) $__c = '0' . substr($__c, 2);
    if (strlen($__c) === 10 && $__c[0] === '9')          $__c = '0' . $__c;
    $__dc  = (string)($__vu['dept_code'] ?? '');
    $__sub = trim((string)($__vu['sub_unit'] ?? ''));
    $__au  = $__dc !== '' && function_exists('au_has_units') && au_has_units($__dc);
    $__bd  = trim((string)($__vu['birthday'] ?? ''));
    $__uvData[(int)$__vu['id']] = [
        'avatar'   => (string)($__vu['avatar_path'] ?? ''),
        'pos'      => (string)($__vu['avatar_pos'] ?? '50% 50%') ?: '50% 50%',
        'initials' => initials((string)($__vu['full_name'] ?? '')),
        'name'     => (string)($__vu['full_name'] ?? ''),
        'first'    => $__f, 'mi' => $__m, 'last' => $__l,
        'suffix'   => (string)($__vu['suffix'] ?? ''),
        'sex'      => (string)($__vu['sex'] ?? ''),
        'birthday' => ($__bd !== '' && strtotime($__bd)) ? date('Y-m-d', strtotime($__bd)) : '',
        'position' => (string)($__vu['position'] ?? ''),
        'email'    => (string)($__vu['email'] ?? ''),
        'contact'  => $__c,
        'deptName' => (string)($__vu['dept_name'] ?? '') ?: $__dc,
        'deptCode' => $__dc,
        'dept'     => ($__vu['dept_name'] ?? '') ? $__vu['dept_name'] . ' (' . $__dc . ')' : $__dc,
        // a school with programs: its Bachelor / Master (Assignment), as on Profile;
        // otherwise a program / major the account has (Account information)
        'auSchool' => $__au,
        'program'  => $__au ? ($__sub !== '' ? au_program_label($__dc, $__sub) : 'Whole school (all programs)') : $__sub,
        'role'     => role_label((string)($__vu['role'] ?? '')),
        'roleRaw'  => (string)($__vu['role'] ?? 'user'),
        'username' => (string)($__vu['username'] ?? ''),
        'status'   => ucfirst((string)($__vu['status'] ?? 'active')),
    ];
}
?>
<style>
  .uv-who { display: flex; align-items: center; gap: 10px; }
  .uv-av { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border); flex: none; }
  .uv-ini { background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; border: none;
            font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; }
  .uv-btn { padding: 2px 10px !important; font-size: 11px !important; color: var(--ink); margin-left: 8px; vertical-align: middle; }
  #userViewModal .modal { max-width: 760px; padding: 26px 28px; }
  #userViewModal .uv-head { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; margin-bottom: 16px; }
  #userViewModal .uv-photo { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border); }
  #userViewModal .uv-photo-ini { width: 72px; height: 72px; border-radius: 50%; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center;
                                 font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 26px; }
  #userViewModal .modal-close { font-size: 26px; line-height: 1; }
  #userViewModal [hidden] { display: none !important; }   /* the photo or the initials — never both */
  body[data-theme="dark"] #userViewModal .modal { background: #0b2440 !important; border: 1px solid rgba(255,255,255,.08); }
  @media (max-width: 640px) { #userViewModal .modal { padding: 20px 16px; } }
</style>
<div class="modal-overlay" id="userViewModal" role="dialog" aria-modal="true" aria-labelledby="uvTitle">
  <div class="modal">
    <form method="post" id="uvEditForm"><input type="hidden" name="action" value="update_user_info"><input type="hidden" name="edit_user_id" id="uvEditId">
    <div class="modal-header" style="margin-bottom: 14px;">
      <div>
        <div class="modal-title" id="uvTitle">User profile</div>
        <div class="modal-sub" style="margin-bottom: 0;">Edit the user profile and account information</div>
      </div>
      <button type="button" class="modal-close" data-uv-close aria-label="Close">&times;</button>
    </div>
    <div class="card uv-head">
      <img id="uvPhoto" class="uv-photo" alt="Profile picture" hidden>
      <div id="uvPhotoIni" class="uv-photo-ini" aria-hidden="true" hidden></div>
      <div style="flex: 1; min-width: 200px;">
        <div id="uvName" style="font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 600;"></div>
        <div id="uvSub" style="font-size: 14px; color: var(--muted); margin-top: 4px;"></div>
        <div id="uvMeta" style="font-size: 12px; color: var(--muted); margin-top: 6px;"></div>
      </div>
      <div class="form-grid" id="uvTopEdit" style="flex-basis:100%;"></div>
    </div>
    <div class="card" style="margin-bottom: 16px;">
      <div class="card-title">Account information</div>
      <div class="form-grid" id="uvInfo"></div>
    </div>
    <div class="card">
      <div class="card-title">Assignment &amp; sign-in</div>
      <div class="form-grid" id="uvAssign"></div>
    </div>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" data-uv-close style="padding: 9px 22px;">Cancel</button><button type="submit" class="btn btn-primary" style="padding: 9px 22px;">Save changes</button></div>
    </form>
  </div>
</div>
<script>
(function () {
  var USERS = <?= json_encode($__uvData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var modal = document.getElementById('userViewModal');
  if (!modal) return;
  var last = null;
  var departments = <?= json_encode(array_map(static function($d){ return ['code'=>(string)$d['code'],'name'=>(string)$d['name']]; }, $editDepartments ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  function field(box, label, value, name, type) {
    var d = document.createElement('div');
    var l = document.createElement('label'); l.className = 'form-label'; l.textContent = label;
    var i = document.createElement('input'); i.type = type || 'text'; i.className = 'form-input'; i.name = name;
    i.value = value == null ? '' : String(value); i.setAttribute('aria-label', label);
    if (name === 'sex') { i = document.createElement('select'); i.className = 'form-input'; i.name = name; [['','Select'],['Female','Female'],['Male','Male'],['Prefer not to say','Prefer not to say']].forEach(function(o){ var op=document.createElement('option'); op.value=o[0]; op.textContent=o[1]; i.appendChild(op); }); i.value=value || ''; }
    d.appendChild(l); d.appendChild(i); box.appendChild(d);
  }
  function departmentField(box, u) {
    var d=document.createElement('div'), l=document.createElement('label'), s=document.createElement('select');
    l.className='form-label'; l.textContent='Department'; s.className='form-input'; s.name='dept_code';
    departments.forEach(function(dep){ var o=document.createElement('option'); o.value=dep.code; o.textContent=dep.name+' ('+dep.code+')'; s.appendChild(o); });
    s.value=u.deptCode || ''; d.appendChild(l); d.appendChild(s); box.appendChild(d);
  }
  function open(id, btn) {
    var u = USERS[id]; if (!u) return;
    last = btn || null;
    document.getElementById('uvEditId').value = id;
    var img = document.getElementById('uvPhoto'), ini = document.getElementById('uvPhotoIni');
    if (u.avatar) { img.src = u.avatar; img.style.objectPosition = u.pos; img.hidden = false; ini.hidden = true; }
    else { img.removeAttribute('src'); img.hidden = true; ini.textContent = u.initials; ini.hidden = false; }
    document.getElementById('uvName').textContent = u.name;
    document.getElementById('uvSub').textContent = (u.position || '—') + ' · ' + u.deptName;
    document.getElementById('uvMeta').textContent = 'Role: ' + u.role + ' · Username: ' + u.username;
    var info = document.getElementById('uvInfo'), as = document.getElementById('uvAssign'), topEdit = document.getElementById('uvTopEdit');
    info.innerHTML = ''; as.innerHTML = ''; topEdit.innerHTML = '';
    // Keep the main identity fields editable in the top section, where users expect them.
    field(topEdit, 'First name', u.first, 'first_name'); field(topEdit, 'Middle initial', u.mi, 'middle_initial');
    field(topEdit, 'Last name', u.last, 'last_name'); field(topEdit, 'Position', u.position, 'position');
    field(info, 'Suffix', u.suffix, 'suffix');
    field(info, 'Sex', u.sex, 'sex'); field(info, 'Birthday', u.birthday, 'birthday');
    field(info, 'Email', u.email, 'email', 'email'); field(info, 'Contact number', u.contact, 'contact');
    if (!u.auSchool && u.program) { var note=document.createElement('div'); note.textContent='Program / Major: '+u.program; note.style.color='var(--muted)'; info.appendChild(note); }
    departmentField(as, u);
    if (u.auSchool) { var prog=document.createElement('div'); prog.textContent='Bachelor / Master: '+u.program; prog.style.color='var(--muted)'; as.appendChild(prog); }
    var rd=document.createElement('div'), rl=document.createElement('label'), rs=document.createElement('select'); rl.className='form-label'; rl.textContent='Role'; rs.className='form-input'; rs.name='role'; [['user','User'],['dept_admin','Department Admin']].forEach(function(o){var op=document.createElement('option');op.value=o[0];op.textContent=o[1];rs.appendChild(op);}); rs.value=u.roleRaw || 'user'; rd.appendChild(rl); rd.appendChild(rs); as.appendChild(rd);
    field(as, 'Username', u.username, 'username');
    var st=document.createElement('div'); st.innerHTML='<label class="form-label">Account status</label><input class="form-input" readonly value="'+String(u.status).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;')+'">'; as.appendChild(st);
    modal.classList.add('active');
    modal.querySelector('.modal').scrollTop = 0;
    modal.querySelector('.modal-close').focus();
  }
  function close() {
    modal.classList.remove('active');
    if (last) { try { last.focus(); } catch (e) {} }
  }
  document.querySelectorAll('.uv-btn[data-uv]').forEach(function (b) {
    b.addEventListener('click', function () { open(b.getAttribute('data-uv'), b); });
  });
  modal.querySelectorAll('[data-uv-close]').forEach(function (b) { b.addEventListener('click', close); });
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('active')) close(); });
})();
</script>
