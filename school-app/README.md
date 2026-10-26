# School

Miles Education: one school app for **primary schools, institutes, colleges and universities**.
Laravel 13 + Inertia + React, connected to the platform through
[`miles/platform-client`](../packages/platform-client/README.md). Served at `{workspace}/school`.

## Categories

Each workspace is set up once as one kind of school (`App\Enums\SchoolCategory`). The category
decides the words, the structure, numbering and grading; everything else is shared.

|                   | Primary school          | Institute                     | College                      | University                       |
| ----------------- | ----------------------- | ----------------------------- | ---------------------------- | -------------------------------- |
| Year split into   | 3 terms                 | 2 semesters                   | 2 semesters                  | 2 semesters                      |
| Structure         | Classes (Primary 1–8)   | Departments → programmes      | Departments → programmes     | Faculties → departments → programmes |
| Taught            | Subjects                | Modules                       | Courses (credit hours)       | Courses (credit hours)           |
| Students          | Pupils, `ADM-00001`     | `REG-00001`                   | `REG-00001`                  | `REG-00001`                      |
| Registers         | One a day per class     | Per module per day            | Per course per day           | Per course per day               |
| Grading           | A–E, total, average, position in class | Distinction / Credit / Pass / Fail | A–F, grade points, GPA | A–F, grade points, GPA, CGPA |
| Starts with       | Primary 1–8 and six common subjects (optional) | — | — | — |

The category can change until the first student is enrolled.

## Features

- **Calendar**: academic years split evenly into terms or semesters (dates editable), one of
  them current. Each term has assessments (mid-term and end of term; coursework and final exam)
  whose weights add up to 100.
- **Structure**: faculties and departments, programmes (award, length; a class for each year of
  study), classes with a class teacher and places, subjects/modules/courses, and who teaches
  each one to each class.
- **Admissions**: applications (`APP-00001`) admitted into a class (they become students) or
  declined. Students can also be enrolled directly.
- **Students**: details, guardian or next of kin, status (enrolled, suspended, withdrawn,
  graduated); promotion of a class to the next one, or graduation of the final year.
- **Attendance**: registers of present / late / absent / excused; taking one again replaces it.
- **Marks and results**: marks out of 100 per assessment; a subject's term mark is the weighted
  mean of its assessments (flagged incomplete while some are unmarked). Class broadsheet and a
  printable report card per student, with attendance.
- **Fees**: fee items per term (for every class or one class), billed once per student per term
  (`INV-00001`), paid in parts (`RCT-00001`), unpaid invoices voided. Finance hears of each:
  `finance.invoice.issued`, `finance.payment.received`, `finance.invoice.voided`.
- **Staff** (`STF-00001`): HR hears of each one added (`hr.employee.joined`), organizational
  facts only.

State changes live in `app/Actions` (`Calendar`, `Enrolment`, `Attendance`, `Marks`, `Fees`,
`Staffing`), results in `app/Support/Results.php`. Amounts are integers in the currency's minor
unit (`App\Support\Money`). Everything is organization-wide (no branches).

## Roles

Defined in `platform/catalog.json`: Head / Principal (everything), Registrar (admissions,
students, structure, results), Teacher / Lecturer (registers, marks, results), Bursar (fees).
Voiding invoices and setting the school up are their own permissions.

Teachers take registers and enter marks only for what they teach (`App\Support\Teaching`): they
are matched to a member of staff by email, then get the whole class they are class teacher of
and the subjects assigned to them in other classes. Members who manage academics do it for
every class.

## Develop

```bash
createdb school       # owner: your local DB user
composer setup        # install, .env, key, migrate, build
composer dev          # php artisan serve on :9100, queue, Vite
composer ci:check     # format, lint, types, Pint, PHPStan, Pest
```

Register with the local platform:
`cd ../central-app && php artisan platform:local-product school-app --url=http://127.0.0.1:9100 --entitle=<workspace-slug>`.
