<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../notifications/ChannelSender.php';

class ChannelSenderTest extends TestCase
{
    public function testBuildTelegramRendersTextAndHidesTokenFromTarget(): void
    {
        $channel = new Channel(['name' => 'tg']);
        $channel->set('type', 'telegram');
        $channel->set('bot_token', 'SECRET123');
        $channel->set('chat_id', '42');
        $channel->set('message', 'ROI {roi}%');

        $req = ChannelSender::buildTelegram($channel, ['roi' => -10]);

        $this->assertSame('https://api.telegram.org/botSECRET123/sendMessage', $req['url']);
        $this->assertSame('ROI -10%', $req['fields']['text']);
        $this->assertSame('42', $req['fields']['chat_id']);
        $this->assertSame('HTML', $req['fields']['parse_mode']);
        $this->assertStringNotContainsString('SECRET123', $req['target']);
    }

    public function testBuildTelegramErrorsWhenMissingCredentials(): void
    {
        $channel = new Channel(['name' => 'tg']);
        $channel->set('type', 'telegram');
        $req = ChannelSender::buildTelegram($channel, []);
        $this->assertSame('', $req['url']);
        $this->assertNotSame('', $req['error']);
    }

    public function testBuildWebhookRendersUrlBodyAndAddsContentType(): void
    {
        $channel = new Channel(['name' => 'wh']);
        $channel->set('type', 'webhook');
        $channel->set('url', 'https://hook/{rule}');
        $channel->set('body', '{"roi":{roi}}');
        $channel->set('content_type', 'application/json');

        $req = ChannelSender::buildWebhook($channel, ['rule' => 'r1', 'roi' => 3]);

        $this->assertSame('POST', $req['method']);
        $this->assertSame('https://hook/r1', $req['url']);
        $this->assertSame('{"roi":3}', $req['body']);
        $this->assertContains('Content-Type: application/json', $req['headers']);
    }

    public function testBuildWebhookFallsBackToMessageWhenBodyEmpty(): void
    {
        $channel = new Channel(['name' => 'wh']);
        $channel->set('type', 'webhook');
        $channel->set('url', 'https://hook');
        $channel->set('message', 'hello {rule}');

        $req = ChannelSender::buildWebhook($channel, ['rule' => 'r1']);
        $this->assertSame('hello r1', $req['body']);
    }

    public function testBuildEmailRendersParts(): void
    {
        $channel = new Channel(['name' => 'mail']);
        $channel->set('type', 'email');
        $channel->set('to', 'a@b.com');
        $channel->set('from', 'tds@b.com');
        $channel->set('subject', 'Alert {rule}');
        $channel->set('message', 'body {roi}');

        $req = ChannelSender::buildEmail($channel, ['rule' => 'r1', 'roi' => 9]);
        $this->assertSame('a@b.com', $req['to']);
        $this->assertSame('Alert r1', $req['subject']);
        $this->assertSame('body 9', $req['body']);
        $this->assertSame('From: tds@b.com', $req['headers']);
    }

    public function testSendDispatchesByTypeWithoutRealIo(): void
    {
        $sender = new class extends ChannelSender {
            public array $posts = [];
            protected function httpPost(string $url, string $body, array $headers): array
            {
                $this->posts[] = ['url' => $url, 'body' => $body];
                return ['code' => 200, 'error' => '', 'content' => 'ok'];
            }
        };

        $channel = new Channel(['name' => 'tg']);
        $channel->set('type', 'telegram');
        $channel->set('bot_token', 'T');
        $channel->set('chat_id', '1');
        $channel->set('message', 'hi');

        $res = $sender->send($channel, []);
        $this->assertTrue($res['ok']);
        $this->assertSame(200, $res['code']);
        $this->assertCount(1, $sender->posts);
    }

    public function testSendReturnsFailureOnNon2xx(): void
    {
        $sender = new class extends ChannelSender {
            protected function httpPost(string $url, string $body, array $headers): array
            {
                return ['code' => 500, 'error' => 'boom', 'content' => ''];
            }
        };
        $channel = new Channel(['name' => 'wh']);
        $channel->set('type', 'webhook');
        $channel->set('url', 'https://hook');
        $channel->set('message', 'x');

        $res = $sender->send($channel, []);
        $this->assertFalse($res['ok']);
        $this->assertSame(500, $res['code']);
    }
}
