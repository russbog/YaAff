<?php

require_once __DIR__ . '/Entity.php';

/**
 * Landing page entity. Generic and data-driven.
 *
 * Common settings keys:
 *   type          string  "local" (uploaded ZIP folder) or "remote" (URL)
 *   path          string  local folder name (for local landings)
 *   url           string  remote URL with tokens (for remote landings)
 *   redirect_type string  http_302 | js | meta | iframe | ... (see Phase 2)
 *   protect       bool    enable bot protection/cloak on this landing
 *   note          string  free-form note
 */
class Landing extends Entity
{
    public const TABLE = 'landings';

    public function type(): string
    {
        return (string)$this->get('type', 'local');
    }

    public function isRemote(): bool
    {
        return $this->type() === 'remote';
    }

    public function path(): string
    {
        return (string)$this->get('path', '');
    }

    public function url(): string
    {
        return (string)$this->get('url', '');
    }

    /** Resolved target: remote URL for remote landings, folder name otherwise. */
    public function target(): string
    {
        return $this->isRemote() ? $this->url() : $this->path();
    }
}
