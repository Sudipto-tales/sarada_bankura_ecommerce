<?php

namespace App\Services\AdminAccess;

use App\Services\Mail\Mail;

final class EmailOtpChannel implements OtpChannel
{
    public function name(): string
    {
        return 'email';
    }

    public function canReach(array $user): bool
    {
        return !empty($user['email']);
    }

    public function send(array $user, string $code, string $purpose): void
    {
        $shop = (string) (business('name') ?: 'Maa Sarada');
        Mail::send((string) $user['email'], $code . ' is your ' . $shop . ' Admin code',
            "Hi {$user['name']},\n\nYour code to " . ($purpose === 'forgot_password' ? 'reset' : 'change')
            . " your {$shop} Admin password is:\n\n    {$code}\n\nIt is valid for 10 minutes. If you didn't ask for it, you can ignore this email — your password stays the same.");
    }
}
