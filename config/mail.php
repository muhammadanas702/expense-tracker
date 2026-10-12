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
        ];
    }

    if (empty($settings['username']) || empty($settings['password'])) {
        error_log('ExpenseFlow mail settings are missing.');
        return null;
    }

    return $settings;
}

/** Emails a 6-digit password reset code. Returns true when the mail was accepted by the SMTP server. */
function sendOtpMail(string $email, string $name, string $otp): bool
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
        $mail->Subject = 'Your ExpenseFlow password reset code';

        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $mail->Body = "Hello {$safeName},<br><br>"
            . "Your code to reset your ExpenseFlow password is: <b style=\"font-size:20px;letter-spacing:3px\">{$safeOtp}</b><br><br>"
            . 'This code is valid for 10 minutes. If you did not ask for it, you can ignore this email.<br><br>'
            . '– ExpenseFlow Team';
        $mail->AltBody = "Your ExpenseFlow password reset code is: {$otp}\nIt is valid for 10 minutes. If you did not ask for it, ignore this email.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('ExpenseFlow reset email could not be sent: ' . $mail->ErrorInfo);
        return false;
    }
}
