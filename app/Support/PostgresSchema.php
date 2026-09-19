<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

// Enum columns need raw DDL to widen on PostgreSQL, and the schema builder
// can't do it. Laravel's PostgresGrammar renders `$table->enum()` as
// `varchar(255) check ("col" in (...))` with an *unnamed* constraint, so
// adding a value means dropping and re-adding that CHECK by hand — and
// `$table->enum(...)->change()` is not an option, because compileChange()
// emits `alter column "x" type varchar(255) check (...)`, which isn't valid
// SQL. These helpers are the supported way to do it, used by the handful of
// migrations that widen or narrow an enum's value list.
//
// Postgres can't parameterise DDL, so values are interpolated through
// PDO::quote() rather than bound.
class PostgresSchema
{
    // Point a column at a new set of allowed values. Drops whatever CHECK
    // constraints the column currently carries, normalises the type/default/
    // nullability, then re-adds a single CHECK under a predictable name.
    public static function redefineEnum(
        string $table,
        string $column,
        array $values,
        ?string $default = null,
        bool $nullable = false,
        int $length = 255,
    ): void {
        self::relaxEnum($table, $column, $default, $nullable, $length);

        $quoted = implode(', ', array_map(self::quote(...), $values));

        DB::statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s IN (%s))',
            self::wrap($table),
            self::wrap("{$table}_{$column}_check"),
            self::wrap($column),
            $quoted,
        ));
    }

    // The first half of redefineEnum on its own: strip the CHECK and leave a
    // plain varchar behind. Migrations that widen -> backfill -> narrow need
    // this as a separate step, because the old and new labels have to coexist
    // while the UPDATEs run.
    public static function relaxEnum(
        string $table,
        string $column,
        ?string $default = null,
        bool $nullable = false,
        int $length = 255,
    ): void {
        self::dropCheckConstraints($table, $column);

        $wrappedTable = self::wrap($table);
        $wrappedColumn = self::wrap($column);

        // Drop the default before retyping: an existing default expression of
        // the old type would block the USING cast.
        DB::statement("ALTER TABLE {$wrappedTable} ALTER COLUMN {$wrappedColumn} DROP DEFAULT");

        DB::statement(sprintf(
            'ALTER TABLE %s ALTER COLUMN %s TYPE varchar(%d) USING %s::varchar(%d)',
            $wrappedTable,
            $wrappedColumn,
            $length,
            $wrappedColumn,
            $length,
        ));

        if ($default !== null) {
            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN %s SET DEFAULT %s',
                $wrappedTable,
                $wrappedColumn,
                self::quote($default),
            ));
        }

        DB::statement(sprintf(
            'ALTER TABLE %s ALTER COLUMN %s %s NOT NULL',
            $wrappedTable,
            $wrappedColumn,
            $nullable ? 'DROP' : 'SET',
        ));
    }

    // Every CHECK on the column, not just `{table}_{column}_check` — Postgres
    // appends _check1, _check2 and so on when a column gets re-constrained, so
    // a column that has already been through redefineEnum once under a
    // different name would otherwise keep its stale constraint.
    public static function dropCheckConstraints(string $table, string $column): void
    {
        $constraints = DB::select(
            <<<'SQL'
            SELECT c.conname
            FROM pg_constraint c
            JOIN pg_attribute a
              ON a.attrelid = c.conrelid
             AND a.attnum = ANY (c.conkey)
            WHERE c.conrelid = ?::regclass
              AND c.contype = 'c'
              AND a.attname = ?
            SQL,
            [$table, $column],
        );

        foreach ($constraints as $constraint) {
            DB::statement(sprintf(
                'ALTER TABLE %s DROP CONSTRAINT %s',
                self::wrap($table),
                self::wrap($constraint->conname),
            ));
        }
    }

    private static function wrap(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private static function quote(string $value): string
    {
        return DB::getPdo()->quote($value);
    }
}
