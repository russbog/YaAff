<?php

/**
 * Base class for all first-class entities (sources, offers, networks,
 * landings, integrations, domains, rules, users, ...).
 *
 * Every entity table follows the same convention so a single generic
 * {@see EntityRepository} can manage it:
 *
 *   id          INTEGER PRIMARY KEY AUTOINCREMENT
 *   name        TEXT NOT NULL
 *   group_id    INTEGER NULL          -- optional grouping/folder
 *   settings    TEXT NOT NULL '{}'    -- schemaless JSON configuration bag
 *   created_at  INTEGER NOT NULL      -- unix epoch
 *   updated_at  INTEGER NOT NULL      -- unix epoch
 *
 * All entity-specific configuration lives inside the schemaless `settings`
 * JSON bag, so new fields never require a schema change and stay generic
 * (no hardcoding for a particular offer/network/source).
 */
class Entity
{
    public ?int $id = null;
    public string $name = '';
    public ?int $group_id = null;
    /** @var array<string,mixed> Decoded JSON configuration bag. */
    public array $settings = [];
    public ?int $created_at = null;
    public ?int $updated_at = null;

    /** Columns common to every entity table, in canonical order. */
    public const COLUMNS = ['id', 'name', 'group_id', 'settings', 'created_at', 'updated_at'];

    /**
     * @param array<string,mixed> $row Raw database row (settings as JSON text).
     */
    public function __construct(array $row = [])
    {
        if ($row !== []) {
            $this->hydrate($row);
        }
    }

    /**
     * Populate this entity from a raw database row.
     *
     * @param array<string,mixed> $row
     */
    public function hydrate(array $row): void
    {
        if (array_key_exists('id', $row)) {
            $this->id = $row['id'] === null ? null : (int)$row['id'];
        }
        if (array_key_exists('name', $row)) {
            $this->name = (string)$row['name'];
        }
        if (array_key_exists('group_id', $row)) {
            $this->group_id = $row['group_id'] === null ? null : (int)$row['group_id'];
        }
        if (array_key_exists('settings', $row)) {
            $value = $row['settings'];
            if (is_array($value)) {
                $this->settings = $value;
            } elseif (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                $this->settings = is_array($decoded) ? $decoded : [];
            } else {
                $this->settings = [];
            }
        }
        if (array_key_exists('created_at', $row)) {
            $this->created_at = $row['created_at'] === null ? null : (int)$row['created_at'];
        }
        if (array_key_exists('updated_at', $row)) {
            $this->updated_at = $row['updated_at'] === null ? null : (int)$row['updated_at'];
        }
    }

    /**
     * Persistable column => value map (settings encoded as JSON text).
     * The `id` column is omitted; it is managed by the database.
     *
     * @return array<string,mixed>
     */
    public function toRow(): array
    {
        return [
            'name' => $this->name,
            'group_id' => $this->group_id,
            'settings' => json_encode($this->settings === [] ? new stdClass() : $this->settings),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** Read a value from the schemaless settings bag with a default. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /** Write a value into the schemaless settings bag (fluent). */
    public function set(string $key, mixed $value): static
    {
        $this->settings[$key] = $value;
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'group_id' => $this->group_id,
            'settings' => $this->settings,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
