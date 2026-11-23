<?php

namespace App\Controllers\Admin;

use App\Services\Customer\PincodeLookup;
use Vayu\Http\Response;

/** Small JSON lookups used by admin forms. The browser never calls third-party APIs directly. */
class LookupController
{
    /** GET /admin/lookup/pincode/{code} → {ok, district, state, areas[]} or {ok:false, reason: invalid|not_found|unavailable}. */
    public function pincode(string $code): Response
    {
        try {
            $res = PincodeLookup::find($code);
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'reason' => 'unavailable'];
        }

        return json($res, $res['ok'] || ($res['reason'] ?? '') !== 'invalid' ? 200 : 422);
    }
}
