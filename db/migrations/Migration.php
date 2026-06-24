<?php

require_once __DIR__ . '/../drivers/DbDriver.php';

/**
 * A single, reversible schema change.
 *
 * Migration files live in db/migrations/ and are named
 * `NNN_short_description.php` (e.g. 001_create_networks.php). Each file
 * returns an instance of an anonymous class implementing this interface:
 *
 *   <?php
 *   return new class implements Migration {
 *       public function up(DbDriver $db): void {
 *           $db->exec("CREATE TABLE IF NOT EXISTS networks (...)");
 *       }
 *       public function down(DbDriver $db): void {
 *           $db->exec("DROP TABLE IF EXISTS networks");
 *       }
 *   };
 *
 * The numeric prefix is the version; {@see Migrator} applies pending versions
 * in ascending order and records them so each runs exactly once.
 */
interface Migration
{
    /** Apply the schema change. Must be idempotent (use IF NOT EXISTS, etc.). */
    public function up(DbDriver $driver): void;

    /** Revert the schema change. */
    public function down(DbDriver $driver): void;
}
