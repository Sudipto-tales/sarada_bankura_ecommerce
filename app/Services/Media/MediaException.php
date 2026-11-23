<?php

namespace App\Services\Media;

/** A media operation was refused (bad type, too large, still in use…). The message is user-facing. */
class MediaException extends \RuntimeException
{
}
