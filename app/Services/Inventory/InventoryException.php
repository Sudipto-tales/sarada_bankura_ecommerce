<?php

namespace App\Services\Inventory;

/** A stock action that cannot be done; the message is safe to show to the admin. */
final class InventoryException extends \RuntimeException
{
}
