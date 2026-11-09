<?php
require_once __DIR__ . '/auth.php';
admin_need('set');
require_once __DIR__ . '/../config/mail.php';
ef_mail_log_schema($conn);

$cfgFile = __DIR__ . '/../config/mail.local.php';
$cfg = is_file($cfgFile) ? require $cfgFile : null;
$configured = ef_mail_configured();
$host = is_array($cfg) ? (string) ($cfg['host'] ?? '') : (string) (getenv('EXPENSEFLOW_SMTP_HOST') ?: 'smtp.gmail.com');
$port = is_array($cfg) ? (int) ($cfg['port'] ?? 587) : (int) (getenv('EXPENSEFLOW_SMTP_PORT') ?: 587);
$user = is_array($cfg) ? (string) ($cfg['username'] ?? '') : (string) getenv('EXPENSEFLOW_SMTP_USERNAME');

$st = $conn->query("SELECT SUM(ok=1) ok24, SUM(ok=0) bad24, MAX(CASE WHEN ok=1 THEN created_at END) last_ok, MAX(CASE WHEN ok=0 THEN created_at END) last_bad FROM mail_log WHERE created_at > (NOW() - INTERVAL 1 DAY)")->fetch(PDO::FETCH_ASSOC);
$lastOk = $conn->query("SELECT MAX(created_at) FROM mail_log WHERE ok = 1")->fetchColumn();
$rows = $conn->query("SELECT created_at, to_mask, subject, ok, error, ms FROM mail_log ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$meSt = $conn->prepare('SELECT email FROM users WHERE id = ?');
$meSt->execute([$admin['id']]);
$myEmail = (string) $meSt->fetchColumn();
$when = fn(?string $t) => $t ? date('d M, H:i', strtotime($t)) : '–';
$blocked = false;
foreach ($rows as $r) {
    if (!$r['ok'] && preg_match('/connect\(\) failed|Could not connect|Failed to connect|timed out|Connection (refused|timed)/i', $r['error'])) {
        $blocked = true;
        break;
    }
}

admin_page_start(['title' => 'Email log', 'sub' => (int) ($st['ok24'] ?? 0) . ' sent · ' . (int) ($st['bad24'] ?? 0) . ' not sent in 24 hours', 'active' => 'mail']);
?>
<div class="g card" style="margin-bottom:14px">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
    <div><small class="k">E-mail set up</small><div style="font-size:1.2rem;font-weight:800"><?= $configured ? 'Yes' : 'No' ?></div></div>
    <div><small class="k">Server</small><div style="font-weight:700"><?= e($host !== '' ? $host . ':' . $port : '–') ?></div></div>
    <div><small class="k">Sender account</small><div style="font-weight:700"><?= e($user !== '' ? ef_mail_mask($user) : '–') ?></div></div>
    <div><small class="k">Sent (24 h)</small><div style="font-size:1.5rem;font-weight:800"><?= (int) ($st['ok24'] ?? 0) ?></div></div>
    <div><small class="k">Not sent (24 h)</small><div style="font-size:1.5rem;font-weight:800"><?= (int) ($st['bad24'] ?? 0) ?></div></div>
    <div><small class="k">Last success</small><div style="font-weight:700"><?= e($when($lastOk ?: null)) ?></div></div>
  </div>
  <small class="k" style="display:block;margin-top:10px">Every e-mail the site tries to send is listed here with the reason when it fails. Addresses are partly hidden and codes are never stored.</small>
<?php if (!$configured): ?>
  <div class="msg err" style="margin-top:12px">No e-mail account is set up on this server, so no e-mail can be sent. Add the SMTP secrets in GitHub and deploy again.</div>
<?php elseif ($blocked): ?>
  <div class="msg err" style="margin-top:12px">The server could not reach the mail server (connect failed or timed out). Some free hosts block outgoing mail ports. Ask the host, or switch to a mail service that works over the normal web port.</div>
<?php endif; ?>
</div>

<div class="g card" style="margin-bottom:14px">
  <h3 style="margin:0 0 10px">Send a test e-mail</h3>
  <form method="post" action="mail-action.php" class="toolbar">
    <?= csrf_field() ?>
    <input class="inp" type="email" name="to" value="<?= e($myEmail) ?>" placeholder="you@example.com" maxlength="190" required aria-label="Send to">
    <button class="btn sm" type="submit">Send test</button>
  </form>
</div>

<div class="g card">
  <h3 style="margin:0 0 8px">Recent e-mails</h3>
  <div class="tbl-wrap"><table class="tbl fixm wide">
    <tr><th>When</th><th>To</th><th class="hm">What</th><th>Result</th></tr>
<?php foreach ($rows as $r): ?>
    <tr>
      <td class="nw"><?= e($when($r['created_at'])) ?></td>
      <td><?= e($r['to_mask']) ?><small class="dt-m"><?= e($r['subject']) ?></small></td>
      <td class="hm"><?= e($r['subject']) ?></td>
      <td><?= $r['ok'] ? '<span class="tag tg">Sent</span> <small class="k">' . (int) $r['ms'] . ' ms</small>' : '<span class="tag tx">Not sent</span><small style="display:block;margin-top:4px">' . e($r['error']) . '</small>' ?></td>
    </tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="4" class="k" style="padding:18px 12px">Nothing yet. Try "Send a test e-mail", or ask for a password reset code.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php admin_page_end();
