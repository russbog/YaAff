<?php

require_once __DIR__ . '/../db/drivers/DbDriver.php';
require_once __DIR__ . '/EntityRepository.php';
require_once __DIR__ . '/Network.php';
require_once __DIR__ . '/Source.php';
require_once __DIR__ . '/Offer.php';
require_once __DIR__ . '/Landing.php';
require_once __DIR__ . '/Integration.php';
require_once __DIR__ . '/Group.php';

/**
 * Factory for the generic {@see EntityRepository}, one per entity type, cached
 * per driver instance. New entity types in later phases register here by adding
 * a single line, keeping wiring centralized and avoiding duplicated setup.
 */
class Repositories
{
    /** @var array<int,array<string,EntityRepository>> spl_object_id => table => repo */
    private static array $cache = [];

    /** Map of admin-facing entity type => [table, entity class]. */
    public const TYPES = [
        'networks' => [Network::class, 'Networks'],
        'sources'  => [Source::class,  'Sources'],
        'offers'   => [Offer::class,   'Offers'],
        'landings' => [Landing::class, 'Landings'],
        'integrations' => [Integration::class, 'Integrations'],
        'groups'   => [Group::class,   'Groups'],
    ];

    /** @param class-string<Entity> $entityClass */
    public static function for(DbDriver $driver, string $table, string $entityClass = Entity::class): EntityRepository
    {
        $oid = spl_object_id($driver);
        if (!isset(self::$cache[$oid][$table])) {
            self::$cache[$oid][$table] = new EntityRepository($driver, $table, $entityClass);
        }
        return self::$cache[$oid][$table];
    }

    public static function networks(DbDriver $driver): EntityRepository
    {
        return self::for($driver, Network::TABLE, Network::class);
    }

    public static function sources(DbDriver $driver): EntityRepository
    {
        return self::for($driver, Source::TABLE, Source::class);
    }

    public static function offers(DbDriver $driver): EntityRepository
    {
        return self::for($driver, Offer::TABLE, Offer::class);
    }

    public static function landings(DbDriver $driver): EntityRepository
    {
        return self::for($driver, Landing::TABLE, Landing::class);
    }

    public static function integrations(DbDriver $driver): EntityRepository
    {
        return self::for($driver, Integration::TABLE, Integration::class);
    }

    public static function groups(DbDriver $driver): EntityRepository
    {
        return self::for($driver, Group::TABLE, Group::class);
    }

    /** Repository for a registered admin entity type, or null if unknown. */
    public static function byType(DbDriver $driver, string $type): ?EntityRepository
    {
        if (!isset(self::TYPES[$type])) {
            return null;
        }
        [$class] = self::TYPES[$type];
        return self::for($driver, $type, $class);
    }
}
