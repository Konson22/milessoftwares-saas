# Phase 15: donor portal

Someone invited to act for a funder signs in on the organization's subdomain and
sees what each grant shares with them. They can accept a submitted report, ask
for a revision, download report documents, and write to the organization.

## Departure from the roadmap

`docs/platform-assessment-and-roadmap.md` §5.2 assumed donors would have their
own organization, with access reaching across organizations. This phase does not
do that. A contact is a member of the NGO's organization with no roles, confined
to the portal. Reasons: no relaxing of the tenant scope, which fails closed
everywhere today, and donors don't have to set up anything of their own.

The cost: a donor who funds two NGOs has two sign-ins to two subdomains, and no
combined view. If that matters later, it needs its own design.

## What was built

- **Contacts:** `funder_contacts`, and `invitations.funder_id`. Staff invite by
  email from the grant page, using the existing invitation link. Someone already
  in the organization is refused. A contact never enters the people directory.
- **Confinement:** `ConfineFunderContacts` allows only `donor.*` and
  `notifications.*` for a contact holding no permissions. It is an allow list, so
  a page added later stays closed to them. Account settings live outside the
  organization, so they can still change their password.
- **Sharing:** `grants.portal_enabled`, `portal_budget`, `portal_spending` and
  `portal_progress`, all off by default.
- **Reading:** only `App\Support\DonorProjection`, which returns named fields,
  never whole records.
- **Writing:** `App\Actions\DonorPortal`, every change audited.
- **Report documents:** `grant_report_documents`, on the private disk. Downloads
  by a contact are audited.
- **Conversation:** `grant_messages`, never edited or deleted.
- **Alerts:** a new `message` kind. Staff messages and submitted reports alert
  contacts; funder messages and decisions alert holders of `grants.manage`.
- **Screens:** `portal/donor/index`, `portal/donor/grant`, and
  `DonorPortalPanel` on the grant page. The members page labels donor contacts.

## What a contact sees

| Part             | When                                    | Fields                                                                                |
| ---------------- | --------------------------------------- | ------------------------------------------------------------------------------------- |
| Grant            | shared, and active or closed            | reference, title, status, currency, amount, received, restricted, dates               |
| Amendments       | same                                    | number, effective date, amount after, end after                                       |
| Payments         | same                                    | due, amount, received date                                                            |
| Reports          | same                                    | title, kind, due, status, submitted, accepted                                         |
| Report documents | report submitted, accepted or sent back | name, size, download                                                                  |
| Funded projects  | same as grant                           | code, name, currency, what this grant gives                                           |
| Progress         | `portal_progress`                       | status, percent, milestones                                                           |
| Budget           | `portal_budget`, once approved          | approved amount by category                                                           |
| Spending         | `portal_spending`                       | paid lines charged to this grant: date, category, description, amount; approved total |

Never returned: notes on reports and payments, other funders' grants and
allocations, suppliers, requesters, approvers, payment references, receipts,
journals, staff lists, tasks, risks, and grants in the pipeline.

## Tested

`tests/Feature/DonorPortalTest.php`, all through HTTP, with contacts made through
the real invitation link:

- Every parameterless GET page behind the middleware is refused to a contact,
  including those open to any member (`my-work`, `approvals`, `search`,
  `accounting`). The test requires more than 25 such pages, so it can't pass empty.
- Nothing is visible until shared, and sharing can be taken back. Pipeline grants
  stay hidden even when switched on.
- Another funder's grant gives 404 for reads, messages and decisions. Another
  organization gives 404. Staff and owners who aren't contacts get 404.
- Each of the three switches. Spending excludes other grants' and unfunded
  lines, and the response doesn't contain the supplier, payment reference, internal
  notes, another donor's name or staff emails.
- A draft budget stays hidden until approved.
- Documents on a report still due are hidden. The same document can't be reached
  through another shared grant. Staff can't remove a document after submission.
- Decisions: only on a submitted report; a revision needs a reason, which is
  posted to the conversation; acceptance is audited as the contact.
- Messages both ways, with alerts to the other side.
- Removing a contact ends access. Someone later given a role keeps their
  membership when they stop acting for the funder.
- `grants.manage` is required for the staff side.
- Migration up, down and up again on SQLite.

## Checked after the build was fixed

- **MariaDB 10.4:** the full suite passes. It found one bad query in a test, which
  SQLite had let through. It ran in MariaDB's empty `test` database, which was
  emptied afterwards, because the app's user can't create databases.
- **In a real browser (headless Chrome, on seeded demo data):** as a donor: home
  goes to the portal, the menu shows only the portal, the grant page renders with
  all three switches, sending a message works, and a staff page gives 403. As an
  owner: the panel renders, a switch saves, a document uploads, an invitation shows
  as invited, and a reply reaches the donor. No JavaScript errors. Nothing overflows
  sideways at 1280 or 390 pixels wide.

## Not tested

- **Real email.** Mail was logged, not delivered.
- **Accepting or sending back a report in the browser.** Those forms are covered
  over HTTP in the tests only.
- **The Chrome extension.** It wasn't connected, so this was a scripted headless
  run, not someone clicking through by hand.

## Limits

- Indicators and results are not shown: they wait for MEAL.
- The budget switch shows the whole project's approved budget, including what
  other donors pay for. That's why it's a separate switch, and the panel says so.
- Reports are attached and submitted by staff; a funder can't upload anything.
- A contact is tied to one funder per organization. All grants of that funder
  are open to them once shared, with no finer division.
- The `Audit` trail records downloads by contacts, not by staff.
- Message and report email alerts go through the existing alert preferences, and
  there's no separate donor digest.
