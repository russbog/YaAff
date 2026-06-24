<?php

require_once __DIR__ . '/../entities/Channel.php';
require_once __DIR__ . '/MessageRenderer.php';

/**
 * Transport for a notification {@see Channel} (Phase 9).
 *
 * One sender handles Telegram, generic webhooks and email. Each transport
 * builds its concrete request purely (testable) from the channel template plus
 * a {token} map, then performs I/O with strict timeouts. Sends never throw — a
 * deterministic result array is always returned so callers log and continue,
 * matching the tracker's graceful-degradation policy.
 *
 * The actual network/mail calls live in small protected methods so tests can
 * subclass and capture requests without real I/O.
 */
class ChannelSender
{
    public const CONNECT_TIMEOUT = 2;
    public const TOTAL_TIMEOUT = 3;

    /**
     * @param array<string,scalar|null> $tokens
     * @return array{ok:bool,target:string,message:string,code:int,error:string}
     */
    public function send(Channel $channel, array $tokens): array
    {
        switch ($channel->type()) {
            case 'telegram':
                return $this->sendTelegram($channel, $tokens);
            case 'email':
                return $this->sendEmail($channel, $tokens);
            case 'webhook':
            default:
                return $this->sendWebhook($channel, $tokens);
        }
    }

    // --- Telegram ---------------------------------------------------------

    /**
     * Build the Telegram sendMessage request. Pure. The bot token only appears
     * in the URL and is deliberately excluded from `target` so it is never
     * logged.
     *
     * @param array<string,scalar|null> $tokens
     * @return array{url:string,fields:array<string,string>,target:string,error:string}
     */
    public static function buildTelegram(Channel $channel, array $tokens): array
    {
        $botToken = $channel->botToken();
        $chatId = MessageRenderer::render($channel->chatId(), $tokens);
        $text = MessageRenderer::render($channel->message(), $tokens);
        if ($botToken === '' || $chatId === '') {
            return ['url' => '', 'fields' => [], 'target' => 'telegram:' . $chatId, 'error' => 'missing bot_token/chat_id'];
        }
        $fields = ['chat_id' => $chatId, 'text' => $text];
        $parseMode = $channel->parseMode();
        if ($parseMode !== '') {
            $fields['parse_mode'] = $parseMode;
        }
        return [
            'url' => 'https://api.telegram.org/bot' . $botToken . '/sendMessage',
            'fields' => $fields,
            'target' => 'telegram:' . $chatId,
            'error' => '',
        ];
    }

    /**
     * @param array<string,scalar|null> $tokens
     * @return array{ok:bool,target:string,message:string,code:int,error:string}
     */
    protected function sendTelegram(Channel $channel, array $tokens): array
    {
        $req = self::buildTelegram($channel, $tokens);
        if ($req['url'] === '') {
            return ['ok' => false, 'target' => $req['target'], 'message' => '', 'code' => 0, 'error' => $req['error']];
        }
        $res = $this->httpPost($req['url'], http_build_query($req['fields']), ['Content-Type: application/x-www-form-urlencoded']);
        return [
            'ok' => $res['code'] >= 200 && $res['code'] < 300,
            'target' => $req['target'],
            'message' => (string)($req['fields']['text'] ?? ''),
            'code' => $res['code'],
            'error' => $res['error'],
        ];
    }

    // --- Webhook ----------------------------------------------------------

    /**
     * Build the webhook request. Pure.
     *
     * @param array<string,scalar|null> $tokens
     * @return array{method:string,url:string,headers:array<int,string>,body:string,target:string}
     */
    public static function buildWebhook(Channel $channel, array $tokens): array
    {
        $url = MessageRenderer::render($channel->url(), $tokens);
        $body = $channel->body() !== ''
            ? MessageRenderer::render($channel->body(), $tokens)
            : MessageRenderer::render($channel->message(), $tokens);

        $headers = [];
        $hasContentType = false;
        foreach ($channel->headers() as $name => $value) {
            $headers[] = $name . ': ' . MessageRenderer::render((string)$value, $tokens);
            if (strcasecmp((string)$name, 'Content-Type') === 0) {
                $hasContentType = true;
            }
        }
        if ($channel->method() === 'POST' && !$hasContentType && $body !== '') {
            $headers[] = 'Content-Type: ' . $channel->contentType();
        }

        return ['method' => $channel->method(), 'url' => $url, 'headers' => $headers, 'body' => $body, 'target' => $url];
    }

    /**
     * @param array<string,scalar|null> $tokens
     * @return array{ok:bool,target:string,message:string,code:int,error:string}
     */
    protected function sendWebhook(Channel $channel, array $tokens): array
    {
        $req = self::buildWebhook($channel, $tokens);
        if ($req['url'] === '') {
            return ['ok' => false, 'target' => '', 'message' => '', 'code' => 0, 'error' => 'empty url'];
        }
        $res = $req['method'] === 'POST'
            ? $this->httpPost($req['url'], $req['body'], $req['headers'])
            : $this->httpGet($req['url'], $req['headers']);
        return [
            'ok' => $res['code'] >= 200 && $res['code'] < 300,
            'target' => $req['target'],
            'message' => $req['body'],
            'code' => $res['code'],
            'error' => $res['error'],
        ];
    }

    // --- Email ------------------------------------------------------------

    /**
     * Build the email parts. Pure.
     *
     * @param array<string,scalar|null> $tokens
     * @return array{to:string,subject:string,body:string,headers:string,target:string,error:string}
     */
    public static function buildEmail(Channel $channel, array $tokens): array
    {
        $to = MessageRenderer::render($channel->to(), $tokens);
        $subject = MessageRenderer::render($channel->subject(), $tokens);
        $body = MessageRenderer::render($channel->message(), $tokens);
        $from = MessageRenderer::render($channel->from(), $tokens);
        $headers = $from !== '' ? ('From: ' . $from) : '';
        return [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'headers' => $headers,
            'target' => 'email:' . $to,
            'error' => $to === '' ? 'missing recipient' : '',
        ];
    }

    /**
     * @param array<string,scalar|null> $tokens
     * @return array{ok:bool,target:string,message:string,code:int,error:string}
     */
    protected function sendEmail(Channel $channel, array $tokens): array
    {
        $req = self::buildEmail($channel, $tokens);
        if ($req['to'] === '') {
            return ['ok' => false, 'target' => $req['target'], 'message' => '', 'code' => 0, 'error' => $req['error']];
        }
        $ok = $this->mail($req['to'], $req['subject'], $req['body'], $req['headers']);
        return [
            'ok' => $ok,
            'target' => $req['target'],
            'message' => $req['body'],
            'code' => $ok ? 200 : 0,
            'error' => $ok ? '' : 'mail() failed',
        ];
    }

    // --- I/O (overridable in tests) --------------------------------------

    /**
     * @param array<int,string> $headers
     * @return array{code:int,error:string,content:string}
     */
    protected function httpPost(string $url, string $body, array $headers): array
    {
        return $this->curl($url, 'POST', $body, $headers);
    }

    /**
     * @param array<int,string> $headers
     * @return array{code:int,error:string,content:string}
     */
    protected function httpGet(string $url, array $headers): array
    {
        return $this->curl($url, 'GET', '', $headers);
    }

    /**
     * @param array<int,string> $headers
     * @return array{code:int,error:string,content:string}
     */
    private function curl(string $url, string $method, string $body, array $headers): array
    {
        $curl = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($curl, $opts);
        $content = curl_exec($curl);
        $info = curl_getinfo($curl);
        $error = curl_error($curl);
        curl_close($curl);

        return [
            'code' => (int)($info['http_code'] ?? 0),
            'error' => (string)$error,
            'content' => $content === false ? '' : (string)$content,
        ];
    }

    protected function mail(string $to, string $subject, string $body, string $headers): bool
    {
        return @mail($to, $subject, $body, $headers);
    }
}
