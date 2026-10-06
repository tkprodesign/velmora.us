<?php
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../_layout.php';

$profile=v3Profile($user_email,$user_name);
$accounts=v3Accounts($user_email);
$accountNumber=preg_replace('/\D+/','',(string)($_GET['account']??($accounts[0]['account_number']??'')));
$month=(string)($_GET['month']??date('Y-m'));
if(!preg_match('/^\d{4}-\d{2}$/',$month))$month=date('Y-m');

$db=connectToDatabase();
$account=v3OwnedAccount($db,$user_email,$accountNumber);
if(!$account && !empty($accounts)){
    $accountNumber=(string)$accounts[0]['account_number'];
    $account=v3OwnedAccount($db,$user_email,$accountNumber);
}
$rows=[];
if($account){
    $start=strtotime($month.'-01 00:00:00');
    $end=strtotime('+1 month',$start);
    $stmt=$db->prepare("SELECT transaction_id,type,description,amount,currency,status,time,channel,value_date,posted_at
        FROM transactions WHERE user_email=? AND account_number=? AND (status IS NULL OR LOWER(status)<>'failed') AND time>=? AND time<? ORDER BY time ASC,id ASC");
    $stmt->bind_param('ssii',$user_email,$accountNumber,$start,$end);
    $stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();
}
$db->close();

if(isset($_GET['download'])&&$_GET['download']==='csv'&&$account){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="velmora-statement-'.$accountNumber.'-'.$month.'.csv"');
    $out=fopen('php://output','w');
    fputcsv($out,['Velmora Bank Statement']);
    fputcsv($out,['Account',$accountNumber]);
    fputcsv($out,['Account Type',$account['account_type']]);
    fputcsv($out,['Currency',$account['currency']]);
    fputcsv($out,['Period',$month]);
    fputcsv($out,[]);
    fputcsv($out,['Value Date','Reference','Type','Description','Status','Channel','Amount','Currency']);
    foreach($rows as $r){
        fputcsv($out,[
            $r['value_date']?:date('Y-m-d',(int)$r['time']),
            $r['transaction_id'],$r['type'],$r['description'],$r['status'],
            $r['channel']?:'Online Banking',number_format((float)$r['amount'],2,'.',''),$r['currency']
        ]);
    }
    fclose($out);exit;
}

$monthly=[];
$db=connectToDatabase();
$stmt=$db->prepare("SELECT DATE_FORMAT(FROM_UNIXTIME(time),'%Y-%m') AS period,COUNT(*) AS tx_count
    FROM transactions WHERE user_email=? AND (status IS NULL OR LOWER(status)<>'failed') GROUP BY period ORDER BY period DESC LIMIT 12");
$stmt->bind_param('s',$user_email);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc())$monthly[]=$r;$stmt->close();$db->close();

v3PageStart('Statements','statements',$profile,$user_profile_picture);
?>
<section class="v3-heading">
 <div><span class="v3-kicker">DOCUMENTS</span><h1>Statements</h1><p>Review monthly ledger activity by account and download a structured CSV statement for your records.</p></div>
</section>
<section class="v3-panel" style="margin-bottom:18px">
 <form method="get" class="v3-form-row">
  <label class="v3-form"><span>Account</span><select name="account" onchange="this.form.submit()"><?php foreach($accounts as $a): ?><option value="<?php echo htmlspecialchars($a['account_number']); ?>" <?php echo (string)$a['account_number']===$accountNumber?'selected':''; ?>><?php echo htmlspecialchars($a['currency'].' · '.$a['account_type'].' · •••• '.substr((string)$a['account_number'],-4)); ?></option><?php endforeach; ?></select></label>
  <label class="v3-form"><span>Statement month</span><input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>" onchange="this.form.submit()"></label>
 </form>
</section>

<?php if($account): ?>
<section class="v3-panel">
 <div class="v3-section-head"><div><span class="v3-kicker">MONTHLY STATEMENT</span><h2><?php echo htmlspecialchars(date('F Y',strtotime($month.'-01'))); ?></h2></div><a href="?account=<?php echo urlencode($accountNumber); ?>&month=<?php echo urlencode($month); ?>&download=csv">Download CSV</a></div>
 <div class="v3-meta-grid" style="margin-bottom:16px">
  <div><span>Account</span><strong>•••• <?php echo htmlspecialchars(substr($accountNumber,-4)); ?></strong></div>
  <div><span>Account type</span><strong><?php echo htmlspecialchars($account['account_type']); ?></strong></div>
  <div><span>Currency</span><strong><?php echo htmlspecialchars($account['currency']); ?></strong></div>
 </div>
 <div class="v3-table-wrap">
  <table class="v3-table"><thead><tr><th>Value date</th><th>Reference</th><th>Description</th><th>Status</th><th>Amount</th></tr></thead><tbody>
  <?php foreach($rows as $r): $credit=(float)$r['amount']>=0; ?>
   <tr><td><?php echo htmlspecialchars($r['value_date']?:date('Y-m-d',(int)$r['time'])); ?></td><td><a href="/dashboard/transactions/detail/?ref=<?php echo urlencode($r['transaction_id']); ?>"><?php echo htmlspecialchars($r['transaction_id']); ?></a></td><td><strong><?php echo htmlspecialchars($r['description']); ?></strong><small><?php echo htmlspecialchars(($r['channel']?:'Online Banking').' · '.$r['type']); ?></small></td><td><span class="v3-status <?php echo htmlspecialchars(strtolower($r['status'])); ?>"><?php echo htmlspecialchars($r['status']); ?></span></td><td class="v3-table-amount <?php echo $credit?'credit':''; ?>"><?php echo htmlspecialchars(velmoraFormatCurrency((float)$r['amount'],$r['currency'])); ?></td></tr>
  <?php endforeach; ?>
  <?php if(empty($rows)): ?><tr><td colspan="5"><div class="v3-empty">No account activity for this statement period.</div></td></tr><?php endif; ?>
  </tbody></table>
 </div>
</section>

<?php if(!empty($monthly)): ?>
<section class="v3-panel" style="margin-top:18px">
 <div class="v3-section-head"><div><span class="v3-kicker">RECENT PERIODS</span><h2>Statement months</h2></div></div>
 <div class="v3-statement-months"><?php foreach($monthly as $m): ?><article class="v3-statement-card"><span>Statement period</span><strong><?php echo htmlspecialchars(date('M Y',strtotime($m['period'].'-01'))); ?></strong><small><?php echo (int)$m['tx_count']; ?> transaction<?php echo (int)$m['tx_count']===1?'':'s'; ?></small><br><a href="?account=<?php echo urlencode($accountNumber); ?>&month=<?php echo urlencode($m['period']); ?>">Open period</a></article><?php endforeach; ?></div>
</section>
<?php endif; ?>
<?php else: ?><section class="v3-panel"><div class="v3-empty">Open an account before using statements.</div></section><?php endif; ?>
<?php v3PageEnd(); ?>