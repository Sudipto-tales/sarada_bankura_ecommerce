<?php

namespace App\Services\Payment;

/** A payment action that can't go ahead; the message is safe to show (admin or customer). */
final class PaymentException extends \RuntimeException
{
}
