# Admin global search

The search box in the admin topbar searches the whole admin, not only products.

## What it finds

| Group | Matches on | Opens |
|---|---|---|
| Pages | page name + keywords ("low stock", "new order", "upi", "apk") | the page |
| Products | name, SKU, variant SKU | product page |
| Orders | order number, customer name, mobile | order page |
| Customers | name, company, email, mobile (merged customers skipped) | customer page |
| Enquiries | ENQ number, name, message, mobile — only with `enquiries.view` | enquiries list with that row opened |
| Returns | request number, order number | return page |

Mobile numbers match with spaces, `+91` or a leading `0` (4+ digits needed). Pages behind a permission
(Staff, Android app, Enquiries) are only suggested to staff who have it.

## How it works

- `App\Services\Admin\GlobalSearch::run($q, $per)` — single place for the queries; at least 2 characters.
  `%` is dropped from the query; `_` is left in (a 1-char wildcard still matches SKUs like `GAL_9`). No ESCAPE clause, so it runs on SQLite and MySQL.
- `GET /admin/search/suggest?q=` → JSON, 5 per group (throttled 120/min). Used by the dropdown.
- `GET /admin/search?q=` → full results page, 20 per group, each group links to its own list filtered by `q`.
- Dropdown JS lives in `resources/views/admin/partials/footer.php`; styles are the `.gs-*` rules in `resources/css/app.css`.

## Using it

- `Ctrl/⌘ K` or `/` focuses the box.
- Before typing: recent searches (kept in this browser only) and quick actions.
- `↑` `↓` to move, `Enter` opens the highlighted result; `Enter` with nothing highlighted opens the full results page. `Esc` closes.

Tests: `tests/admin_search_test.php`.
