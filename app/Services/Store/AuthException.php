<?php

namespace App\Services\Store;

/** A storefront sign-in refusal whose message is safe to show (wrong password, locked, switched off). */
final class AuthException extends \RuntimeException
{
}
