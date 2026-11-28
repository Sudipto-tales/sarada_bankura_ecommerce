<?php

namespace App\Controllers\Admin;

use App\Services\Admin\GlobalSearch;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** Topbar global search: live suggestions (JSON) and the full results page. */
class SearchController
{
    /** GET /admin/search/suggest?q= → {q, groups{key:{label,icon,more,items[]}}, total} */
    public function suggest(Request $request): Response
    {
        return json(GlobalSearch::run((string) $request->query('q', ''), 5));
    }

    /** GET /admin/search?q= */
    public function index(Request $request): Response
    {
        $res = GlobalSearch::run((string) $request->query('q', ''), 20);

        return view('admin.search.index', ['active' => 'search', 'res' => $res, 'quick' => GlobalSearch::quickLinks()]);
    }
}
