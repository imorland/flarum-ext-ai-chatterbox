<?php

/*
 * This file is part of ianm/ai-chatterbox.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace IanM\AIChatterbox;

use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\Config;
use Flarum\Realtime\Websocket\Settings as RealtimeSettings;
use Flarum\User\User;
use GuzzleHttp\Psr7\Message as Psr7Message;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Contracts\Container\Container;
use Ratchet\RFC6455\Handshake\ClientNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\Message;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use Throwable;

/**
 * Makes AI users look like real people composing a post: it pauses for the
 * configured "thinking" delay and, while paused, drives flarum/realtime's typing
 * indicator so the "X is typing…" dot (and the discussion-list ambient dot)
 * appear before the post lands.
 *
 * flarum/realtime only relays `client-typing` events that arrive over a genuine
 * websocket connection that is subscribed to the channel (see the
 * isAuthorizedClientEvent guard in the realtime server) — a server-side HTTP
 * trigger is ignored. So this connects to the realtime server as a real client
 * (the bot), subscribes to the discussion's private channel with a signed auth
 * token, and emits `client-typing` exactly as a browser would. The server's own
 * relay logic then lights both the in-discussion indicator and the index dot.
 *
 * If flarum/realtime isn't installed/enabled, there's no connection to make and
 * every method degrades to a plain sleep.
 */
class TypingSimulator
{
    /**
     * How often (seconds) to re-send the typing event while delaying, comfortably
     * inside realtime's 6s expiry so the indicator never flickers off mid-pause.
     */
    protected const REFRESH_SECONDS = 2;

    /**
     * Hard ceiling on how long the websocket interaction may run, so a stuck
     * connection can never hang the queue worker beyond the delay window.
     */
    protected const CONNECT_TIMEOUT = 4;

    public function __construct(
        protected Settings $settings,
        protected Container $container
    ) {
    }

    /**
     * Pause for the configured delay while showing $bot typing a reply in the
     * given discussion, then run $submit. Returns whatever $submit returns.
     */
    public function replyWithTyping(int $discussionId, User $bot, callable $submit): mixed
    {
        $this->typeThenSubmit(
            channel: "private-typing=$discussionId",
            event: 'client-typing',
            data: fn () => [
                'displayName' => $this->displayName($bot),
                'discloseOnline' => $this->disclosesOnline($bot),
                'time' => $this->nowMs(),
            ]
        );

        return $submit();
    }

    /**
     * Pause for the configured delay while showing $bot composing a NEW discussion
     * in the given tags (the discussion-list ambient dot), then run $submit.
     *
     * Compose-typing is sent on the bot's own `private-user={id}` channel as
     * `client-index-typing-tags`; the realtime server re-authorises each tag and
     * lights the tag list (see relayComposeTyping).
     *
     * @param int[] $tagIds
     */
    public function newDiscussionWithTyping(array $tagIds, User $bot, callable $submit): mixed
    {
        $this->typeThenSubmit(
            channel: 'private-user='.$bot->id,
            event: 'client-index-typing-tags',
            data: fn () => ['tags' => array_values($tagIds)]
        );

        return $submit();
    }

    /**
     * Run the delay; if realtime is available and typing is enabled, hold a real
     * websocket connection for the duration and emit the event periodically.
     * Always sleeps for the delay even if the connection fails — typing is
     * cosmetic and must never block or shorten the post.
     *
     * @param callable():array<string, mixed> $data
     */
    protected function typeThenSubmit(string $channel, string $event, callable $data): void
    {
        $seconds = $this->randomDelay();

        if ($seconds <= 0) {
            return;
        }

        $rt = $this->realtimeSettings();

        if (! $this->settings->simulateTyping() || $rt === null) {
            sleep($seconds);

            return;
        }

        try {
            $this->driveTyping($rt, $channel, $event, $data, $seconds);
        } catch (Throwable $e) {
            // Connection problem — never let typing affect the post. Ensure the
            // full delay still elapses so timing is unchanged.
            sleep($seconds);
        }
    }

    /**
     * Open a websocket connection to the realtime server as the bot, subscribe to
     * the (private) channel, and emit $event every REFRESH_SECONDS for $seconds.
     * Runs its own React loop and returns after roughly $seconds.
     *
     * Built directly on ratchet/rfc6455 + react/socket (both pulled in by
     * flarum/realtime) to avoid adding a websocket-client dependency: we drive the
     * HTTP upgrade handshake, then read/write masked frames ourselves.
     *
     * @param callable():array<string, mixed> $data
     */
    protected function driveTyping(RealtimeSettings $rt, string $channel, string $event, callable $data, int $seconds): void
    {
        $loop = Loop::get();
        $connector = new Connector(['timeout' => self::CONNECT_TIMEOUT], $loop);

        $secure = $rt->phpClientSecure;
        $target = ($secure ? 'tls://' : 'tcp://')."{$rt->phpClientHost}:{$rt->phpClientPort}";
        $wsScheme = $secure ? 'wss' : 'ws';
        $uri = new Uri("{$wsScheme}://{$rt->phpClientHost}:{$rt->phpClientPort}/app/{$rt->appKey}");

        $negotiator = new ClientNegotiator();
        $request = $negotiator->generateRequest($uri);

        // The realtime server allow-lists the handshake Origin against the forum
        // URL (browsers send it automatically; we must set it explicitly or the
        // connection is rejected with "origin not allowed").
        $request = $request->withHeader('Origin', (string) $this->container->make(Config::class)->url());

        $done = false;
        $finish = function () use (&$done, $loop) {
            if (! $done) {
                $done = true;
                $loop->stop();
            }
        };

        // Absolute deadline so a stuck connection can never outlive the window.
        $loop->addTimer($seconds + self::CONNECT_TIMEOUT, $finish);

        $connector->connect($target)->then(function (ConnectionInterface $conn) use (
            $loop,
            $request,
            $negotiator,
            $channel,
            $event,
            $data,
            $seconds,
            $rt,
            $finish
        ) {
            $handshakeComplete = false;
            $buffer = '';

            // Build the frame reader once the handshake is done.
            $messageBuffer = new MessageBuffer(
                new CloseFrameChecker(),
                function (Message $message) use ($conn, $channel, $event, $data, $loop, $seconds, $rt) {
                    $payload = json_decode($message->getPayload());
                    $name = $payload->event ?? null;

                    if ($name === 'pusher:connection_established') {
                        $socketId = json_decode($payload->data ?? '{}')->socket_id ?? null;

                        if (! $socketId) {
                            return;
                        }

                        // Subscribe to the private channel with a signed auth token.
                        $auth = $rt->appKey.':'.hash_hmac('sha256', "{$socketId}:{$channel}", $rt->appSecret);
                        $this->sendFrame($conn, json_encode([
                            'event' => 'pusher:subscribe',
                            'data' => ['channel' => $channel, 'auth' => $auth],
                        ]));

                        return;
                    }

                    // Only start emitting once the subscription is confirmed — the
                    // server drops client events on channels the connection isn't yet
                    // registered as subscribed to (isAuthorizedClientEvent).
                    if ($name !== 'pusher_internal:subscription_succeeded') {
                        return;
                    }

                    $emit = function () use ($conn, $channel, $event, $data) {
                        $this->sendFrame($conn, json_encode([
                            'event' => $event,
                            'channel' => $channel,
                            'data' => $data(),
                        ]));
                    };
                    $emit();

                    $timer = $loop->addPeriodicTimer(self::REFRESH_SECONDS, $emit);
                    $loop->addTimer($seconds, function () use ($loop, $timer, $conn) {
                        $loop->cancelTimer($timer);
                        $conn->close();
                    });
                },
                null,
                false // server→client frames are not masked
            );

            $conn->on('data', function ($chunk) use (&$handshakeComplete, &$buffer, $conn, $request, $negotiator, $messageBuffer, $finish) {
                if ($handshakeComplete) {
                    $messageBuffer->onData($chunk);

                    return;
                }

                $buffer .= $chunk;
                $headerEnd = strpos($buffer, "\r\n\r\n");

                if ($headerEnd === false) {
                    return; // wait for the rest of the response headers
                }

                $response = Psr7Message::parseResponse(substr($buffer, 0, $headerEnd + 4));

                if (! $negotiator->validateResponse($request, $response)) {
                    $finish();
                    $conn->close();

                    return;
                }

                $handshakeComplete = true;

                // Any bytes after the handshake belong to the websocket stream.
                $rest = substr($buffer, $headerEnd + 4);
                if ($rest !== '') {
                    $messageBuffer->onData($rest);
                }
            });

            $conn->on('close', $finish);
            $conn->on('error', $finish);

            // Send the HTTP upgrade request to start the handshake.
            $conn->write(Psr7Message::toString($request));
        }, function (Throwable $e) use ($finish) {
            $finish();
        });

        $loop->run();
    }

    /**
     * Write a masked text frame (client frames must be masked per RFC 6455).
     */
    protected function sendFrame(ConnectionInterface $conn, string $payload): void
    {
        $conn->write((new Frame($payload, true, Frame::OP_TEXT))->maskPayload()->getContents());
    }

    protected function randomDelay(): int
    {
        [$min, $max] = $this->settings->delayRange();

        return $min === $max ? $min : mt_rand($min, $max);
    }

    /**
     * The realtime websocket settings, or null when flarum/realtime isn't
     * installed/enabled (so typing degrades to a plain delay). We can't rely on
     * the container binding being registered in every context, so gate on the
     * extension being enabled and resolve the settings directly.
     */
    protected function realtimeSettings(): ?RealtimeSettings
    {
        if (! class_exists(RealtimeSettings::class)) {
            return null;
        }

        $extensions = $this->container->make(ExtensionManager::class);

        if (! $extensions->isEnabled('flarum-realtime')) {
            return null;
        }

        try {
            return $this->container->make(RealtimeSettings::class);
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function displayName(User $bot): string
    {
        return $this->disclosesOnline($bot) ? (string) $bot->display_name : '[anonymous]';
    }

    protected function disclosesOnline(User $bot): bool
    {
        return (bool) $bot->getPreference('discloseOnline', true);
    }

    protected function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
