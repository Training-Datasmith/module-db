# Architecture: module-db (Codeception)

## Purpose

The Codeception Db module. Provides database-related test helpers: seeding tables from SQL dump files, asserting database state (`seeInDatabase`, `dontSeeInDatabase`), and cleaning up between tests.

## Directory Structure

```
src/Codeception/
  Module/
    Db.php                — Main Codeception module: seeInDatabase, haveInDatabase, cleanupDatabase
  Lib/
    Driver/
      Db.php              — Abstract PDO-based database driver
      My_Sql.php          — MySQL/MariaDB driver
      Postgre_Sql.php     — PostgreSQL driver
      Sqlite.php          — SQLite driver
      Sql_Srv.php         — Microsoft SQL Server driver
      Oci.php             — Oracle driver
    Db_Populator.php      — Executes SQL dump files against the database
    Interfaces/Db.php     — Interface for database assertions
tests/
  unit/                   — Unit tests for each driver
```

## Key Design Decisions

- **Driver pattern**: Each database backend is a `Driver` subclass with backend-specific SQL quoting, table truncation, and data insertion
- **SQL dump seeding**: `Db_Populator` reads `.sql` files and executes them statement by statement, with support for multi-statement files and delimiter overrides
- **Cleanup modes**: `cleanup: true` wraps each test in a transaction (rolled back after), or truncates tables listed in `populate` after each test

## Extension Points

- Implement the `Db` driver interface to add a new database backend
- Configure `populate_before: true` to reseed the database before each test from a dump file
