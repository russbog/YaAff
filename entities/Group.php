<?php

require_once __DIR__ . '/Entity.php';

/**
 * Grouping/folder for first-class entities. The entity type a group applies to
 * is stored in the settings bag under "entity_type" (e.g. network, source,
 * offer, landing, campaign), so one table serves every entity type.
 */
class Group extends Entity
{
    public const TABLE = 'groups';

    public function entityType(): string
    {
        return (string)$this->get('entity_type', '');
    }
}
