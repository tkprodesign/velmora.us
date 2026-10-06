<?php
if (!defined('VELMORA_LOGIN_ROUTE')) define('VELMORA_LOGIN_ROUTE','/login/');
if (!defined('VELMORA_CONTROL_PANEL_ROUTE')) define('VELMORA_CONTROL_PANEL_ROUTE','/control-panel/');
require_once __DIR__ . '/../dashboard/app.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['v3_csrf'])) {
    $_SESSION['v3_csrf'] = bin2hex(random_bytes(24));
}

function v3CsrfInput(): string {
    return '<input type="hidden" name="v3_csrf" value="' . htmlspecialchars((string)$_SESSION['v3_csrf'], ENT_QUOTES, 'UTF-8') . '">';
}

function v3VerifyPost(): void {
    $token = (string)($_POST['v3_csrf'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['v3_csrf'] ?? ''), $token)) {
        http_response_code(419);
        exit('Session validation failed. Please refresh and try again.');
    }
}

function v3Redirect(string $path): never {
    header('Location: ' . $path);
    exit;
}

function v3TransactionId(string $prefix): string {
    return strtoupper($prefix) . '-' . strtoupper(bin2hex(random_bytes(8)));
}

function v3Accounts(string $email): array {
    $db = connectToDatabase();
    $stmt = $db->prepare("SELECT a.id, a.account_number, a.account_type, a.currency, a.account_status, a.creation_time,
        a.account_alias, a.opened_at,
        COALESCE(SUM(CASE WHEN t.status IS NULL OR LOWER(t.status) <> 'failed' THEN t.amount ELSE 0 END), 0) AS balance
        FROM accounts a
        LEFT JOIN transactions t ON t.account_number = a.account_number
        WHERE a.user_email = ?
        GROUP BY a.id, a.account_number, a.account_type, a.currency, a.account_status, a.creation_time, a.account_alias, a.opened_at
        ORDER BY a.id DESC");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['currency'] = strtoupper((string)$row['currency']);
        $row['balance'] = (float)$row['balance'];
        $rows[] = $row;
    }
    $stmt->close();
    $db->close();
    return $rows;
}

function v3Profile(string $email, string $fallbackName): array {
    $profile = [
        'name' => $fallbackName,
        'dob' => 'Not available',
        'occupation' => 'Not available',
        'status' => 'Not submitted',
        'address' => 'Not available',
        'city' => 'Not available',
        'country' => 'Not available',
        'phone' => 'Not available',
        'nationality' => 'Not available',
        'source_of_income' => 'Not available',
    ];

    $db = connectToDatabase();
    $stmt = $db->prepare("SELECT first_name, middle_name, last_name, date_of_birth, occupation, status,
        address1, city, country_of_residence, phone_number, nationality, source_of_income
        FROM kyc_data WHERE email = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->bind_result($first, $middle, $last, $dob, $occupation, $status, $address, $city, $country, $phone, $nationality, $source);
    if ($stmt->fetch()) {
        $full = trim(implode(' ', array_filter([$first, $middle, $last])));
        if ($full !== '') $profile['name'] = $full;
        foreach ([
            'dob' => $dob, 'occupation' => $occupation, 'status' => $status, 'address' => $address,
            'city' => $city, 'country' => $country, 'phone' => $phone,
            'nationality' => $nationality, 'source_of_income' => $source
        ] as $key => $value) {
            if ($value !== null && trim((string)$value) !== '') $profile[$key] = (string)$value;
        }
    }
    $stmt->close();
    $db->close();
    return $profile;
}

function v3ClientMeta(string $email): array {
    $meta=['customer_number'=>'Not assigned','user_status'=>'Active','member_since'=>'Not available','last_login_at'=>null,'last_login_ip'=>null,'login_count'=>0];
    $db=connectToDatabase();
    $stmt=$db->prepare("SELECT customer_number,user_status,date_registered,last_login_at,last_login_ip,login_count FROM users WHERE email=? LIMIT 1");
    if($stmt){$stmt->bind_param('s',$email);$stmt->execute();$stmt->bind_result($cn,$status,$registered,$lastLogin,$ip,$count);
        if($stmt->fetch()){if($cn)$meta['customer_number']=$cn;if($status)$meta['user_status']=$status;if((int)$registered>0)$meta['member_since']=date('M d, Y',(int)$registered);$meta['last_login_at']=$lastLogin;$meta['last_login_ip']=$ip;$meta['login_count']=(int)$count;}
        $stmt->close();}
    $db->close();return $meta;
}
function v3Beneficiaries(string $email, bool $activeOnly=true): array {
    $db=connectToDatabase();$sql="SELECT id,nickname,beneficiary_name,bank_name,account_number,account_type,currency,status,last_used_at,created_at FROM beneficiaries WHERE user_email=?";
    if($activeOnly)$sql.=" AND status='Active'";$sql.=" ORDER BY COALESCE(last_used_at,created_at) DESC,id DESC";
    $stmt=$db->prepare($sql);$rows=[];if($stmt){$stmt->bind_param('s',$email);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();}$db->close();return $rows;
}
function v3Notifications(string $email,int $limit=50): array {
    $db=connectToDatabase();$limit=max(1,min(100,$limit));$stmt=$db->prepare("SELECT id,title,body,notification_type,action_url,is_read,read_at,created_at FROM notifications WHERE user_email=? ORDER BY id DESC LIMIT ".$limit);
    $rows=[];if($stmt){$stmt->bind_param('s',$email);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();}$db->close();return $rows;
}
function v3UnreadNotificationCount(string $email): int {
    $db=connectToDatabase();$count=0;$stmt=$db->prepare("SELECT COUNT(*) FROM notifications WHERE user_email=? AND is_read=0");
    if($stmt){$stmt->bind_param('s',$email);$stmt->execute();$stmt->bind_result($count);$stmt->fetch();$stmt->close();}$db->close();return (int)$count;
}
function v3Preferences(string $email): array {
    $p=['timezone'=>'America/New_York','language'=>'en','email_transaction_alerts'=>1,'email_security_alerts'=>1,'in_app_notifications'=>1,'statement_delivery'=>'Digital'];
    $db=connectToDatabase();$stmt=$db->prepare("SELECT timezone,language,email_transaction_alerts,email_security_alerts,in_app_notifications,statement_delivery FROM user_preferences WHERE user_email=? LIMIT 1");
    if($stmt){$stmt->bind_param('s',$email);$stmt->execute();$res=$stmt->get_result();if($r=$res->fetch_assoc())$p=array_merge($p,$r);$stmt->close();}$db->close();return $p;
}

function v3SupportCases(string $email): array {
    $db=connectToDatabase();
    $stmt=$db->prepare("SELECT id,case_number,category,subject,status,priority,related_transaction_id,assigned_to,last_customer_message_at,last_operator_message_at,resolved_at,created_at,updated_at FROM support_cases WHERE user_email=? ORDER BY updated_at DESC,id DESC");
    $rows=[];
    if($stmt){$stmt->bind_param('s',$email);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();}
    $db->close();return $rows;
}
function v3SupportCase(string $email,int $id): ?array {
    $db=connectToDatabase();
    $stmt=$db->prepare("SELECT id,case_number,user_email,category,subject,status,priority,related_transaction_id,assigned_to,resolved_at,created_at,updated_at FROM support_cases WHERE id=? AND user_email=? LIMIT 1");
    $stmt->bind_param('is',$id,$email);$stmt->execute();$case=$stmt->get_result()->fetch_assoc()?:null;$stmt->close();
    if($case){
        $stmt=$db->prepare("SELECT id,sender_role,sender_email,message,created_at FROM support_case_messages WHERE case_id=? ORDER BY id ASC");
        $stmt->bind_param('i',$id);$stmt->execute();$case['messages']=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    }
    $db->close();return $case;
}

function v3OwnedAccount(mysqli $db, string $email, string $accountNumber): ?array {
    $stmt = $db->prepare("SELECT account_number, account_type, currency, account_status
        FROM accounts WHERE user_email = ? AND account_number = ? LIMIT 1");
    $stmt->bind_param('ss', $email, $accountNumber);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc() ?: null;
    $stmt->close();
    if ($row) $row['currency'] = strtoupper((string)$row['currency']);
    return $row;
}

function v3OwnedAccountForUpdate(mysqli $db, string $email, string $accountNumber): ?array {
    $stmt = $db->prepare("SELECT account_number, account_type, currency, account_status
        FROM accounts WHERE user_email = ? AND account_number = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('ss', $email, $accountNumber);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc() ?: null;
    $stmt->close();
    if ($row) $row['currency'] = strtoupper((string)$row['currency']);
    return $row;
}

function v3AccountBalance(mysqli $db, string $accountNumber): float {
    $stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN status IS NULL OR LOWER(status) <> 'failed' THEN amount ELSE 0 END), 0)
        FROM transactions WHERE account_number = ?");
    $stmt->bind_param('s', $accountNumber);
    $stmt->execute();
    $stmt->bind_result($balance);
    $stmt->fetch();
    $stmt->close();
    return (float)$balance;
}

function v3PostMessage(string $key, string $value): void {
    $_SESSION['v3_flash'][$key] = $value;
}

function v3Flash(string $key): ?string {
    $value = $_SESSION['v3_flash'][$key] ?? null;
    unset($_SESSION['v3_flash'][$key]);
    return is_string($value) ? $value : null;
}


if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['v3_create_support_case'])) {
    v3VerifyPost();
    $category=trim((string)($_POST['category']??'General'));
    $subject=trim((string)($_POST['subject']??''));
    $message=trim((string)($_POST['message']??''));
    $priority=trim((string)($_POST['priority']??'Normal'));
    $related=trim((string)($_POST['related_transaction_id']??''));
    $allowedCategories=['Accounts','Transfers','FX','Cards','Loans','Profile & KYC','Security','General'];
    if(!in_array($category,$allowedCategories,true))$category='General';
    if(!in_array($priority,['Normal','Urgent'],true))$priority='Normal';
    if($subject===''||$message===''){
        v3PostMessage('error','Add a subject and message before submitting the support case.');
        v3Redirect('/dashboard/support/');
    }
    $caseNumber='VLM-SUP-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
    $db=connectToDatabase();
    $stmt=$db->prepare("INSERT INTO support_cases (case_number,user_email,category,subject,status,priority,related_transaction_id,last_customer_message_at) VALUES (?,?,?,?,'Open',?,?,NOW())");
    $stmt->bind_param('ssssss',$caseNumber,$user_email,$category,$subject,$priority,$related);$stmt->execute();$caseId=(int)$db->insert_id;$stmt->close();
    $role='Customer';
    $stmt=$db->prepare("INSERT INTO support_case_messages (case_id,sender_role,sender_email,message) VALUES (?,?,?,?)");
    $stmt->bind_param('isss',$caseId,$role,$user_email,$message);$stmt->execute();$stmt->close();
    createUserNotification($db,$user_email,'Support case created','Your support case '.$caseNumber.' has been opened.','Support','/dashboard/support/detail/?id='.$caseId);
    $db->close();
    v3PostMessage('success','Support case '.$caseNumber.' was created.');
    v3Redirect('/dashboard/support/detail/?id='.$caseId);
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['v3_reply_support_case'])) {
    v3VerifyPost();
    $caseId=(int)($_POST['case_id']??0);$message=trim((string)($_POST['message']??''));
    if($caseId<=0||$message===''){v3PostMessage('error','Enter a message before sending.');v3Redirect('/dashboard/support/');}
    $db=connectToDatabase();
    $stmt=$db->prepare("SELECT status FROM support_cases WHERE id=? AND user_email=? LIMIT 1");$stmt->bind_param('is',$caseId,$user_email);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$row){$db->close();v3PostMessage('error','Support case not found.');v3Redirect('/dashboard/support/');}
    if(in_array($row['status'],['Resolved','Closed'],true)){
        $stmt=$db->prepare("UPDATE support_cases SET status='Open',resolved_at=NULL,last_customer_message_at=NOW() WHERE id=? AND user_email=?");$stmt->bind_param('is',$caseId,$user_email);$stmt->execute();$stmt->close();
    }else{
        $stmt=$db->prepare("UPDATE support_cases SET last_customer_message_at=NOW() WHERE id=? AND user_email=?");$stmt->bind_param('is',$caseId,$user_email);$stmt->execute();$stmt->close();
    }
    $role='Customer';$stmt=$db->prepare("INSERT INTO support_case_messages (case_id,sender_role,sender_email,message) VALUES (?,?,?,?)");$stmt->bind_param('isss',$caseId,$role,$user_email,$message);$stmt->execute();$stmt->close();$db->close();
    v3PostMessage('success','Your message was added to the support case.');v3Redirect('/dashboard/support/detail/?id='.$caseId);
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['v3_add_beneficiary'])) {
    v3VerifyPost();
    $name=trim((string)($_POST['beneficiary_name']??''));$nickname=trim((string)($_POST['nickname']??''));$bank=trim((string)($_POST['bank_name']??''));$account=trim((string)($_POST['account_number']??''));$type=trim((string)($_POST['account_type']??''));$currency=strtoupper(trim((string)($_POST['currency']??'')));
    if($name===''||$bank===''||$account===''||!velmoraIsSupportedCurrency($currency)){v3PostMessage('error','Complete the beneficiary details correctly.');v3Redirect('/dashboard/beneficiaries/');}
    $db=connectToDatabase();$status='Active';
    $stmt=$db->prepare("INSERT INTO beneficiaries (user_email,nickname,beneficiary_name,bank_name,account_number,account_type,currency,status) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nickname=VALUES(nickname),beneficiary_name=VALUES(beneficiary_name),account_type=VALUES(account_type),currency=VALUES(currency),status='Active'");
    $stmt->bind_param('ssssssss',$user_email,$nickname,$name,$bank,$account,$type,$currency,$status);$stmt->execute();$stmt->close();$db->close();
    v3PostMessage('success','Beneficiary saved.');v3Redirect('/dashboard/beneficiaries/');
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['v3_remove_beneficiary'])) {
    v3VerifyPost();$id=(int)($_POST['beneficiary_id']??0);if($id>0){$db=connectToDatabase();$stmt=$db->prepare("UPDATE beneficiaries SET status='Inactive' WHERE id=? AND user_email=?");$stmt->bind_param('is',$id,$user_email);$stmt->execute();$stmt->close();$db->close();v3PostMessage('success','Beneficiary removed.');}v3Redirect('/dashboard/beneficiaries/');
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['v3_mark_notifications_read'])) {
    v3VerifyPost();$db=connectToDatabase();$stmt=$db->prepare("UPDATE notifications SET is_read=1,read_at=NOW() WHERE user_email=? AND is_read=0");$stmt->bind_param('s',$user_email);$stmt->execute();$stmt->close();$db->close();v3Redirect('/dashboard/notifications/');
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['v3_save_preferences'])) {
    v3VerifyPost();$timezone=trim((string)($_POST['timezone']??'America/New_York'));if(!in_array($timezone,DateTimeZone::listIdentifiers(),true))$timezone='America/New_York';
    $txAlerts=isset($_POST['email_transaction_alerts'])?1:0;$secAlerts=isset($_POST['email_security_alerts'])?1:0;$inApp=isset($_POST['in_app_notifications'])?1:0;$delivery=in_array((string)($_POST['statement_delivery']??''),['Digital','Email'],true)?(string)$_POST['statement_delivery']:'Digital';$language='en';
    $db=connectToDatabase();$stmt=$db->prepare("INSERT INTO user_preferences (user_email,timezone,language,email_transaction_alerts,email_security_alerts,in_app_notifications,statement_delivery) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE timezone=VALUES(timezone),language=VALUES(language),email_transaction_alerts=VALUES(email_transaction_alerts),email_security_alerts=VALUES(email_security_alerts),in_app_notifications=VALUES(in_app_notifications),statement_delivery=VALUES(statement_delivery)");
    $stmt->bind_param('sssiiis',$user_email,$timezone,$language,$txAlerts,$secAlerts,$inApp,$delivery);$stmt->execute();$stmt->close();recordSecurityEvent($db,$user_email,'Preferences Updated','Online banking preferences updated');$db->close();
    v3PostMessage('success','Preferences updated.');v3Redirect('/dashboard/preferences/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['v3_create_account'])) {
    v3VerifyPost();
    $currency = strtoupper(trim((string)($_POST['currency'] ?? '')));
    $accountType = trim((string)($_POST['account_type'] ?? ''));
    $accountAlias = trim((string)($_POST['account_alias'] ?? ''));
    if(strlen($accountAlias)>100)$accountAlias=substr($accountAlias,0,100);

    if (!velmoraIsSupportedCurrency($currency) || !in_array($accountType, ['Savings','Current','Fixed','Personal Checking'], true)) {
        v3PostMessage('error', 'Choose a valid account type and currency.');
        v3Redirect('/dashboard/accounts/');
    }

    $db = connectToDatabase();
    do {
        $accountNumber = (string)random_int(2000000000, 2999999999);
        $stmt = $db->prepare('SELECT COUNT(*) FROM accounts WHERE account_number = ?');
        $stmt->bind_param('s', $accountNumber);
        $stmt->execute();
        $stmt->bind_result($taken);
        $stmt->fetch();
        $stmt->close();
    } while ((int)$taken > 0);

    $status='Active';$now=time();$openedAt=date('Y-m-d H:i:s',$now);
    $stmt=$db->prepare('INSERT INTO accounts (account_type,user_name,user_email,currency,account_number,account_status,creation_time,account_alias,opened_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('ssssssiss',$accountType,$user_name,$user_email,$currency,$accountNumber,$status,$now,$accountAlias,$openedAt);$stmt->execute();$stmt->close();
    createUserNotification($db,$user_email,'New account opened',$currency.' '.$accountType.' account ending '.substr($accountNumber,-4).' is now active.','Account','/dashboard/accounts/');
    $db->close();
    v3Redirect('/dashboard/accounts/opened/?account=' . urlencode($accountNumber));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['v3_quote_exchange'])) {
    v3VerifyPost();
    $from = preg_replace('/\D+/', '', (string)($_POST['from_account'] ?? ''));
    $to = preg_replace('/\D+/', '', (string)($_POST['to_account'] ?? ''));
    $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);

    $db = connectToDatabase();
    $source = v3OwnedAccount($db, $user_email, $from);
    $target = v3OwnedAccount($db, $user_email, $to);

    if (!$source || !$target || $source['account_status'] !== 'Active' || $target['account_status'] !== 'Active'
        || $from === $to || $source['currency'] === $target['currency'] || $amount === false || $amount <= 0) {
        $db->close();
        v3PostMessage('error', 'Choose two active accounts in different currencies and enter a valid amount.');
        v3Redirect('/dashboard/exchange/');
    }

    $balance = v3AccountBalance($db, $from);
    $db->close();
    if ((float)$amount > $balance) {
        v3PostMessage('error', 'The source account does not have enough available funds for this trade.');
        v3Redirect('/dashboard/exchange/');
    }

    try {
        $fx = velmoraFxQuote((float)$amount, $source['currency'], $target['currency']);
    } catch (Throwable $e) {
        v3PostMessage('error', 'A bank exchange quote could not be prepared for that currency pair.');
        v3Redirect('/dashboard/exchange/');
    }

    $quoteId = 'Q-' . strtoupper(bin2hex(random_bytes(6)));
    $_SESSION['v3_fx_quote'] = [
        'quote_id' => $quoteId,
        'from_account' => $from,
        'to_account' => $to,
        'source_currency' => $source['currency'],
        'target_currency' => $target['currency'],
        'source_amount' => (float)$amount,
        'target_amount' => (float)$fx['amount_out'],
        'customer_rate' => (float)$fx['customer_rate'],
        'spread_bps' => (int)$fx['spread_bps'],
        'quoted_at' => time(),
        'expires_at' => time() + 60,
    ];
    v3Redirect('/dashboard/exchange/?review=1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['v3_execute_exchange'])) {
    v3VerifyPost();
    $quote = $_SESSION['v3_fx_quote'] ?? null;
    $quoteId = (string)($_POST['quote_id'] ?? '');

    if (!is_array($quote) || $quoteId === '' || !hash_equals((string)$quote['quote_id'], $quoteId) || time() > (int)$quote['expires_at']) {
        unset($_SESSION['v3_fx_quote']);
        v3PostMessage('error', 'That exchange quote has expired. Request a fresh quote.');
        v3Redirect('/dashboard/exchange/');
    }

    $db = connectToDatabase();
    $db->begin_transaction();
    try {
        $lockNumbers = [(string)$quote['from_account'], (string)$quote['to_account']];
        sort($lockNumbers, SORT_STRING);
        $lockedAccounts = [];
        foreach ($lockNumbers as $lockNumber) {
            $lockedAccounts[$lockNumber] = v3OwnedAccountForUpdate($db, $user_email, $lockNumber);
        }
        $source = $lockedAccounts[(string)$quote['from_account']] ?? null;
        $target = $lockedAccounts[(string)$quote['to_account']] ?? null;
        if (
            !$source || !$target ||
            $source['account_status'] !== 'Active' ||
            $target['account_status'] !== 'Active' ||
            $source['currency'] !== $quote['source_currency'] ||
            $target['currency'] !== $quote['target_currency']
        ) {
            throw new RuntimeException('Account details or status changed after the quote.');
        }

        $balance = v3AccountBalance($db, (string)$quote['from_account']);
        if ((float)$quote['source_amount'] > $balance) {
            throw new RuntimeException('Insufficient funds at execution.');
        }

        $tradeId = 'FX-' . strtoupper(bin2hex(random_bytes(8)));
        $debitId = $tradeId . '-D';
        $creditId = $tradeId . '-C';
        $status = 'Successful';
        $type = 'FX Exchange';
        $now = time();

        $sourceAmount = -abs((float)$quote['source_amount']);
        $targetAmount = abs((float)$quote['target_amount']);
        $sourceCurrency = (string)$quote['source_currency'];
        $targetCurrency = (string)$quote['target_currency'];
        $rate = (float)$quote['customer_rate'];
        $spread = (int)$quote['spread_bps'];
        $sourceDesc = 'Currency exchange to ' . $targetCurrency . ' • ' . $tradeId;
        $targetDesc = 'Currency exchange from ' . $sourceCurrency . ' • ' . $tradeId;
        $sourceAccount = (string)$quote['from_account'];
        $targetAccount = (string)$quote['to_account'];

        $stmt = $db->prepare("INSERT INTO transactions
            (transaction_id,type,user_email,account_number,amount,currency,description,status,time,counter_currency,counter_amount,fx_rate,fx_spread_bps)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sssidsssisddi', $debitId, $type, $user_email, $sourceAccount, $sourceAmount, $sourceCurrency, $sourceDesc, $status, $now, $targetCurrency, $targetAmount, $rate, $spread);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("INSERT INTO transactions
            (transaction_id,type,user_email,account_number,amount,currency,description,status,time,counter_currency,counter_amount,fx_rate,fx_spread_bps)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $counterSource = abs((float)$quote['source_amount']);
        $stmt->bind_param('sssidsssisddi', $creditId, $type, $user_email, $targetAccount, $targetAmount, $targetCurrency, $targetDesc, $status, $now, $sourceCurrency, $counterSource, $rate, $spread);
        $stmt->execute();
        $stmt->close();

        $valueDate = date('Y-m-d', $now);
        $postedAt = date('Y-m-d H:i:s', $now);
        foreach ([$debitId, $creditId] as $entryId) {
            $metaStmt = $db->prepare("UPDATE transactions SET channel='Online Banking', value_date=?, posted_at=? WHERE transaction_id=?");
            if ($metaStmt) {
                $metaStmt->bind_param('sss', $valueDate, $postedAt, $entryId);
                $metaStmt->execute();
                $metaStmt->close();
            }
        }

        $tradeStatus = 'Executed';
        $quotedAt = (int)$quote['quoted_at'];
        $sourcePositive = abs((float)$quote['source_amount']);

        $stmt = $db->prepare("INSERT INTO fx_trades
            (trade_id,user_email,from_account_number,to_account_number,source_currency,target_currency,source_amount,target_amount,customer_rate,fx_spread_bps,status,quoted_at,executed_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('ssssssdddisii', $tradeId, $user_email, $sourceAccount, $targetAccount, $sourceCurrency, $targetCurrency, $sourcePositive, $targetAmount, $rate, $spread, $tradeStatus, $quotedAt, $now);
        $stmt->execute();
        $stmt->close();

        createUserNotification(
            $db,
            $user_email,
            'Currency exchange completed',
            velmoraFormatCurrency($sourcePositive, $sourceCurrency) . ' was exchanged for ' . velmoraFormatCurrency($targetAmount, $targetCurrency) . '. Trade ' . $tradeId . '.',
            'FX Trade',
            '/dashboard/exchange/'
        );

        $db->commit();
        unset($_SESSION['v3_fx_quote']);
        v3PostMessage('success', 'Exchange executed. Trade reference: ' . $tradeId);
    } catch (Throwable $e) {
        $db->rollback();
        v3PostMessage('error', 'Exchange could not be executed: ' . $e->getMessage());
    } finally {
        $db->close();
    }
    v3Redirect('/dashboard/exchange/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['v3_quote_transfer'])) {
    v3VerifyPost();
    $from = preg_replace('/\D+/', '', (string)($_POST['from_account'] ?? ''));
    $recipientName = trim((string)($_POST['recipient_name'] ?? ''));
    $bank = trim((string)($_POST['bank_name'] ?? ''));
    $recipient = trim((string)($_POST['account_number'] ?? ''));
    $accountType = trim((string)($_POST['account_type'] ?? ''));
    $recipientCurrency = strtoupper(trim((string)($_POST['currency'] ?? '')));
    $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);

    $db = connectToDatabase();
    $source = v3OwnedAccount($db, $user_email, $from);
    if (!$source || $source['account_status'] !== 'Active' || $recipientName === '' || $recipient === '' || $amount === false || $amount <= 0 || !velmoraIsSupportedCurrency($recipientCurrency)) {
        $db->close();
        v3PostMessage('error', 'Complete all transfer details correctly.');
        v3Redirect('/dashboard/transfer/');
    }

    $balance = v3AccountBalance($db, $from);
    $db->close();
    if ((float)$amount > $balance) {
        v3PostMessage('error', 'Insufficient available funds in the selected account.');
        v3Redirect('/dashboard/transfer/');
    }

    try {
        $fx = velmoraFxQuote((float)$amount, $source['currency'], $recipientCurrency);
    } catch (Throwable $e) {
        v3PostMessage('error', 'A transfer quote could not be prepared.');
        v3Redirect('/dashboard/transfer/');
    }

    $_SESSION['v3_transfer_quote'] = [
        'quote_id' => 'TQ-' . strtoupper(bin2hex(random_bytes(6))),
        'from_account' => $from,
        'source_currency' => $source['currency'],
        'amount' => (float)$amount,
        'recipient_name' => $recipientName,
        'bank_name' => $bank,
        'recipient_account' => $recipient,
        'recipient_account_type' => $accountType ?: 'Not Sure',
        'recipient_currency' => $recipientCurrency,
        'recipient_amount' => (float)$fx['amount_out'],
        'customer_rate' => (float)$fx['customer_rate'],
        'spread_bps' => (int)$fx['spread_bps'],
        'expires_at' => time() + 60,
    ];
    v3Redirect('/dashboard/transfer/?review=1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['v3_execute_transfer'])) {
    v3VerifyPost();
    $quote = $_SESSION['v3_transfer_quote'] ?? null;
    $quoteId = (string)($_POST['quote_id'] ?? '');

    if (!is_array($quote) || $quoteId === '' || !hash_equals((string)$quote['quote_id'], $quoteId) || time() > (int)$quote['expires_at']) {
        unset($_SESSION['v3_transfer_quote']);
        v3PostMessage('error', 'That transfer quote expired. Please review a fresh quote.');
        v3Redirect('/dashboard/transfer/');
    }

    $db = connectToDatabase();
    $db->begin_transaction();
    try {
        $source = v3OwnedAccountForUpdate($db, $user_email, (string)$quote['from_account']);
        if (
            !$source ||
            $source['account_status'] !== 'Active' ||
            $source['currency'] !== $quote['source_currency']
        ) throw new RuntimeException('Source account details or status changed.');
        if ((float)$quote['amount'] > v3AccountBalance($db, (string)$quote['from_account'])) throw new RuntimeException('Insufficient funds.');

        $txid = v3TransactionId('TRF');
        $type = 'Transfer';
        $status = 'Pending';
        $amount = -abs((float)$quote['amount']);
        $now = time();
        $recipientName = (string)$quote['recipient_name'];
        $description = 'Transfer to ' . $recipientName . ' account number ' . $quote['recipient_account'];
        $sourceAccount = (string)$quote['from_account'];
        $sourceCurrency = (string)$quote['source_currency'];
        $recipientCurrency = (string)$quote['recipient_currency'];
        $recipientAmount = (float)$quote['recipient_amount'];
        $rate = (float)$quote['customer_rate'];
        $spread = (int)$quote['spread_bps'];
        $bank = (string)$quote['bank_name'];
        $acctType = (string)$quote['recipient_account_type'];
        $recipient = (string)$quote['recipient_account'];

        $stmt = $db->prepare("INSERT INTO transactions
            (transaction_id,type,user_email,account_number,amount,currency,description,status,time,to_bank_name,recipient_name,to_account_type,to_account_number,counter_currency,counter_amount,fx_rate,fx_spread_bps)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('ssssdsssisssssddi', $txid, $type, $user_email, $sourceAccount, $amount, $sourceCurrency, $description, $status, $now, $bank, $recipientName, $acctType, $recipient, $recipientCurrency, $recipientAmount, $rate, $spread);
        $stmt->execute();
        $stmt->close();

        $valueDate = date('Y-m-d', $now);
        $postedAt = date('Y-m-d H:i:s', $now);
        $metaStmt = $db->prepare("UPDATE transactions SET channel='Online Banking', value_date=?, posted_at=? WHERE transaction_id=?");
        if ($metaStmt) {
            $metaStmt->bind_param('sss', $valueDate, $postedAt, $txid);
            $metaStmt->execute();
            $metaStmt->close();
        }

        $beneficiaryStmt = $db->prepare("UPDATE beneficiaries SET last_used_at=NOW() WHERE user_email=? AND bank_name=? AND account_number=? AND status='Active'");
        if ($beneficiaryStmt) {
            $beneficiaryStmt->bind_param('sss', $user_email, $bank, $recipient);
            $beneficiaryStmt->execute();
            $beneficiaryStmt->close();
        }

        createUserNotification(
            $db,
            $user_email,
            'Transfer submitted',
            'Transfer ' . $txid . ' to ' . $recipientName . ' account ending ' . substr($recipient, -4) . ' has been submitted for processing.',
            'Transfer',
            '/dashboard/transactions/detail/?ref=' . urlencode($txid)
        );
        $db->commit();
        $db->close();

        unset($_SESSION['v3_transfer_quote']);
        v3PostMessage('success', 'Transfer submitted for bank processing. Reference: ' . $txid);
    } catch (Throwable $e) {
        $db->rollback();
        $db->close();
        v3PostMessage('error', 'Transfer could not be submitted: ' . $e->getMessage());
    }
    v3Redirect('/dashboard/transfer/');
}
