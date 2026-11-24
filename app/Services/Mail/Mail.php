<?php

namespace App\Services\Mail;

/**
 * Plain-text email over SMTP (MAIL_* in .env) using PHPMailer from composer.
 * The legacy core/Mailer.php needs the old bootstrap (__BASEDIR__), so new code sends through here.
 * Throws \RuntimeException with a message safe to show the admin.
 */
final class Mail
{
    public static function configured(): bool
    {
        return trim((string) env('MAIL_HOST', '')) !== '' && trim((string) env('MAIL_USERNAME', '')) !== '';
    }

    public static function send(string $to, string $subject, string $body): void
    {
        if (!self::configured()) {
            throw new \RuntimeException('Email isn’t set up yet (MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD in .env).');
        }
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class) && is_file($autoload)) {
            require_once $autoload;
        }
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            throw new \RuntimeException('PHPMailer is not installed on the server (run composer install).');
        }
        $m = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $m->isSMTP();
            $m->Host = (string) env('MAIL_HOST');
            $m->Port = (int) env('MAIL_PORT', 587);
            $m->SMTPAuth = true;
            $m->Username = (string) env('MAIL_USERNAME');
            $m->Password = (string) env('MAIL_PASSWORD', '');
            $enc = strtolower((string) env('MAIL_ENCRYPTION', 'tls'));
            $m->SMTPSecure = $enc === 'ssl' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $m->CharSet = 'UTF-8';
            $m->Timeout = 15;
            $m->setFrom((string) (env('MAIL_FROM_ADDRESS') ?: env('MAIL_USERNAME')), (string) (business('name') ?: env('MAIL_FROM_NAME', '')));
            if (filter_var((string) business('email'), FILTER_VALIDATE_EMAIL)) {
                $m->addReplyTo((string) business('email'));
            }
            $m->addAddress($to);
            $m->isHTML(false);
            $m->Subject = $subject;
            $m->Body = $body;
            $m->send();
        } catch (\Throwable $e) {
            throw new \RuntimeException('SMTP error: ' . ($m->ErrorInfo ?: $e->getMessage()));
        }
    }
}
