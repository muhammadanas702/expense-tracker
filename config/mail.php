<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendResetMail($email, $token)
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
        return false;
    }

    require_once __DIR__ . '/app.php';
    $resetLink = rtrim($base_url, '/') . '/auth/reset-password.php?token=' . rawurlencode((string) $token);
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $settings['host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $settings['username'];
        $mail->Password = $settings['password'];
        $mail->SMTPSecure = $settings['encryption'] ?? 'tls';
        $mail->Port = (int) ($settings['port'] ?? 587);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        $fromEmail = $settings['from_email'] ?? $settings['username'];
        $mail->setFrom($fromEmail, $settings['from_name'] ?? 'ExpenseFlow');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'ExpenseFlow Password Reset';
        $mail->Body = "
            <h2>Password Reset</h2>
            <p>Use the link below to reset your password:</p>
            <p><a href=\"{$resetLink}\">Reset Password</a></p>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('ExpenseFlow password reset email could not be sent.');
        return false;
    }
}
