<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// The bundled PHPMailer copy is used on purpose: it does not need Composer's vendor/ folder.
require_once __DIR__ . '/../auth/src/Exception.php';
require_once __DIR__ . '/../auth/src/PHPMailer.php';
require_once __DIR__ . '/../auth/src/SMTP.php';

/**
 * SMTP settings come from config/mail.local.php (created by the deployment
 * workflow from GitHub secrets) or from EXPENSEFLOW_SMTP_* environment variables.
 * Credentials must never be written into the source code.
 */
function mail_settings(): ?array
{
    $settingsPath = __DIR__ . '/mail.local.php';
    $settings = is_file($settingsPath) ? require $settingsPath : null;

    if (!is_array($settings)) {
        $settings = [
            'host' => getenv('EXPENSEFLOW_SMTP_HOST') ?: 'smtp.gmail.com',
            'port' => (int) (getenv('EXPENSEFLOW_SMTP_PORT') ?: 587),
            'username' => getenv('EXPENSEFLOW_SMTP_USERNAME') ?: '',
            'password' => getenv('EXPENSEFLOW_SMTP_PASSWORD') ?: '',
            'encryption' => getenv('EXPENSEFLOW_SMTP_ENCRYPTION') ?: 'tls',
            'from_email' => getenv('EXPENSEFLOW_MAIL_FROM') ?: (getenv('EXPENSEFLOW_SMTP_USERNAME') ?: ''),
            'from_name' => getenv('EXPENSEFLOW_MAIL_FROM_NAME') ?: 'ExpenseFlow',
            'reply_to' => getenv('EXPENSEFLOW_MAIL_REPLY_TO') ?: '',
        ];
    }

    if (empty($settings['username']) || empty($settings['password'])) {
        error_log('ExpenseFlow mail settings are missing.');
        return null;
    }

    return $settings;
}

/** Sends one HTML email through the configured SMTP server. Returns true when the server accepted it. */
function ef_send_mail(string $email, string $name, string $subject, string $html, string $alt, bool $logo = false): bool
{
    $settings = mail_settings();
    if ($settings === null) {
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $settings['host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $settings['username'];
        $mail->Password = $settings['password'];
        $encryption = $settings['encryption'] ?? 'tls';
        if ($encryption === 'none') {
            // Only for a trusted local relay (for example a development mail catcher).
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure = ($encryption === 'ssl')
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->Port = (int) ($settings['port'] ?? 587);
        $mail->Timeout = 10;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        $mail->setFrom($settings['from_email'] ?: $settings['username'], $settings['from_name'] ?? 'ExpenseFlow');
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $alt;
        // Automatic message: mail programs should not answer it with out-of-office replies.
        $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
        $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
        if (!empty($settings['reply_to'])) {
            $mail->addReplyTo($settings['reply_to'], $settings['from_name'] ?? 'ExpenseFlow');
        }
        $logoFile = __DIR__ . '/../assets/icons/icon-192.png';
        if ($logo && is_file($logoFile)) {
            $mail->addEmbeddedImage($logoFile, 'efLogo', 'expenseflow.png', 'base64', 'image/png');
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('ExpenseFlow email could not be sent: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * The shared look of ExpenseFlow emails (table layout and inline styles, because mail programs ignore normal CSS).
 * $lead is plain text, $code the 6-digit code (shown big), $note small print under the code.
 */
function ef_mail_html(string $heading, string $name, string $lead, string $code, string $note): string
{
    $h = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $digits = implode('</td><td style="width:6px"></td><td style="width:42px;height:54px;text-align:center;background:#ffffff;border:1.5px solid #cfe3e0;border-radius:12px;font-size:28px;font-weight:800;color:#0B2545;font-family:Segoe UI,Arial,sans-serif">', array_map($h, str_split($code)));
    $year = date('Y');
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"></head>'
        . '<body style="margin:0;padding:0;background:#eaf3f4;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#0B2545">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . $h($lead) . ' Your code: ' . $h($code) . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eaf3f4;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:480px;background:#ffffff;border-radius:22px;overflow:hidden;box-shadow:0 12px 34px rgba(11,37,69,.12)">'
        . '<tr><td style="background:#0B2545;background-image:linear-gradient(135deg,#0B2545,#137A7F);padding:26px 28px">'
        . '<table role="presentation" cellspacing="0" cellpadding="0"><tr><td><img src="cid:efLogo" width="38" height="38" alt="" style="display:block;border-radius:10px;background:#fff"></td>'
        . '<td style="padding-left:12px;font-size:20px;font-weight:800;color:#ffffff;letter-spacing:-.3px">ExpenseFlow</td></tr></table></td></tr>'
        . '<tr><td style="padding:30px 28px 8px">'
        . '<h1 style="margin:0 0 12px;font-size:22px;line-height:1.25;color:#0B2545">' . $h($heading) . '</h1>'
        . '<p style="margin:0 0 6px;font-size:15px;line-height:1.6;color:#33465f">Hi ' . $h($name) . ',</p>'
        . '<p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#33465f">' . $h($lead) . '</p>'
        . '<table role="presentation" align="center" cellspacing="0" cellpadding="0" style="margin:0 auto 20px"><tr><td style="padding:14px;background:#f2f9f8;border-radius:16px"><table role="presentation" cellspacing="0" cellpadding="0"><tr>'
        . '<td style="width:42px;height:54px;text-align:center;background:#ffffff;border:1.5px solid #cfe3e0;border-radius:12px;font-size:28px;font-weight:800;color:#0B2545;font-family:Segoe UI,Arial,sans-serif">' . $digits . '</td>'
        . '</tr></table></td></tr></table>'
        . '<p style="margin:0 0 6px;text-align:center;font-size:13px;color:#5b6b80">' . $h($note) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:18px 28px 26px"><div style="border-top:1px solid #e3ecef;padding-top:16px;font-size:12.5px;line-height:1.6;color:#6b7b90">'
        . 'Never share this code. ExpenseFlow will never ask you for your password or this code by phone, chat or email.<br>If you did not ask for it, you can safely ignore this email.'
        . '</div></td></tr></table>'
        . '<p style="max-width:480px;margin:16px auto 0;font-size:12px;line-height:1.6;color:#7a8a9e;text-align:center">This is an automatic message. Please do not reply to it.<br>&copy; ' . $year . ' ExpenseFlow</p>'
        . '</td></tr></table></body></html>';
}

/** Emails a 6-digit password reset code. Returns true when the mail was accepted by the SMTP server. */
function sendOtpMail(string $email, string $name, string $otp): bool
{
    return ef_send_mail(
        $email,
        $name,
        $otp . ' is your ExpenseFlow password reset code',
        ef_mail_html('Reset your password', $name, 'Use this code to choose a new password for your ExpenseFlow account.', $otp, 'The code is valid for 10 minutes.'),
        "Hi {$name},\n\nYour ExpenseFlow password reset code is: {$otp}\nIt is valid for 10 minutes.\n\nNever share this code. If you did not ask for it, ignore this email.\n\nThis is an automatic message, please do not reply.",
        true
    );
}

/** Emails the 6-digit code that proves a new sign-up owns the address. */
function sendVerifyMail(string $email, string $name, string $code): bool
{
    return ef_send_mail(
        $email,
        $name,
        $code . ' is your ExpenseFlow confirmation code',
        ef_mail_html('Confirm your email', $name, 'Enter this code on the sign-up page to finish creating your ExpenseFlow account.', $code, 'The code is valid for 10 minutes.'),
        "Hi {$name},\n\nYour ExpenseFlow confirmation code is: {$code}\nIt is valid for 10 minutes. Enter it on the sign-up page to finish creating your account.\n\nIf you did not sign up, ignore this email and nothing will be created.\n\nThis is an automatic message, please do not reply.",
        true
    );
}
