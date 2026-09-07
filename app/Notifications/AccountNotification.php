<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Base for every in-app notification in the product.
 *
 * ── One payload shape, so one renderer ──────────────────────────────────────
 * The bell and the notifications page never branch on the notification class.
 * They read `data` and nothing else. That is deliberate: the `type` column
 * holds a PHP class name, and a class that gets renamed or deleted in a later
 * release would otherwise take every historical row on screen down with it.
 * A stored row must keep rendering for as long as it exists in the table, so
 * everything a screen needs is written into `data` at the moment it is sent.
 *
 * The keys are:
 *   title       one line, already worded for a human
 *   body        one or two sentences of detail
 *   level       success | info | warning | danger — colour only, no meaning
 *   icon        campaign | split | smtp | mailbox | plan
 *   kind        the model the notification is about ('' when it is about none)
 *   target_id   that model's id (0 when there is no target)
 *   route       route name to open it, or '' for none
 *   route_param the wildcard's name in that route
 *   gone_note   what the screen says when the target no longer exists
 *
 * ── Not queued, on purpose ──────────────────────────────────────────────────
 * These fire from inside queue jobs and scheduled commands. Writing one row
 * inline is cheaper than serialising a job to write one row later, and it
 * cannot fail in a second place, hours after the event, with nothing left to
 * report it against. AccountNotifier is what keeps that inline write from
 * ever reaching the caller as an exception.
 */
abstract class AccountNotification extends Notification
{
    /** Every key the screens read, so a partial payload can never blow one up. */
    public const DEFAULTS = [
        'title' => 'Notification',
        'body' => '',
        'level' => 'info',
        'icon' => 'plan',
        'kind' => '',
        'target_id' => 0,
        'route' => '',
        'route_param' => '',
        'gone_note' => 'What this refers to no longer exists.',
    ];

    /**
     * The database channel and only the database channel. Email about email
     * that failed to send is a poor plan, and a notification is not worth a
     * second delivery mechanism that can itself fail.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @var array<string, mixed>|null */
    protected ?array $memo = null;

    /**
     * Builds the payload.
     *
     * Called once per notification, not once per recipient — see toArray().
     * That matters because several of these read a row back from the database
     * to describe it accurately, and an account with eight team members would
     * otherwise pay for that read eight times.
     *
     * A payload is built here rather than at the call site so that any read it
     * needs happens inside AccountNotifier's try/catch, where a failure is
     * logged and the send it was reporting on carries on regardless.
     *
     * @return array<string, mixed>
     */
    abstract public function payload(): array;

    /**
     * The permission a user needs before this is worth telling them about.
     * NULL means everybody in the account. Slugs come from PermissionSeeder;
     * nothing here invents one.
     */
    public function permission(): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->memo ??= array_replace(self::DEFAULTS, $this->payload());
    }
}
