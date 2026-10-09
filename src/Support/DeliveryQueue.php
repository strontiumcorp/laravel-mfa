<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Where MFA mail and texts go out (config mfa.delivery): codes, the
 * enrollment verification code and the account owner's security alerts all
 * follow the same rule (queued() below).
 */
final class DeliveryQueue
{
    /**
     * Queued when a connection or a queue name is set (a queue name alone
     * uses the default connection), unless that connection is "sync": then
     * it is sent inline, with immediate error feedback.
     *
     * @param  array{queue_connection?: string|null, queue?: string|null}|null  $delivery  null = config('mfa.delivery')
     */
    public static function queued(?array $delivery = null): bool
    {
        $delivery ??= (array) config('mfa.delivery');
        $connection = ($delivery['queue_connection'] ?? null) ?: null;

        if ($connection === null && empty($delivery['queue'])) {
            return false;
        }

        $connection ??= config('queue.default');

        return config("queue.connections.{$connection}.driver") !== 'sync';
    }

    /**
     * Send a mail notification the configured way: queued on the delivery
     * queue (keeping a connection or queue the notification set itself) when
     * delivery is queued and it can be queued, otherwise inline, so a
     * failure throws here.
     */
    public static function send(string $email, Notification $notification): void
    {
        if (! self::queued() || ! $notification instanceof ShouldQueue) {
            NotificationFacade::route('mail', $email)->notifyNow($notification);

            return;
        }

        // Queueable notifications carry their own connection and queue.
        if (property_exists($notification, 'connection') && $notification->connection === null && method_exists($notification, 'onConnection')) {
            $notification->onConnection(self::connection());
        }
        if (property_exists($notification, 'queue') && $notification->queue === null && method_exists($notification, 'onQueue')) {
            $notification->onQueue(self::queue());
        }

        NotificationFacade::route('mail', $email)->notify($notification);
    }

    /** The configured connection (null = the app's default). */
    public static function connection(): ?string
    {
        return config('mfa.delivery.queue_connection') ?: null;
    }

    /** The configured queue (null = the connection's default). */
    public static function queue(): ?string
    {
        return config('mfa.delivery.queue') ?: null;
    }
}
