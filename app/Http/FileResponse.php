<?php

namespace App\Http;

use Vayu\Http\Response;

/**
 * Streams a file from disk instead of holding it in memory (Vayu's Response buffers its body).
 * Used for APK downloads (docs/mobile-app.md phase 3). getContent() stays empty on purpose.
 */
class FileResponse extends Response
{
    public function __construct(private string $path, string $downloadName, string $type = 'application/octet-stream', array $headers = [])
    {
        parent::__construct('', 200, $headers + [
            'Content-Type' => $type,
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => 'attachment; filename="' . str_replace(['"', '\\', "\r", "\n"], '', $downloadName) . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($this->path);
    }
}
