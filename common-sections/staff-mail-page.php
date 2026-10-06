<?php
if (!isset($staffMailRole, $staffMailBase)) {
    http_response_code(500);
    exit('Mail module configuration is missing.');
}

require_once __DIR__ . '/staff-mail.php';

$mailbox = staffMailRoleMailbox((string)$staffMailRole);
$folderRequested = trim((string)($_GET['folder'] ?? 'INBOX'));
$action = trim((string)($_GET['action'] ?? 'list'));
$query = trim((string)($_GET['q'] ?? ''));
$uid = (int)($_GET['uid'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_mail_send'])) {
    cpv2Verify();
    [$sent, $message] = staffMailSend($mailbox, [
        'to' => $_POST['to'] ?? '',
        'cc' => $_POST['cc'] ?? '',
        'bcc' => $_POST['bcc'] ?? '',
        'subject' => $_POST['subject'] ?? '',
        'body' => $_POST['body'] ?? '',
    ], $_FILES['attachments'] ?? []);

    cpv2Flash($sent ? 'success' : 'error', $message);
    if ($sent) {
        cpv2Go($staffMailBase . '?folder=' . rawurlencode(staffMailFindSpecialFolder($mailbox, 'sent') ?: 'INBOX'));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_mail_seen'])) {
    cpv2Verify();
    $postUid = (int)($_POST['uid'] ?? 0);
    $postFolder = (string)($_POST['folder'] ?? 'INBOX');
    $seen = (string)($_POST['seen'] ?? '1') === '1';
    staffMailToggleSeen($mailbox, $postFolder, $postUid, $seen);
    cpv2Go($staffMailBase . '?folder=' . rawurlencode($postFolder));
}

$folders = staffMailConfigured($mailbox) && staffMailImapAvailable() ? staffMailFolders($mailbox) : ['INBOX'];
$folder = staffMailResolveFolder($mailbox, $folderRequested);
$list = ['folder'=>$folder,'messages'=>[],'error'=>null];
$messageView = null;

if (staffMailConfigured($mailbox) && staffMailImapAvailable()) {
    if ($action === 'view' && $uid > 0) {
        $messageView = staffMailMessage($mailbox, $folder, $uid);
    } else {
        $list = staffMailList($mailbox, $folder, $query);
    }
}

$composeTo = trim((string)($_GET['to'] ?? ''));
$composeSubject = trim((string)($_GET['subject'] ?? ''));
$composeBody = '';

if (($action === 'reply' || $action === 'forward') && $uid > 0 && staffMailConfigured($mailbox) && staffMailImapAvailable()) {
    $source = staffMailMessage($mailbox, $folder, $uid);
    if ($source) {
        if ($action === 'reply') {
            if (preg_match('/<([^>]+)>/', $source['from'], $m)) $composeTo = $m[1];
            elseif (filter_var($source['from'], FILTER_VALIDATE_EMAIL)) $composeTo = $source['from'];
            $composeSubject = preg_match('/^Re:/i', $source['subject']) ? $source['subject'] : 'Re: ' . $source['subject'];
        } else {
            $composeSubject = preg_match('/^Fwd:/i', $source['subject']) ? $source['subject'] : 'Fwd: ' . $source['subject'];
        }
        $quoted = trim((string)($source['plain'] !== '' ? $source['plain'] : strip_tags($source['html'])));
        $composeBody = "\n\n--- Original message ---\nFrom: " . $source['from'] . "\nDate: " . $source['date'] . "\nSubject: " . $source['subject'] . "\n\n" . $quoted;
    }
}

cpv2Start('Mail', 'mail');
?>
<section class="op-heading">
  <div>
    <span class="op-kicker">STAFF WEBMAIL</span>
    <h1>Mail</h1>
    <p>Send and receive mail through Velmora's shared operations mailbox.</p>
  </div>
  <a class="op-btn primary" href="<?php echo htmlspecialchars($staffMailBase); ?>?action=compose"><span class="material-symbols-rounded">edit</span>Compose</a>
</section>

<?php if (!staffMailConfigured($mailbox)): ?>
<div class="op-alert error">The shared operations mailbox is not connected on the production host yet. Its IMAP/SMTP credentials must be available to the server environment.</div>
<?php elseif (!staffMailImapAvailable()): ?>
<div class="op-alert error">The server PHP IMAP extension is not enabled. Sending can use SMTP, but Inbox/Sent sync requires IMAP.</div>
<?php endif; ?>

<div class="op-mail-shell">
  <aside class="op-panel op-mail-sidebar">
    <div class="op-mail-account">
      <span class="material-symbols-rounded">alternate_email</span>
      <div><strong><?php echo htmlspecialchars((string)$mailbox['email']); ?></strong><small>Shared operations mailbox · <?php echo htmlspecialchars(ucfirst((string)$staffMailRole)); ?> panel</small></div>
    </div>
    <a class="op-btn primary op-mail-compose" href="<?php echo htmlspecialchars($staffMailBase); ?>?action=compose"><span class="material-symbols-rounded">edit</span>Compose</a>
    <nav class="op-mail-folders">
      <?php foreach ($folders as $mailFolder):
        $icon = stripos($mailFolder,'sent')!==false ? 'send' : (stripos($mailFolder,'draft')!==false ? 'draft' : (stripos($mailFolder,'trash')!==false || stripos($mailFolder,'deleted')!==false ? 'delete' : ($mailFolder==='INBOX'?'inbox':'folder')));
      ?>
      <a class="<?php echo strcasecmp($folder,$mailFolder)===0 && $action==='list'?'active':''; ?>" href="<?php echo htmlspecialchars($staffMailBase); ?>?folder=<?php echo rawurlencode($mailFolder); ?>">
        <span class="material-symbols-rounded"><?php echo $icon; ?></span><?php echo htmlspecialchars($mailFolder); ?>
      </a>
      <?php endforeach; ?>
    </nav>
  </aside>

  <section class="op-panel op-mail-main">
    <?php if ($action === 'compose' || $action === 'reply' || $action === 'forward'): ?>
      <div class="op-panel-head"><div><span class="op-kicker">NEW MESSAGE</span><h2>Compose email</h2></div><a href="<?php echo htmlspecialchars($staffMailBase); ?>">Close</a></div>
      <form method="post" enctype="multipart/form-data" class="op-form op-mail-compose-form">
        <?php echo cpv2CsrfInput(); ?>
        <label><span>To</span><input type="text" name="to" required value="<?php echo htmlspecialchars($composeTo); ?>" placeholder="name@example.com"></label>
        <div class="op-mail-two"><label><span>CC</span><input type="text" name="cc"></label><label><span>BCC</span><input type="text" name="bcc"></label></div>
        <label><span>Subject</span><input type="text" name="subject" maxlength="250" required value="<?php echo htmlspecialchars($composeSubject); ?>"></label>
        <label><span>Message</span><textarea name="body" class="op-mail-editor" required><?php echo htmlspecialchars($composeBody); ?></textarea></label>
        <label><span>Attachments <small>(up to 10 MB each, 20 MB total)</small></span><input type="file" name="attachments[]" multiple></label>
        <div class="op-mail-compose-actions">
          <button class="op-btn primary" type="submit" name="staff_mail_send" value="1"><span class="material-symbols-rounded">send</span>Send</button>
        </div>
      </form>

    <?php elseif ($action === 'view' && $messageView): ?>
      <div class="op-mail-message-head">
        <a class="op-btn secondary" href="<?php echo htmlspecialchars($staffMailBase); ?>?folder=<?php echo rawurlencode($folder); ?>"><span class="material-symbols-rounded">arrow_back</span>Back</a>
        <div>
          <h2><?php echo htmlspecialchars($messageView['subject']); ?></h2>
          <div class="op-mail-meta"><strong><?php echo htmlspecialchars($messageView['from']); ?></strong><span><?php echo htmlspecialchars($messageView['date']); ?></span></div>
          <small>To: <?php echo htmlspecialchars(implode(', ', $messageView['to'])); ?><?php if ($messageView['cc']): ?> · CC: <?php echo htmlspecialchars(implode(', ', $messageView['cc'])); ?><?php endif; ?></small>
        </div>
      </div>
      <article class="op-mail-body"><?php
        $displayBody = trim((string)($messageView['plain'] !== '' ? $messageView['plain'] : strip_tags($messageView['html'])));
        echo nl2br(htmlspecialchars($displayBody, ENT_QUOTES, 'UTF-8'));
      ?></article>
      <div class="op-mail-reply-actions">
        <a class="op-btn primary" href="<?php echo htmlspecialchars($staffMailBase); ?>?action=reply&folder=<?php echo rawurlencode($folder); ?>&uid=<?php echo (int)$messageView['uid']; ?>"><span class="material-symbols-rounded">reply</span>Reply</a>
        <a class="op-btn secondary" href="<?php echo htmlspecialchars($staffMailBase); ?>?action=forward&folder=<?php echo rawurlencode($folder); ?>&uid=<?php echo (int)$messageView['uid']; ?>"><span class="material-symbols-rounded">forward</span>Forward</a>
      </div>

    <?php else: ?>
      <div class="op-mail-toolbar">
        <div><span class="op-kicker">MAILBOX</span><h2><?php echo htmlspecialchars($folder); ?></h2></div>
        <form method="get" class="op-search op-mail-search">
          <input type="hidden" name="folder" value="<?php echo htmlspecialchars($folder); ?>">
          <input type="search" name="q" value="<?php echo htmlspecialchars($query); ?>" placeholder="Search this mailbox">
          <button class="op-btn secondary" type="submit"><span class="material-symbols-rounded">search</span>Search</button>
        </form>
      </div>
      <?php if (!empty($list['error'])): ?><div class="op-alert error"><?php echo htmlspecialchars((string)$list['error']); ?></div><?php endif; ?>
      <div class="op-mail-list">
        <?php foreach ($list['messages'] as $row): ?>
        <article class="<?php echo !$row['seen']?'unread':''; ?>">
          <a class="op-mail-row" href="<?php echo htmlspecialchars($staffMailBase); ?>?action=view&folder=<?php echo rawurlencode($folder); ?>&uid=<?php echo (int)$row['uid']; ?>">
            <span class="material-symbols-rounded op-mail-star"><?php echo $row['flagged']?'star':'star_outline'; ?></span>
            <div class="op-mail-from"><?php echo htmlspecialchars($row['from'] ?: '(Unknown sender)'); ?></div>
            <div class="op-mail-subject"><strong><?php echo htmlspecialchars($row['subject']); ?></strong><?php if($row['answered']): ?><span class="material-symbols-rounded">reply</span><?php endif; ?></div>
            <time><?php echo $row['timestamp']>0?htmlspecialchars(date('M d', $row['timestamp'])):''; ?></time>
          </a>
          <form method="post" class="op-mail-seen-form">
            <?php echo cpv2CsrfInput(); ?>
            <input type="hidden" name="uid" value="<?php echo (int)$row['uid']; ?>">
            <input type="hidden" name="folder" value="<?php echo htmlspecialchars($folder); ?>">
            <input type="hidden" name="seen" value="<?php echo $row['seen']?'0':'1'; ?>">
            <button type="submit" name="staff_mail_seen" value="1" title="<?php echo $row['seen']?'Mark unread':'Mark read'; ?>"><span class="material-symbols-rounded"><?php echo $row['seen']?'mark_email_unread':'drafts'; ?></span></button>
          </form>
        </article>
        <?php endforeach; ?>
        <?php if (empty($list['messages'])): ?><div class="op-mail-empty"><span class="material-symbols-rounded">inbox</span><strong>No messages found</strong><p>This folder is empty or the current search returned no messages.</p></div><?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php cpv2End(); ?>