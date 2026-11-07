# CCSync API

Laravel 12 REST API serving CCSync Admin and ClassFlow from `/api/v1`.

Local URL: http://localhost:8000

## Current status

Phase 2 schema and Sanctum SPA authentication are implemented. The populated
`ccsync_db` database must be preserved. Its 22 migrations are recorded in batch 1; the
live schema has 27 tables and four users. A read-only `php artisan migrate:status` was
verified on 2026-11-04.

Implemented API routes:

| Method | Endpoint | Auth | Purpose |
| --- | --- | --- | --- |
| `GET` | `/api/v1/health` | No | API availability and version |
| `POST` | `/api/v1/auth/login` | Public, throttled, CSRF protected for stateful SPA requests | Create a session |
| `GET` | `/api/v1/auth/me` | `auth:sanctum` | Return current user and profile |
| `POST` | `/api/v1/auth/logout` | `auth:sanctum`, CSRF protected for stateful SPA requests | Invalidate the session |
| `GET` | `/api/v1/users` | Administrator or Department Personnel | Search/filter/paginate permitted accounts |
| `GET` | `/api/v1/users/options` | Administrator or Department Personnel | Return program options and Administrator role choices |
| `GET` | `/api/v1/users/{user}` | Administrator or Department Personnel, policy scoped | View a permitted account |
| `POST` | `/api/v1/users` | Administrator | Create an account and any required Student/Professor profile |
| `PATCH` | `/api/v1/users/{user}` | Administrator or Department Personnel, policy scoped | Update account/profile fields; role is immutable |
| `PATCH` | `/api/v1/users/{user}/status` | Administrator or Department Personnel, policy scoped | Activate/deactivate permitted accounts |
| `GET` | `/api/v1/academic/options` | Administrator or Department Personnel | Related-record choices for structure forms |
| `GET`, `POST` | `/api/v1/academic/programs` | Administrator or Department Personnel | Search/filter/paginate and manage programs |
| `GET`, `PATCH` | `/api/v1/academic/programs/{program}` | Administrator or Department Personnel | Read or update a program |
| `GET`, `POST` | `/api/v1/academic/terms` | Administrator or Department Personnel | Search/filter/paginate and manage academic terms |
| `GET`, `PATCH` | `/api/v1/academic/terms/{term}` | Administrator or Department Personnel | Read or update a term |
| `GET`, `POST` | `/api/v1/academic/year-levels` | Administrator or Department Personnel | Search/paginate and manage year levels |
| `GET`, `PATCH` | `/api/v1/academic/year-levels/{year_level}` | Administrator or Department Personnel | Read or update a year level |
| `GET`, `POST` | `/api/v1/academic/subjects` | Administrator or Department Personnel | Search/filter/paginate and manage subjects |
| `GET`, `PATCH` | `/api/v1/academic/subjects/{subject}` | Administrator or Department Personnel | Read or update a subject |
| `GET`, `POST` | `/api/v1/academic/sections` | Administrator or Department Personnel | Search/filter/paginate and manage sections |
| `GET`, `PATCH` | `/api/v1/academic/sections/{section}` | Administrator or Department Personnel | Read or update a section |

Stage 1 user-account authorization and CRUD endpoints and Stage 2 academic structure
endpoints for programs, terms, year levels, subjects, and sections are implemented.
Stages 3–5 Student, Faculty, and Scheduling APIs are implemented. Stage 6 Attendance
provides session and record APIs, program-scoped Department Personnel monitoring,
Professor session entry, and Student self-only history. The attendance migration is
present but has not been applied to `ccsync_db`. Announcements, faculty attendance,
activities, and other later workflows remain deferred.

Stage 2 uses the existing schema without adding migrations. Academic endpoints are
available to Administrator and Department Personnel only. They validate unique codes
and term definitions, date ranges, related section records, and current-term transitions;
term switching is transactional. Records are not hard-deleted.

Administrators create accounts and select one of the four roles at creation. Department
Personnel may edit and activate/deactivate Professor and Student accounts only; they
cannot create accounts, manage Administrator/Department Personnel accounts, or assign
roles. Student and Professor accounts are created with profiles in the same database
transaction. The user role cannot be changed later, preserving profile and academic
record integrity. New Student/Professor accounts may only be assigned to active
programs; existing assignments to inactive programs are retained during profile edits.
Passwords are hashed through the existing `User` model cast.

The pending migration `2026_11_04_100018_preserve_schedule_conflict_history` changes
schedule-conflict foreign keys to restrict schedule deletion. It has not been applied to
`ccsync_db`. The Stage 6 migration `2026_11_05_160000_create_attendance_tables` is also
pending on that database.

## Requirements and setup

- PHP 8.2+
- Composer 2
- Node.js 20+ only for sibling frontend work
- The configured MySQL-compatible database server

Install dependencies and run the API without overwriting an existing `.env`:

```powershell
composer install
php artisan serve
```

The existing database is configured on port `3307`. Do not change the local connection
settings or copy `.env.example` over an existing `.env` unless specifically authorized.
Never reset `ccsync_db`, run migrations against it, or run seeders against it.

## Roles and seed data

The current data has one user for each role: administrator, department personnel,
professor, and student. Do not print or disclose user emails or credentials when auditing
the populated database.

`RoleSeeder` and `ReferenceDataSeeder` upsert role/reference rows. The reference seeder
also marks its sample term current and clears the current flag on other terms.
`DevelopmentAccountSeeder` upserts four development users and profiles, resetting their
passwords and statuses from `CCSYNC_SEED_PASSWORD`. These seeders are not read-only and
must not be run against `ccsync_db`.

## Safe verification

Read-only checks:

```powershell
php artisan route:list --path=api
php artisan migrate:status
```

The test suite uses `RefreshDatabase`; it rebuilds its configured test schema. Confirm
that `DB_DATABASE` resolves to the separate, disposable `ccsync_test` database before
running it. The verified test database contained only its migration ledger before the
current test run.

```powershell
php artisan test
```

Successful responses use `{ success, message, data }`; errors use
`{ success, message, errors? }`. Public registration is not available.

See [setup](../docs/setup.md), [authentication](../docs/authentication.md),
[architecture](../docs/architecture.md), and
[database documentation](../database/documentation/README.md).
