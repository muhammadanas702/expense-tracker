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

/** Sends one HTML email through the configured SMTP server. Returns true when the server accepted it. */
function ef_send_mail(string $email, string $name, string $subject, string $html, string $alt): bool
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

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('ExpenseFlow email could not be sent: ' . $mail->ErrorInfo);
        return false;
    }
}

/** Emails a 6-digit password reset code. Returns true when the mail was accepted by the SMTP server. */
function sendOtpMail(string $email, string $name, string $otp): bool
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    return ef_send_mail(
        $email,
        $name,
        'Your ExpenseFlow password reset code',
        "Hello {$safeName},<br><br>"
        . "Your code to reset your ExpenseFlow password is: <b style=\"font-size:20px;letter-spacing:3px\">{$safeOtp}</b><br><br>"
        . 'This code is valid for 10 minutes. If you did not ask for it, you can ignore this email.<br><br>'
        . '– ExpenseFlow Team',
        "Your ExpenseFlow password reset code is: {$otp}\nIt is valid for 10 minutes. If you did not ask for it, ignore this email."
    );
}

/** Emails the 6-digit code that proves a new sign-up owns the address. */
function sendVerifyMail(string $email, string $name, string $code): bool
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    return ef_send_mail(
        $email,
        $name,
        'Confirm your email for ExpenseFlow',
        "Hello {$safeName},<br><br>"
        . "Your ExpenseFlow confirmation code is: <b style=\"font-size:20px;letter-spacing:3px\">{$safeCode}</b><br><br>"
        . 'Enter it on the sign-up page to finish creating your account. It is valid for 10 minutes. If you did not sign up, you can ignore this email and nothing will be created.<br><br>'
        . '– ExpenseFlow Team',
        "Your ExpenseFlow confirmation code is: {$code}\nIt is valid for 10 minutes. If you did not sign up, ignore this email."
    );
}
