# Phase 14: exports, invoice PDFs and search

Project finance can now leave the app, invoices can be sent to a customer as a file, and people can find a record by typing its name. Before this, only accounting reports could be exported, and Ctrl+K only jumped to sections.

## What was built

| What            | Where                                    | Who                        |
| --------------- | ---------------------------------------- | -------------------------- |
| Spending as CSV | Project → Spending → Download CSV        | `projects.finance.view`    |
| Budget as CSV   | Project → Budget → Download CSV          | `projects.finance.view`    |
| Grants as CSV   | Funding → Grants → Download CSV          | `grants.view`              |
| Invoice as PDF  | Accounting → an invoice → Download PDF   | `finance.receivables.view` |
| Search          | Ctrl+K, then "Search for…", or `/search` | Any member, by permission  |

Search covers projects, tasks and people (`projects.view`), spending (`projects.finance.view`), and grants and funders (`grants.view`).

## Rules

- A search group only runs with the permission of the page its results link to. Someone without finance access doesn't learn that spending exists.
- People are matched on name and title only, never costs.
- `%` and `_` in a search are ordinary text.
- CSV text a spreadsheet would run as a formula is kept as text.
- The budget export is the approved version's lines, or the working version's until one is approved.
- Grant amounts are written in each grant's own currency, with a currency column.
- A voided invoice says so on its PDF. The PDF library doesn't fetch remote files.

## Files

- `app/Http/Controllers/Work/SearchController.php`, `resources/js/pages/portal/search.tsx`
- `resources/views/pdf/invoice.blade.php`, `app/Http/Controllers/Accounting/InvoiceController.php`
- `app/Accounting/Support/CsvExport.php`, `ExpenseController`, `BudgetController`, `GrantController`
- `tests/Feature/ExportsAndSearchTest.php`
- New dependency: `barryvdh/laravel-dompdf` (`^3.1`) and the five packages it needs.

## Tested

- Automated, on SQLite: each download's permission and contents, formula text, another organization's records, the PDF's signature and file name, search by permission, across organizations, and wildcards.
- The whole suite, PHPStan, Pint and `tsc`.

## Not tested

- **Real browser:** the Download links, the search page and the Ctrl+K entry. `npm run build` currently fails (see the Phase 12 report).
- **PDF appearance:** tests check that a PDF is produced, but nobody has looked at one.
- **MariaDB:** search has only run on SQLite. Its `LOWER(...) LIKE ... ESCAPE '!'` is standard SQL, but check it there.

## Known limits

- **Invoices are downloaded, not emailed.** A customer is still only a name on the invoice, with no address or email, so neither appears on the PDF. A customer record is the next step for this.
- The PDF shows line amounts in the books' currency, with the total in the invoice's currency beneath. An invoice in another currency should show its lines in that currency too.
- The PDF has no logo, or the organization's address or tax number, because the app doesn't hold them.
- Search matches text anywhere in a field, row by row, with no index: fine at this size, slow on very large tables. It shows the first 20 per group, unranked.
- Search results for spending, people and funders open their list page, not the record.
- There is no Excel export or PDF report, and CSV doesn't cover time entries.
