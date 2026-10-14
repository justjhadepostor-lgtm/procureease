<?php
require_once __DIR__.'/config/auth.php';require_once __DIR__.'/config/admin-schema.php';require_admin();ensure_admin_tables();$pdo=db();$u=current_user();$activeNav='late-submission-requests';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $id=(int)($_POST['request_id']??0); $decision=$_POST['decision']??''; $note=trim($_POST['review_note']??'');
    if($id&&in_array($decision,['approved','rejected'],true)){
        $rq=$pdo->prepare("SELECT * FROM late_submission_requests WHERE id=? AND status='pending'"); $rq->execute([$id]); $request=$rq->fetch();
        if(!$request){flash('error','Request already reviewed.');header('Location: late-submission-requests.php');exit;}
        if($decision==='approved'){
            // Approval now completes the original submit action: validate the same document set,
            // submit the PPMP, then send its companion documents using the existing workflow.
            require_once __DIR__.'/config/companions.php';
            require_once __DIR__.'/config/archive.php';
            $dept=(string)$request['dept_code']; $fy=(int)$request['fy'];
            $p=$pdo->prepare("SELECT id,status FROM ppmps WHERE dept_code=? AND fy=? ORDER BY id DESC LIMIT 1"); $p->execute([$dept,$fy]); $pp=$p->fetch();
            $block='';
            if(!$pp) $block='No PPMP was found for this department and fiscal year. The request remains pending.';
            elseif((string)$pp['status']!=='draft') $block='The PPMP is not in draft status, so it cannot be automatically submitted. The request remains pending.';
            elseif(arc_ppmp_is_archived((int)$pp['id'])) $block='The PPMP is archived. Restore it before approval can send the files.';
            else {
                $plan=ppmp_companions($dept,$fy);
                if(empty($plan['ok'])) {
                    $missing=[]; foreach(($plan['items']??[]) as $it) if(empty($it['ok'])) $missing[]=$it['label'];
                    $block='The files are not ready to send yet: '.implode(', ',$missing).'. Complete them first; the request remains pending.';
                } else {
                    $bm=ppmp_budget_match($dept,$fy,(int)$pp['id']);
                    if(empty($bm['ok'])) $block='The submission cannot be sent because of the budget check: '.($bm['note']??'Please review the budget.'). ' The request remains pending.';
                }
            }
            if($block!==''){flash('error',$block);header('Location: late-submission-requests.php');exit;}
            $pdo->beginTransaction();
            try {
                $q=$pdo->prepare("UPDATE late_submission_requests SET status='approved',review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status='pending'");
                $q->execute([$note,$u['full_name']??'Procurement Unit',$id]);
                if(!$q->rowCount()) throw new RuntimeException('Request already reviewed.');
                $who=(string)($request['full_name']??$u['full_name']??'Procurement Unit');
                $up=$pdo->prepare("UPDATE ppmps SET status='submitted',submitted_at=NOW(),submitted_by=? WHERE id=? AND status='draft'");
                $up->execute([$who,(int)$pp['id']]);
                if(!$up->rowCount()) throw new RuntimeException('The PPMP changed before it could be submitted. Please review the request again.');
                $pdo->commit();
            } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); flash('error',$e->getMessage());header('Location: late-submission-requests.php');exit; }
            try {
                require_once __DIR__.'/config/doc-versions.php'; doc_snapshot('ppmp',(string)$pp['id'],'submitted');
                require_once __DIR__.'/config/notify.php';
                $owner=$pdo->prepare('SELECT full_name FROM users WHERE id=?');$owner->execute([(int)$request['user_id']]);$request['full_name']=$owner->fetchColumn()?:($u['full_name']??'Procurement Unit');
                $request['dept_name']=$dept;
                ppmp_companions_send($plan,$request,$dept,$fy);
                notify_by_role('budget','submission',"Late PPMP submitted: $dept · FY $fy","Procurement Unit approved the late-submission request and the PPMP was automatically sent for review.",'budget-dashboard.php');
                notify_by_role('admin','submission',"Late submission approved and sent: $dept · FY $fy","The PPMP and its supporting documents were automatically sent after approval.",'admin-dashboard.php');
                log_activity('Approved and auto-submitted late request','department',"$dept · FY $fy",'Procurement Unit approval automatically sent the PPMP and ready supporting documents.');
            } catch(Throwable $e) { error_log('Late request auto-submit companion/notification issue: '.$e->getMessage()); }
            flash('success','Request approved. The PPMP and its supporting documents were automatically sent to the appropriate review queues.');
        } else {
            $q=$pdo->prepare("UPDATE late_submission_requests SET status='rejected',review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status='pending'");
            $q->execute([$note,$u['full_name']??'Procurement Unit',$id]); flash($q->rowCount()?'success':'error',$q->rowCount()?'Request rejected.':'Request already reviewed.');
        }
    }
    header('Location: late-submission-requests.php');exit;
}$rows=$pdo->query("SELECT r.*,u.full_name FROM late_submission_requests r LEFT JOIN users u ON u.id=r.user_id ORDER BY CASE r.status WHEN 'pending' THEN 0 ELSE 1 END,r.created_at DESC")->fetchAll();
?><?php require_once __DIR__ . '/includes/head.php'; ?><div class="app"><?php require __DIR__.'/includes/sidebar-admin.php'; ?><main class="main"><div class="page-head"><div><h1 class="page-title">Late Submission Requests</h1><p class="page-sub">Review late submission permission requests.</p></div></div><?php render_flashes(); ?><div class="card"><div class="tbl-scroll"><table class="data-table"><thead><tr><th>Department</th><th>FY</th><th>Requested by</th><th>Reason</th><th>Date</th><th>Status / Review</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="6">No requests yet.</td></tr><?php endif;foreach($rows as $r):?><tr><td><?=e($r['dept_code'])?></td><td><?= (int)$r['fy']?></td><td><?=e($r['full_name']??'User')?></td><td style="white-space:pre-wrap;min-width:200px"><?=e($r['reason'])?></td><td><?=e(date('M j, Y g:i A',strtotime($r['created_at'])))?></td><td><strong><?=e(ucfirst($r['status']))?></strong><?php if($r['status']==='pending'):?><form method="post" style="display:grid;gap:6px;margin-top:8px"><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int)$r['id']?>"><textarea class="form-input" name="review_note" rows="2" placeholder="Optional note"></textarea><div style="display:flex;gap:6px"><button class="btn btn-primary" name="decision" value="approved">Approve</button><button class="btn btn-secondary" name="decision" value="rejected">Reject</button></div></form><?php else:?><div class="page-sub"><?=e($r['reviewed_by']??'')?></div><?php if($r['review_note']):?><div><?=e($r['review_note'])?></div><?php endif;endif;?></td></tr><?php endforeach;?></tbody></table></div></div></main></div></body></html>
