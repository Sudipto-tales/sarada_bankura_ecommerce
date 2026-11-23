<?php

namespace App\Services\Order;

/** A business-rule refusal with a message safe to show the admin (wrong step, bad OTP, …). */
class OrderException extends \RuntimeException
{
}
