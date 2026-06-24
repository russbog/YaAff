<?php

require_once __DIR__ . '/Entity.php';

/**
 * Notification channel (Phase 9). Generic and data-driven: one entity models a
 * Telegram bot, an outgoing webhook or an email recipient. The channel type
 * picks the transport; all message text is a {token} template resolved at send
 * time, so alerts are configuration, not code.
 *
 * Common settings keys:
 *   type         string  telegram|webhook|email (default webhook)
 *   enabled      bool    master on/off switch (default true)
 *   events       array   event names this channel listens to; empty = all
 *   message      string  message template (tokens allowed)
 * Telegram:
 *   bot_token    string  Telegram bot token (secret; never logged)
 *   chat_id      string  target chat id
 *   parse_mode   string  HTML|Markdown|'' (default HTML)
 * Webhook:
 *   url          string  endpoint URL (tokens allowed)
 *   method       string  GET|POST (default POST)
 *   headers      array   header name => value template
 *   body         string  raw body template; falls back to {message}
 *   content_type string  default application/json
 * Email:
 *   to           string  recipient(s), comma-separated
 *   from         string  From address
 *   subject      string  subject template
 */
class Channel extends Entity
{
    public const TABLE = 'channels';

    public function type(): string
    {
        $t = strtolower((string)$this->get('type', 'webhook'));
        return in_array($t, ['telegram', 'webhook', 'email'], true) ? $t : 'webhook';
    }

    public function enabled(): bool
    {
        return (bool)$this->get('enabled', true);
    }

    /** @return array<int,string> event names that fire this channel */
    public function events(): array
    {
        $e = $this->get('events', []);
        if (is_string($e)) {
            $e = array_filter(array_map('trim', explode(',', $e)));
        }
        return is_array($e) ? array_values($e) : [];
    }

    /** Whether this channel should fire for the given event name. */
    public function firesFor(string $event): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $events = $this->events();
        if ($events === []) {
            return true;
        }
        foreach ($events as $e) {
            if (strcasecmp((string)$e, $event) === 0) {
                return true;
            }
        }
        return false;
    }

    public function message(): string
    {
        return (string)$this->get('message', '');
    }

    // --- Telegram ---------------------------------------------------------

    public function botToken(): string
    {
        return (string)$this->get('bot_token', '');
    }

    public function chatId(): string
    {
        return (string)$this->get('chat_id', '');
    }

    public function parseMode(): string
    {
        return (string)$this->get('parse_mode', 'HTML');
    }

    // --- Webhook ----------------------------------------------------------

    public function url(): string
    {
        return (string)$this->get('url', '');
    }

    public function method(): string
    {
        return strtoupper((string)$this->get('method', 'POST')) === 'GET' ? 'GET' : 'POST';
    }

    /** @return array<string,string> header name => value template */
    public function headers(): array
    {
        $h = $this->get('headers', []);
        if (is_string($h)) {
            $decoded = json_decode($h, true);
            $h = is_array($decoded) ? $decoded : [];
        }
        return is_array($h) ? $h : [];
    }

    public function body(): string
    {
        return (string)$this->get('body', '');
    }

    public function contentType(): string
    {
        return (string)$this->get('content_type', 'application/json');
    }

    // --- Email ------------------------------------------------------------

    public function to(): string
    {
        return (string)$this->get('to', '');
    }

    public function from(): string
    {
        return (string)$this->get('from', '');
    }

    public function subject(): string
    {
        return (string)$this->get('subject', 'YaAff notification');
    }
}
