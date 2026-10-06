<?php
if (!defined('VELMORA_CUSTOMER_DASHBOARD_ROUTE')) define('VELMORA_CUSTOMER_DASHBOARD_ROUTE','/dashboard/');
if (!defined('VELMORA_CONTROL_PANEL_LOGIN_ROUTE')) define('VELMORA_CONTROL_PANEL_LOGIN_ROUTE','/backend-login/');
require_once __DIR__ . '/../control-panel/app.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['cpv2_csrf'])) $_SESSION['cpv2_csrf']=bin2hex(random_bytes(24));

function cpv2CsrfInput(): string {
    return '<input type="hidden" name="cpv2_csrf" value="'.htmlspecialchars((string)$_SESSION['cpv2_csrf'],ENT_QUOTES,'UTF-8').'">';
}
function cpv2Verify(): void {
    $token=(string)($_POST['cpv2_csrf']??'');
    if($token===''||!hash_equals((string)($_SESSION['cpv2_csrf']??''),$token)){http_response_code(419);exit('Session validation failed.');}
}
function cpv2Flash(string $kind,string $message): void { $_SESSION['cpv2_flash'][$kind]=$message; }
function cpv2TakeFlash(string $kind): ?string { $v=$_SESSION['cpv2_flash'][$kind]??null;unset($_SESSION['cpv2_flash'][$kind]);return is_string($v)?$v:null; }
function cpv2Go(string $url): never { header('Location: '.$url);exit; }

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['cpv2_transfer_decision'])){
    cpv2Verify();$id=(int)($_POST['transaction_id']??0);$decision=(string)($_POST['decision']??'');
    if($id<=0||!in_array($decision,['Successful','Failed'],true)){cpv2Flash('error','Invalid transfer decision.');cpv2Go('/control-panel/transfers/');}
    $db=connectToDatabase();
    $stmt=$db->prepare("SELECT user_email,transaction_id,status FROM transactions WHERE id=? AND type='Transfer' LIMIT 1");$stmt->bind_param('i',$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$row){
        cpv2Flash('error','Transfer not found.');
    }elseif(strtolower((string)$row['status'])!=='pending'){
        cpv2Flash('error','This transfer has already been finalized.');
    }else{
        if($decision==='Successful'){
            $stmt=$db->prepare("UPDATE transactions SET status=?,posted_at=NOW(),value_date=COALESCE(value_date,CURDATE()) WHERE id=? AND LOWER(status)='pending'");
        }else{
            $stmt=$db->prepare("UPDATE transactions SET status=?,posted_at=NULL,value_date=NULL WHERE id=? AND LOWER(status)='pending'");
        }
        $stmt->bind_param('si',$decision,$id);$stmt->execute();$changed=$stmt->affected_rows===1;$stmt->close();
        if($changed){
            $operator=trim((string)($_SESSION['user_email']??'operator@velmora'));
            createUserNotification($db,$row['user_email'],'Transfer '.$decision,'Transfer '.$row['transaction_id'].' status is now '.$decision.'.','Transfer','/dashboard/transactions/detail/?ref='.urlencode($row['transaction_id']));
            recordSecurityEvent($db,$row['user_email'],'Transfer Decision','Transfer '.$row['transaction_id'].' marked '.$decision.' by '.$operator.'.');
            cpv2Flash('success','Transfer status updated.');
        }else{
            cpv2Flash('error','The transfer changed before this decision could be applied. Refresh and review it again.');
        }
    }
    $db->close();cpv2Go('/control-panel/transfers/');
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['cpv2_kyc_decision'])){
    cpv2Verify();$id=(int)($_POST['kyc_id']??0);$decision=(string)($_POST['decision']??'');$note=trim((string)($_POST['note']??''));
    if($id<=0||!in_array($decision,['Approved','Rejected','Pending'],true)){cpv2Flash('error','Invalid KYC decision.');cpv2Go('/control-panel/kyc/');}
    $db=connectToDatabase();$stmt=$db->prepare("SELECT email FROM kyc_data WHERE id=? LIMIT 1");$stmt->bind_param('i',$id);$stmt->execute();$stmt->bind_result($email);$found=$stmt->fetch();$stmt->close();
    if($found){
        $stmt=$db->prepare("UPDATE kyc_data SET status=?,description=? WHERE id=?");$stmt->bind_param('ssi',$decision,$note,$id);$stmt->execute();$stmt->close();
        createUserNotification($db,$email,'Identity review updated','Your identity review status is now '.$decision.'.','KYC','/dashboard/identity/');
        cpv2Flash('success','KYC status updated.');
    }else cpv2Flash('error','KYC record not found.');
    $db->close();cpv2Go('/control-panel/kyc/detail/?id='.$id);
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['cpv2_send_notification'])){
    cpv2Verify();$email=trim((string)($_POST['email']??''));$title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));$type=trim((string)($_POST['type']??'Operations'));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$title===''||$body===''){cpv2Flash('error','Complete all notification fields.');cpv2Go('/control-panel/communications/');}
    $db=connectToDatabase();createUserNotification($db,$email,$title,$body,$type,'/dashboard/notifications/');$db->close();cpv2Flash('success','In-app notification queued for the customer.');cpv2Go('/control-panel/communications/');
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['cpv2_support_phone'])){
    cpv2Verify();$phone=trim((string)($_POST['support_phone']??''));
    if($phone===''){cpv2Flash('error','Enter a support phone number.');cpv2Go('/control-panel/settings/');}
    $db=connectToDatabase();$stmt=$db->prepare("INSERT INTO dynamic_data (name,value) VALUES ('phone_number',?) ON DUPLICATE KEY UPDATE value=VALUES(value)");$stmt->bind_param('s',$phone);$stmt->execute();$stmt->close();$db->close();cpv2Flash('success','Support phone updated.');cpv2Go('/control-panel/settings/');
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['cpv2_restrict_customer'])){
    cpv2Verify();
    $id=(int)($_POST['customer_id']??0);
    $reason=trim((string)($_POST['restriction_reason']??''));
    if($id<=0||strlen($reason)<8||strlen($reason)>1200){cpv2Flash('error','Enter a clear restriction reason of at least 8 characters.');cpv2Go('/control-panel/customers/detail/?id='.$id);}
    $db=connectToDatabase();
    $stmt=$db->prepare("SELECT name,email,user_status FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param('i',$id);$stmt->execute();$user=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$user){$db->close();cpv2Flash('error','Customer was not found.');cpv2Go('/control-panel/customers/');}
    $operator=trim((string)($_SESSION['user_email']??'operator@velmora'));
    $status='Restricted';
    $stmt=$db->prepare("UPDATE users SET user_status=?,restriction_reason=?,restricted_by=?,restricted_at=NOW() WHERE id=?");
    $stmt->bind_param('sssi',$status,$reason,$operator,$id);$stmt->execute();$stmt->close();
    velmoraRevokeCustomerSessions($db,(string)$user['email']);
    recordSecurityEvent($db,(string)$user['email'],'Customer Access Restricted','Customer access restricted by '.$operator.'. Reason: '.$reason);
    $sender=getSecurityNoticeSender();
    $subject='Account Access Restricted - Velmora Bank';
    $intro='<p style="margin:0;">Dear '.htmlspecialchars((string)$user['name'],ENT_QUOTES,'UTF-8').', access to your Velmora customer profile has been restricted while the bank reviews an account matter.</p>';
    $details='<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #e2e8f2;border-radius:8px;background:#ffffff;">'
        .'<tr><td style="padding:12px 16px;border-bottom:1px solid #eef2f7;">Status</td><td style="padding:12px 16px;border-bottom:1px solid #eef2f7;text-align:right;font-weight:700;">Restricted Access</td></tr>'
        .'<tr><td style="padding:12px 16px;border-bottom:1px solid #eef2f7;">Reason</td><td style="padding:12px 16px;border-bottom:1px solid #eef2f7;text-align:right;font-weight:700;">'.htmlspecialchars($reason,ENT_QUOTES,'UTF-8').'</td></tr>'
        .'<tr><td style="padding:12px 16px;">What to do</td><td style="padding:12px 16px;text-align:right;font-weight:700;">Contact Velmora Bank Support if you need assistance or additional information.</td></tr>'
        .'</table>';
    $body=renderControlPanelBankEmail($subject,'Account Access Restricted',$intro,$details);
    if(!sendSiteEmail((string)$user['email'],$subject,$body,(string)$sender['email'],(string)$sender['name'])){
        error_log('Failed to send customer restriction notice to '.(string)$user['email']);
    }
    $db->close();
    cpv2Flash('success','Customer access restricted. Active customer access will terminate on the next authenticated request.');
    cpv2Go('/control-panel/customers/detail/?id='.$id);
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['cpv2_restore_customer'])){
    cpv2Verify();
    $id=(int)($_POST['customer_id']??0);
    if($id<=0){cpv2Flash('error','Invalid customer.');cpv2Go('/control-panel/customers/');}
    $db=connectToDatabase();
    $stmt=$db->prepare("SELECT name,email,user_status FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param('i',$id);$stmt->execute();$user=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$user){$db->close();cpv2Flash('error','Customer was not found.');cpv2Go('/control-panel/customers/');}
    $operator=trim((string)($_SESSION['user_email']??'operator@velmora'));
    $status='Active';
    $stmt=$db->prepare("UPDATE users SET user_status=?,restriction_reason=NULL,restricted_by=NULL,restricted_at=NULL WHERE id=?");
    $stmt->bind_param('si',$status,$id);$stmt->execute();$stmt->close();
    recordSecurityEvent($db,(string)$user['email'],'Customer Access Restored','Customer access restored by '.$operator.'.');
    $sender=getSecurityNoticeSender();
    $subject='Account Access Restored - Velmora Bank';
    $intro='<p style="margin:0;">Dear '.htmlspecialchars((string)$user['name'],ENT_QUOTES,'UTF-8').', access to your Velmora customer profile has been restored.</p>';
    $details='<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #e2e8f2;border-radius:8px;background:#ffffff;"><tr><td style="padding:12px 16px;">Status</td><td style="padding:12px 16px;text-align:right;font-weight:700;">Active</td></tr></table>';
    $body=renderControlPanelBankEmail($subject,'Account Access Restored',$intro,$details);
    sendSiteEmail((string)$user['email'],$subject,$body,(string)$sender['email'],(string)$sender['name']);
    $db->close();cpv2Flash('success','Customer access restored.');cpv2Go('/control-panel/customers/detail/?id='.$id);
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['cpv2_customer_status'])){
    cpv2Verify();$id=(int)($_POST['customer_id']??0);$status=(string)($_POST['status']??'');
    if($id<=0||!in_array($status,['Active','Suspended'],true)){cpv2Flash('error','Invalid customer status.');cpv2Go('/control-panel/customers/');}
    $db=connectToDatabase();$stmt=$db->prepare("UPDATE users SET user_status=? WHERE id=?");$stmt->bind_param('si',$status,$id);$stmt->execute();$stmt->close();
    if($status!=='Active'){$stmt=$db->prepare("SELECT email FROM users WHERE id=? LIMIT 1");$stmt->bind_param('i',$id);$stmt->execute();$stmt->bind_result($statusEmail);if($stmt->fetch()&&$statusEmail){$stmt->close();velmoraRevokeCustomerSessions($db,(string)$statusEmail);}else{$stmt->close();}}
    $db->close();
    cpv2Flash('success','Customer relationship status updated.');cpv2Go('/control-panel/customers/detail/?id='.$id);
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['cpv2_account_status'])){
    cpv2Verify();$account=preg_replace('/\D+/','',(string)($_POST['account_number']??''));$status=(string)($_POST['status']??'');
    if($account===''||!in_array($status,['Active','Restricted','Closed'],true)){cpv2Flash('error','Invalid account status.');cpv2Go('/control-panel/accounts/');}
    $db=connectToDatabase();$stmt=$db->prepare("UPDATE accounts SET account_status=? WHERE account_number=?");$stmt->bind_param('ss',$status,$account);$stmt->execute();$stmt->close();$db->close();
    cpv2Flash('success','Account status updated.');cpv2Go('/control-panel/accounts/?account='.urlencode($account));
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['cpv2_adjust_ledger'])){
    cpv2Verify();
    $email=trim((string)($_POST['email']??''));$account=preg_replace('/\D+/','',(string)($_POST['account_number']??''));$direction=(string)($_POST['direction']??'');$amount=filter_var($_POST['amount']??null,FILTER_VALIDATE_FLOAT);$description=trim((string)($_POST['description']??''));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$account===''||!in_array($direction,['Credit','Debit'],true)||$amount===false||$amount<=0){cpv2Flash('error','Complete the ledger adjustment correctly.');cpv2Go('/control-panel/adjustments/');}
    $db=connectToDatabase();
    $db->begin_transaction();
    $stmt=$db->prepare("SELECT currency,account_status FROM accounts WHERE user_email=? AND account_number=? LIMIT 1 FOR UPDATE");$stmt->bind_param('ss',$email,$account);$stmt->execute();$acct=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$acct||$acct['account_status']==='Closed'){$db->rollback();$db->close();cpv2Flash('error','Account was not found or is closed.');cpv2Go('/control-panel/adjustments/');}
    $currency=strtoupper((string)$acct['currency']);$signed=$direction==='Credit'?abs((float)$amount):-abs((float)$amount);
    if($direction==='Debit'){
        $stmt=$db->prepare("SELECT COALESCE(SUM(CASE WHEN LOWER(status)<>'failed' THEN amount ELSE 0 END),0) FROM transactions WHERE account_number=?");$stmt->bind_param('s',$account);$stmt->execute();$stmt->bind_result($balance);$stmt->fetch();$stmt->close();
        if(abs($signed)>(float)$balance){$db->rollback();$db->close();cpv2Flash('error','Debit exceeds the available ledger balance.');cpv2Go('/control-panel/adjustments/');}
    }
    $txid='OPS-'.strtoupper(bin2hex(random_bytes(7)));$type=$direction==='Credit'?'Operations Credit':'Operations Debit';$status='Successful';$now=time();$channel='Operations Console';$valueDate=date('Y-m-d',$now);$postedAt=date('Y-m-d H:i:s',$now);if($description==='')$description=$type.' adjustment';
    $stmt=$db->prepare("INSERT INTO transactions (type,transaction_id,user_email,account_number,amount,currency,description,status,time,channel,value_date,posted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('ssssdsssisss',$type,$txid,$email,$account,$signed,$currency,$description,$status,$now,$channel,$valueDate,$postedAt);$stmt->execute();$stmt->close();
    createUserNotification($db,$email,'Account adjustment posted',$description.' · '.velmoraFormatCurrency($signed,$currency).' · Reference '.$txid.'.','Account','/dashboard/transactions/detail/?ref='.urlencode($txid));
    recordSecurityEvent($db,$email,'Operations Adjustment','Operations console posted '.$txid);
    $db->commit();
    $db->close();cpv2Flash('success','Ledger adjustment posted: '.$txid);cpv2Go('/control-panel/adjustments/');
}


if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['cpv2_support_reply'])){
    cpv2Verify();
    $caseId=(int)($_POST['case_id']??0);
    $message=trim((string)($_POST['message']??''));
    $operator=trim((string)($_SESSION['user_email']??'operator@velmora'));
    if($caseId<=0||$message===''){cpv2Flash('error','Enter a reply before sending.');cpv2Go('/control-panel/support-cases/');}
    $db=connectToDatabase();
    $stmt=$db->prepare("SELECT user_email,case_number,status FROM support_cases WHERE id=? LIMIT 1");
    $stmt->bind_param('i',$caseId);$stmt->execute();$case=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$case){$db->close();cpv2Flash('error','Support case not found.');cpv2Go('/control-panel/support-cases/');}
    $role='Operator';
    $stmt=$db->prepare("INSERT INTO support_case_messages (case_id,sender_role,sender_email,message) VALUES (?,?,?,?)");
    $stmt->bind_param('isss',$caseId,$role,$operator,$message);$stmt->execute();$stmt->close();
    $nextStatus=in_array($case['status'],['Resolved','Closed'],true)?'In Review':($case['status']==='Open'?'In Review':$case['status']);
    $stmt=$db->prepare("UPDATE support_cases SET status=?,assigned_to=?,last_operator_message_at=NOW(),resolved_at=NULL WHERE id=?");
    $stmt->bind_param('ssi',$nextStatus,$operator,$caseId);$stmt->execute();$stmt->close();
    createUserNotification($db,$case['user_email'],'Support replied','Velmora Support replied to case '.$case['case_number'].'.','Support','/dashboard/support/detail/?id='.$caseId);
    $db->close();cpv2Flash('success','Reply sent to the customer.');cpv2Go('/control-panel/support-cases/detail/?id='.$caseId);
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['cpv2_support_status'])){
    cpv2Verify();
    $caseId=(int)($_POST['case_id']??0);$status=(string)($_POST['status']??'');
    $operator=trim((string)($_SESSION['user_email']??'operator@velmora'));
    if($caseId<=0||!in_array($status,['Open','In Review','Resolved','Closed'],true)){cpv2Flash('error','Invalid support-case status.');cpv2Go('/control-panel/support-cases/');}
    $db=connectToDatabase();
    $stmt=$db->prepare("SELECT user_email,case_number FROM support_cases WHERE id=? LIMIT 1");$stmt->bind_param('i',$caseId);$stmt->execute();$case=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$case){$db->close();cpv2Flash('error','Support case not found.');cpv2Go('/control-panel/support-cases/');}
    $resolved=in_array($status,['Resolved','Closed'],true)?date('Y-m-d H:i:s'):null;
    $stmt=$db->prepare("UPDATE support_cases SET status=?,assigned_to=?,resolved_at=? WHERE id=?");
    $stmt->bind_param('sssi',$status,$operator,$resolved,$caseId);$stmt->execute();$stmt->close();
    createUserNotification($db,$case['user_email'],'Support case updated','Case '.$case['case_number'].' status is now '.$status.'.','Support','/dashboard/support/detail/?id='.$caseId);
    $db->close();cpv2Flash('success','Support case status updated.');cpv2Go('/control-panel/support-cases/detail/?id='.$caseId);
}
