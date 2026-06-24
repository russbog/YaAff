<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/SqliteDriver.php';
require_once __DIR__ . '/../notifications/Notifier.php';

class NotifierTest extends TestCase
{
    private string $path;
    private SqliteDriver $driver;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytnotif') . '.db';
        $this->driver = new SqliteDriver($this->path);
        $this->driver->exec("CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, group_id INTEGER, settings TEXT NOT NULL DEFAULT '{}', created_at INTEGER, updated_at INTEGER)");
        $this->driver->exec("CREATE TABLE notification_log (id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER, type TEXT, event TEXT, ok INTEGER, target TEXT, message TEXT, code INTEGER, error TEXT, created_at INTEGER)");
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $s) {
            @unlink($this->path . $s);
        }
    }

    private function addChannel(string $name, array $settings): Channel
    {
        $channel = new Channel(['name' => $name]);
        foreach ($settings as $k => $v) {
            $channel->set($k, $v);
        }
        /** @var Channel $saved */
        $saved = Repositories::channels($this->driver)->save($channel);
        return $saved;
    }

    /** @param array<int,array<string,mixed>> $sent */
    private function fakeSender(array &$sent): ChannelSender
    {
        return new class($sent) extends ChannelSender {
            /** @var array<int,array<string,mixed>> */
            private array $sink;
            public function __construct(array &$sink)
            {
                $this->sink = &$sink;
            }
            public function send(Channel $channel, array $tokens): array
            {
                $this->sink[] = ['channel' => $channel->name, 'tokens' => $tokens];
                return ['ok' => true, 'target' => 'fake', 'message' => 'm', 'code' => 200, 'error' => ''];
            }
        };
    }

    public function testNotifiesMatchingEventChannelsAndAudits(): void
    {
        $this->addChannel('all events', ['type' => 'telegram', 'enabled' => true]);
        $this->addChannel('rule only', ['type' => 'webhook', 'enabled' => true, 'events' => ['rule']]);
        $this->addChannel('other only', ['type' => 'webhook', 'enabled' => true, 'events' => ['conversion']]);

        $sent = [];
        $notifier = new Notifier($this->driver, $this->fakeSender($sent));
        $results = $notifier->notify('rule', ['roi' => -5]);

        $this->assertCount(2, $results, 'all-events + rule-only fire; other-only excluded');
        $this->assertCount(2, $sent);

        $log = $this->driver->select('SELECT * FROM notification_log');
        $this->assertCount(2, $log);
        $this->assertSame('rule', $log[0]['event']);
        $this->assertSame(1, (int)$log[0]['ok']);
    }

    public function testSkipsDisabledChannels(): void
    {
        $this->addChannel('off', ['type' => 'telegram', 'enabled' => false]);
        $sent = [];
        $notifier = new Notifier($this->driver, $this->fakeSender($sent));
        $this->assertSame([], $notifier->notify('rule', []));
        $this->assertCount(0, $sent);
    }

    public function testExplicitChannelIdsBypassEventFilter(): void
    {
        $a = $this->addChannel('a', ['type' => 'telegram', 'enabled' => true, 'events' => ['conversion']]);
        $this->addChannel('b', ['type' => 'telegram', 'enabled' => true, 'events' => ['conversion']]);

        $sent = [];
        $notifier = new Notifier($this->driver, $this->fakeSender($sent));
        $results = $notifier->notify('rule', [], [(int)$a->id]);

        $this->assertCount(1, $results);
        $this->assertSame('a', $sent[0]['channel']);
    }
}
