<?php

namespace App\Console\Commands;

use App\Services\AppReleases;
use Vayu\Console\Command;
use Vayu\Database\DB;
use Vayu\Http\UploadedFile;
use Vayu\Validation\ValidationException;

/**
 * php vayu app:release --apk=<file> --name=1.2.0 --code=10200 [--min=0] [--notes-file=<file>] [--draft]
 *
 * Adds a built APK to Admin → Settings → Android app and publishes it on /app (docs/mobile-app.md,
 * Release workflow). Run by .github/workflows/android.yml after a tag build. The APK file is moved
 * into storage. Running it again for the same code publishes that release if it is still a draft.
 */
class AppReleaseCommand extends Command
{
    protected static string $name = 'app:release';

    protected static string $description = 'Add a built Android APK as a release and publish it on /app';

    public function handle(array $args): int
    {
        $opt = ['min' => '0'];
        foreach ($args as $a) {
            if (preg_match('/^--(apk|name|code|min|notes-file)=(.*)$/s', $a, $m)) {
                $opt[$m[1]] = trim($m[2], " \"'");
            } elseif ($a === '--draft') {
                $opt['draft'] = true;
            }
        }
        $apk = (string) ($opt['apk'] ?? '');
        $code = (string) ($opt['code'] ?? '');
        if (!str_ends_with(strtolower($apk), '.apk') || !is_file($apk) || ($opt['name'] ?? '') === '' || !ctype_digit($code)) {
            $this->error('Usage: php vayu app:release --apk=<file.apk> --name=1.2.0 --code=10200 [--min=0] [--notes-file=<file>] [--draft]');

            return 1;
        }

        $existing = DB::selectOne('SELECT id, status FROM app_releases WHERE version_code = ?', [(int) $code]);
        if ($existing !== null) {
            @unlink($apk);
            if ($existing['status'] === 'published' || !empty($opt['draft'])) {
                $this->info("Code {$code} is already uploaded ({$existing['status']}). Nothing to do.");

                return 0;
            }

            return $this->publish((int) $existing['id'], $opt['name']);
        }

        $notes = isset($opt['notes-file']) && is_file($opt['notes-file']) ? (string) file_get_contents($opt['notes-file']) : '';
        try {
            $id = AppReleases::upload(
                new UploadedFile(basename($apk), 'application/vnd.android.package-archive', $apk, UPLOAD_ERR_OK, (int) filesize($apk)),
                ['version_name' => $opt['name'], 'version_code' => $code, 'min_supported_code' => $opt['min'], 'changelog' => mb_substr(trim($notes), 0, 2000)],
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $msgs) {
                $this->error($field . ': ' . implode(' ', (array) $msgs));
            }

            return 1;
        }
        $this->info("Uploaded {$opt['name']} (code {$code}) as release #{$id}.");

        return !empty($opt['draft']) ? 0 : $this->publish($id, $opt['name']);
    }

    private function publish(int $id, string $name): int
    {
        try {
            AppReleases::publish($id);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $msgs) {
                $this->error(implode(' ', (array) $msgs));
            }

            return 1;
        }
        $this->info("Published {$name} on /app.");

        return 0;
    }
}
