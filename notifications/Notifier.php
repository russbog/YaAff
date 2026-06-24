<?php

require_once __DIR__ . '/../db/drivers/DbDriver.php';
require_once __DIR__ . '/../entities/Repositories.php';
require_once __DIR__ . '/../entities/Channel.php';
require_once __DIR__ . '/ChannelSender.php';

/**
 * Notification dispatcher (Phase 9).
 *
 * Given an event name and a flat {token} context, it selects matching channels
 * (by explicit ids, or by the channel's subscribed events), renders + sends
 * each via {@see ChannelSender} and audits every attempt in notification_log.
 * It is decoupled from any single producer: the Phase 8 rules engine triggers
 * it through a `notify` action, but conversions or other events can reuse it.
 *
 * The sender is injected, so the dispatcher is unit-testable with a seeded
 * driver and a fake sender (no real network/mail).
 */
class Notifier
{
    private DbDriver $driver;
    private ChannelSender $sender;
    private EntityRepository $channels;

    public function __construct(DbDriver $driver, ?ChannelSender $sender = null)
    {
        $this->driver = $driver;
        $this->sender = $sender ?? new ChannelSender();
        $this->channels = Repositories::for($driver, Channel::TABLE, Channel::class);
    }

    /**
     * Dispatch an event to its channels.
     *
     * @param array<string,scalar|null> $tokens   message tokens
     * @param array<int,int>|null       $only      explicit channel ids; null = by event
     * @return array<int,array<string,mixed>>      one result per channel attempted
     */
    public function notify(string $event, array $tokens, ?array $only = null): array
    {
        $results = [];
        foreach ($this->channels->findAll([], 'id', 'ASC') as $entity) {
            /** @var Channel $channel */
            $channel = $entity;
            if (!$channel->enabled()) {
                continue;
            }
            if ($only !== null) {
                if (!in_array((int)$channel->id, $only, true)) {
                    continue;
                }
            } elseif (!$channel->firesFor($event)) {
                continue;
            }

            $res = $this->sender->send($channel, $tokens);
            $this->log((int)$channel->id, $channel->type(), $event, $res);
            $results[] = ['channel_id' => (int)$channel->id, 'type' => $channel->type()] + $res;
        }
        return $results;
    }

    /**
     * @param array{ok:bool,target:string,message:string,code:int,error:string} $res
     */
    private function log(int $channelId, string $type, string $event, array $res): void
    {
        $this->driver->insert(
            "INSERT INTO notification_log (channel_id, type, event, ok, target, message, code, error, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                [$channelId, DbDriver::INT],
                [$type, DbDriver::TEXT],
                [$event, DbDriver::TEXT],
                [!empty($res['ok']) ? 1 : 0, DbDriver::INT],
                [(string)($res['target'] ?? ''), DbDriver::TEXT],
                [(string)($res['message'] ?? ''), DbDriver::TEXT],
                [(int)($res['code'] ?? 0), DbDriver::INT],
                [(string)($res['error'] ?? ''), DbDriver::TEXT],
                [time(), DbDriver::INT],
            ]
        );
    }
}
