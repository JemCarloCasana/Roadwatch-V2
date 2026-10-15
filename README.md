# RoadWatch Dagupan (V2)

Community road hazard reporting for Dagupan City. XAMPP + PHP (mysqli) + MySQL/MariaDB.

## Fresh clone setup (no manual SQL import)

```bat
cd C:\xampp\htdocs\Roadwatch-V2
copy .env.example .env
C:\xampp\php\php.exe database\migrate.php
```

Then open `http://localhost/Roadwatch-V2/index.php`.

What `migrate.php` does:
1. Creates `DB_NAME` if missing (`CREATE DATABASE IF NOT EXISTS`).
2. Applies pending `database/migrations/*.sql` in order (tracked in `schema_migrations`).
3. Re-runs idempotent `database/seeds/*.sql` (`INSERT IGNORE` reference data + default accounts).
4. Verifies `reports.latitude/longitude` and `barangays.latitude/longitude` exist.

Web pages also auto-migrate on first load via `includes/db.php`, so opening
`index.php` on a fresh clone heals the schema automatically. Run `migrate.php`
manually when you want explicit output.

## Config

`.env` (git-ignored). See `.env.example`:

```ini
DB_HOST=localhost
DB_PORT=3306
DB_USER=root
DB_PASS=
DB_NAME=roadwatch_db
```

No `.env`? Defaults above are used (XAMPP out of the box).

## Default accounts (fresh DB only)

Seeded by `database/seeds/002_default_accounts.sql`. Change after first login.

| Email | Password | Role |
|---|---|---|
| superadmin@roadwatch.local | SuperAdmin123! | super_admin |
| admin@roadwatch.local | Admin12345 | admin |
| user@roadwatch.local | User12345 | user |

Security answer for all three: `RoadWatch`.

Existing databases keep their current users — seeds use `INSERT IGNORE` and never overwrite.

## Adding a schema change

1. Create `database/migrations/003_what_changed.sql` (one statement per `;` + newline, no procedures).
2. Use plain `ADD COLUMN` (no `IF NOT EXISTS`) — the migrator ignores errno 1060/1061/1068/1091/1022 on re-run.
3. Put lookup data in `database/seeds/` with `INSERT IGNORE`.
4. Test: drop a scratch DB, run `php database/migrate.php`, load `index.php`.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Cannot reach MySQL` | Start XAMPP MySQL; check `DB_HOST/DB_PORT/DB_USER/DB_PASS` in `.env`. |
| `Access denied` | `.env` creds wrong. XAMPP default is `root` + empty password. |
| `Unknown column 'reports.latitude'` | Legacy DB predating 002. Just reload any page (auto-migrate) or run `php database/migrate.php`. |
| Migration `FAIL:` line | Fix the named `.sql` file, re-run `migrate.php` (it resumes after the last applied version). |
