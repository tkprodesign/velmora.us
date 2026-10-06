<?php
declare(strict_types=1);

function staffMailRoleMailbox(string $role): array {
    $roleCfg = velmoraBackendRoleConfig($role);
    $supportCfg = velmoraBackendRoleConfig('support');

    // Velmora currently has one physical SpaceMail mailbox. Staff roles keep
    // separate application credentials, but all three webmail surfaces use
    // the shared operations mailbox for IMAP/SMTP transport.
    $transportEmail = strtolower(trim((string)(getenv('SMTP_USERNAME') ?: ($supportCfg['email'] ?? ''))));
    $transportPassword = (string)(getenv('SMTP_PASSWORD') ?: ($supportCfg['password'] ?? ''));
    $identityEmail = strtolower(trim((string)($roleCfg['email'] ?? '')));

    return [
        'role' => $role,
        'email' => $transportEmail,
        'identity_email' => $identityEmail,
        'password' => $transportPassword,
        'shared_mailbox' => true,
        'imap_host' => getenv('IMAP_HOST') ?: 'mail.spacemail.com',
        'imap_port' => (int)(getenv('IMAP_PORT') ?: 993),
        'smtp_host' => getenv('SMTP_HOST') ?: 'mail.spacemail.com',
        'smtp_port' => (int)(getenv('SMTP_PORT') ?: 465),
    ];
}

function staffMailConfigured(array $mailbox): bool {
    return ($mailbox['email'] ?? '') !== '' && ($mailbox['password'] ?? '') !== '';
}

function staffMailImapAvailable(): bool {
    return function_exists('imap_open') && function_exists('imap_search') && function_exists('imap_fetch_overview');
}

function staffMailRoot(array $mailbox): string {
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)$mailbox['imap_host']);
    $port = (int)$mailbox['imap_port'];
    return '{' . $host . ':' . $port . '/imap/ssl}';
}

function staffMailDecodeHeader(?string $value): string {
    $value = (string)$value;
    if ($value === '' || !function_exists('imap_mime_header_decode')) return $value;
    $parts = imap_mime_header_decode($value);
    $out = '';
    foreach ($parts as $part) {
        $charset = strtoupper((string)($part->charset ?? 'UTF-8'));
        $text = (string)($part->text ?? '');
        if ($charset !== 'DEFAULT' && $charset !== 'UTF-8' && function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if ($converted !== false) $text = $converted;
        }
        $out .= $text;
    }
    return $out;
}

function staffMailOpen(array $mailbox, string $folder = 'INBOX', int $options = 0) {
    if (!staffMailConfigured($mailbox) || !staffMailImapAvailable()) return false;
    $folder = trim($folder) !== '' ? trim($folder) : 'INBOX';
    return @imap_open(
        staffMailRoot($mailbox) . $folder,
        (string)$mailbox['email'],
        (string)$mailbox['password'],
        $options,
        1
    );
}

function staffMailFolders(array $mailbox): array {
    $imap = staffMailOpen($mailbox, 'INBOX', OP_HALFOPEN);
    if (!$imap) return ['INBOX'];

    $root = staffMailRoot($mailbox);
    $rows = @imap_getmailboxes($imap, $root, '*') ?: [];
    $folders = [];
    foreach ($rows as $row) {
        $name = (string)($row->name ?? '');
        if (str_starts_with($name, $root)) $name = substr($name, strlen($root));
        if ($name === '') continue;
        if (function_exists('imap_utf7_decode')) $name = @imap_utf7_decode($name) ?: $name;
        $folders[] = $name;
    }
    @imap_close($imap);

    $folders = array_values(array_unique($folders));
    usort($folders, function(string $a, string $b): int {
        $priority = ['INBOX'=>0,'Sent'=>1,'Sent Mail'=>1,'Sent Items'=>1,'Drafts'=>2,'Trash'=>3,'Junk'=>4,'Spam'=>4];
        return ($priority[$a] ?? 50) <=> ($priority[$b] ?? 50) ?: strcasecmp($a,$b);
    });
    return $folders ?: ['INBOX'];
}

function staffMailResolveFolder(array $mailbox, string $requested): string {
    $folders = staffMailFolders($mailbox);
    foreach ($folders as $folder) {
        if (strcasecmp($folder, $requested) === 0) return $folder;
    }
    return 'INBOX';
}

function staffMailFindSpecialFolder(array $mailbox, string $type): ?string {
    $folders = staffMailFolders($mailbox);
    $patterns = $type === 'sent'
        ? ['sent','sent mail','sent items']
        : ($type === 'drafts' ? ['drafts','draft'] : ['trash','deleted items','bin']);

    foreach ($folders as $folder) {
        foreach ($patterns as $pattern) {
            if (strcasecmp($folder, $pattern) === 0) return $folder;
        }
    }
    foreach ($folders as $folder) {
        foreach ($patterns as $pattern) {
            if (stripos($folder, $pattern) !== false) return $folder;
        }
    }
    return null;
}

function staffMailAddressLabel($address): string {
    if (!$address) return '';
    $email = trim(((string)($address->mailbox ?? '')) . '@' . ((string)($address->host ?? '')), '@');
    $name = staffMailDecodeHeader((string)($address->personal ?? ''));
    return $name !== '' ? $name . ' <' . $email . '>' : $email;
}

function staffMailList(array $mailbox, string $folder, string $query = '', int $limit = 75): array {
    $folder = staffMailResolveFolder($mailbox, $folder);
    $imap = staffMailOpen($mailbox, $folder);
    if (!$imap) return ['folder'=>$folder,'messages'=>[],'error'=>'Mailbox connection failed.'];

    $criteria = 'ALL';
    $query = trim($query);
    if ($query !== '') {
        $safe = str_replace(['\\','"'], ['\\\\','\"'], $query);
        $criteria = 'TEXT "' . $safe . '"';
    }
    $uids = @imap_search($imap, $criteria, SE_UID) ?: [];
    rsort($uids, SORT_NUMERIC);
    $uids = array_slice($uids, 0, max(1, min($limit, 200)));

    $messages = [];
    foreach ($uids as $uid) {
        $overview = @imap_fetch_overview($imap, (string)$uid, FT_UID);
        $o = $overview[0] ?? null;
        if (!$o) continue;

        $header = @imap_headerinfo($imap, @imap_msgno($imap, (int)$uid));
        $from = '';
        if ($header && !empty($header->from[0])) $from = staffMailAddressLabel($header->from[0]);

        $messages[] = [
            'uid' => (int)$uid,
            'subject' => staffMailDecodeHeader((string)($o->subject ?? '(No subject)')),
            'from' => $from,
            'date' => (string)($o->date ?? ''),
            'timestamp' => isset($o->udate) ? (int)$o->udate : 0,
            'seen' => !empty($o->seen),
            'answered' => !empty($o->answered),
            'flagged' => !empty($o->flagged),
            'size' => (int)($o->size ?? 0),
        ];
    }

    @imap_close($imap);
    return ['folder'=>$folder,'messages'=>$messages,'error'=>null];
}

function staffMailPartText($imap, int $uid, $structure, string $part = ''): array {
    $plain = '';
    $html = '';

    $encodingDecode = static function(string $data, int $encoding): string {
        return match($encoding) {
            3 => base64_decode($data, true) ?: '',
            4 => quoted_printable_decode($data),
            default => $data,
        };
    };

    if (isset($structure->parts) && is_array($structure->parts)) {
        foreach ($structure->parts as $index => $sub) {
            $subPart = $part === '' ? (string)($index + 1) : $part . '.' . ($index + 1);
            $child = staffMailPartText($imap, $uid, $sub, $subPart);
            if ($plain === '' && $child['plain'] !== '') $plain = $child['plain'];
            if ($html === '' && $child['html'] !== '') $html = $child['html'];
        }
        return ['plain'=>$plain,'html'=>$html];
    }

    $type = (int)($structure->type ?? 0);
    $subtype = strtoupper((string)($structure->subtype ?? 'PLAIN'));
    if ($type !== 0) return ['plain'=>'','html'=>''];

    $raw = $part === ''
        ? (string)@imap_body($imap, $uid, FT_UID | FT_PEEK)
        : (string)@imap_fetchbody($imap, $uid, $part, FT_UID | FT_PEEK);
    $decoded = $encodingDecode($raw, (int)($structure->encoding ?? 0));

    $charset = 'UTF-8';
    foreach (array_merge((array)($structure->parameters ?? []), (array)($structure->dparameters ?? [])) as $param) {
        if (strtolower((string)($param->attribute ?? '')) === 'charset') {
            $charset = (string)$param->value;
        }
    }
    if (strtoupper($charset) !== 'UTF-8' && function_exists('iconv')) {
        $converted = @iconv($charset, 'UTF-8//IGNORE', $decoded);
        if ($converted !== false) $decoded = $converted;
    }

    return $subtype === 'HTML'
        ? ['plain'=>'','html'=>$decoded]
        : ['plain'=>$decoded,'html'=>''];
}

function staffMailMessage(array $mailbox, string $folder, int $uid): ?array {
    if ($uid <= 0) return null;
    $folder = staffMailResolveFolder($mailbox, $folder);
    $imap = staffMailOpen($mailbox, $folder);
    if (!$imap) return null;

    $overview = @imap_fetch_overview($imap, (string)$uid, FT_UID);
    $o = $overview[0] ?? null;
    if (!$o) {
        @imap_close($imap);
        return null;
    }

    @imap_setflag_full($imap, (string)$uid, '\\Seen', ST_UID);
    $msgno = @imap_msgno($imap, $uid);
    $header = $msgno ? @imap_headerinfo($imap, $msgno) : null;
    $structure = @imap_fetchstructure($imap, $uid, FT_UID);
    $body = $structure ? staffMailPartText($imap, $uid, $structure) : ['plain'=>(string)@imap_body($imap,$uid,FT_UID),'html'=>''];

    $to = [];
    if ($header && !empty($header->to)) foreach ($header->to as $addr) $to[] = staffMailAddressLabel($addr);
    $cc = [];
    if ($header && !empty($header->cc)) foreach ($header->cc as $addr) $cc[] = staffMailAddressLabel($addr);

    $from = ($header && !empty($header->from[0])) ? staffMailAddressLabel($header->from[0]) : '';

    @imap_close($imap);

    return [
        'uid'=>$uid,
        'subject'=>staffMailDecodeHeader((string)($o->subject ?? '(No subject)')),
        'from'=>$from,
        'to'=>$to,
        'cc'=>$cc,
        'date'=>(string)($o->date ?? ''),
        'timestamp'=>isset($o->udate)?(int)$o->udate:0,
        'plain'=>$body['plain'],
        'html'=>$body['html'],
    ];
}

function staffMailToggleSeen(array $mailbox, string $folder, int $uid, bool $seen): bool {
    $folder = staffMailResolveFolder($mailbox, $folder);
    $imap = staffMailOpen($mailbox, $folder);
    if (!$imap) return false;
    $ok = $seen
        ? @imap_setflag_full($imap, (string)$uid, '\\Seen', ST_UID)
        : @imap_clearflag_full($imap, (string)$uid, '\\Seen', ST_UID);
    @imap_close($imap);
    return (bool)$ok;
}

function staffMailSend(array $mailbox, array $payload, array $files = []): array {
    if (!staffMailConfigured($mailbox)) return [false,'Mailbox credentials are not available on this host.'];
    if (!loadPHPMailerClasses()) return [false,'Mail transport library is unavailable.'];

    $to = array_values(array_filter(array_map('trim', preg_split('/[,;]/', (string)($payload['to'] ?? '')) ?: [])));
    $cc = array_values(array_filter(array_map('trim', preg_split('/[,;]/', (string)($payload['cc'] ?? '')) ?: [])));
    $bcc = array_values(array_filter(array_map('trim', preg_split('/[,;]/', (string)($payload['bcc'] ?? '')) ?: [])));
    if (!$to) return [false,'Enter at least one recipient.'];

    foreach (array_merge($to,$cc,$bcc) as $address) {
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) return [false,'One or more email addresses are invalid.'];
    }

    $subject = trim((string)($payload['subject'] ?? ''));
    $body = trim((string)($payload['body'] ?? ''));
    if ($subject === '' || $body === '') return [false,'Subject and message are required.'];

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = (string)$mailbox['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = (string)$mailbox['email'];
        $mail->Password = (string)$mailbox['password'];
        $mail->Port = (int)$mailbox['smtp_port'];
        $mail->SMTPSecure = $mail->Port === 465
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        $mail->setFrom((string)$mailbox['email'], 'Velmora Bank');
        foreach ($to as $address) $mail->addAddress($address);
        foreach ($cc as $address) $mail->addCC($address);
        foreach ($bcc as $address) $mail->addBCC($address);

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        $mail->AltBody = $body;

        $total = 0;
        if (!empty($files['tmp_name']) && is_array($files['tmp_name'])) {
            foreach ($files['tmp_name'] as $i => $tmp) {
                if (!is_uploaded_file($tmp)) continue;
                $size = (int)($files['size'][$i] ?? 0);
                $total += $size;
                if ($size <= 0 || $size > 10 * 1024 * 1024 || $total > 20 * 1024 * 1024) {
                    return [false,'Attachments exceed the permitted size.'];
                }
                $name = basename((string)($files['name'][$i] ?? 'attachment'));
                $mail->addAttachment($tmp, $name);
            }
        }

        $mail->send();

        if (staffMailImapAvailable()) {
            $sentFolder = staffMailFindSpecialFolder($mailbox, 'sent');
            if ($sentFolder) {
                $imap = staffMailOpen($mailbox, $sentFolder, OP_HALFOPEN);
                if ($imap) {
                    @imap_append($imap, staffMailRoot($mailbox) . $sentFolder, $mail->getSentMIMEMessage(), '\\Seen');
                    @imap_close($imap);
                }
            }
        }

        return [true,'Message sent.'];
    } catch (Throwable $e) {
        error_log('Staff mail send failed: ' . $e->getMessage());
        return [false,'Message could not be sent.'];
    }
}
