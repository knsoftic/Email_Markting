<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\Mailbox;
use App\Models\SmtpAccount;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Turns stored notification rows into something a Blade file can print.
 *
 * ── A notification outlives the thing it is about ───────────────────────────
 * "Your campaign finished sending" is still true a month after somebody
 * deleted that campaign. The row must therefore keep rendering, and its link
 * must not become a 404 waiting to happen. So every target is checked for
 * existence before a link is offered, and a notification whose target is gone
 * renders as plain text with a line saying so — the news, and then what became
 * of the subject of it. Silently dropping the row would be worse: the customer
 * would remember being told something and find no record of it.
 *
 * ── Why the checks are batched ──────────────────────────────────────────────
 * Resolving each row on its own would be one query per notification per page
 * render — and the bell renders on every page in the product. Instead the page
 * of rows is grouped by kind and each kind costs one indexed `whereIn`: at
 * most three extra queries for a page of any size.
 *
 * ── Permissions are re-checked at render time ───────────────────────────────
 * AccountNotifier only sends to people who could act on it, but a role can be
 * narrowed afterwards. Offering a link that answers 403 is a control that does
 * nothing, so the link is withheld and the reason is stated instead.
 */
class NotificationPresenter
{
    /** kind => the model that owns it. Soft-deleted rows count as gone. */
    protected const MODELS = [
        'campaign' => Campaign::class,
        'smtp_account' => SmtpAccount::class,
        'mailbox' => Mailbox::class,
    ];

    /**
     * route name => the permission slug needed to open it. Slugs are from
     * PermissionSeeder; a route missing from this map needs none.
     */
    protected const ROUTE_PERMISSIONS = [
        'campaigns.show' => 'campaigns.view',
        'smtp.show' => 'smtp.view',
        'smtp.index' => 'smtp.view',
        'mailboxes.show' => 'mailboxes.view',
    ];

    /**
     * @param  Collection<int, DatabaseNotification>|iterable<DatabaseNotification>  $notifications
     * @return Collection<int, array<string, mixed>>
     */
    public function present(iterable $notifications, ?User $user = null): Collection
    {
        $rows = collect($notifications);

        if ($rows->isEmpty()) {
            return collect();
        }

        $payloads = $rows->mapWithKeys(fn (DatabaseNotification $n) => [$n->id => $this->payloadOf($n)]);
        $alive = $this->aliveTargets($payloads);

        return $rows->map(function (DatabaseNotification $notification) use ($payloads, $alive, $user) {
            $data = $payloads[$notification->id];

            $kind = (string) $data['kind'];
            $targetId = (int) $data['target_id'];
            $exists = $kind === '' || isset($alive[$kind][$targetId]);

            $permission = self::ROUTE_PERMISSIONS[$data['route']] ?? null;
            $allowed = $permission === null || ($user?->hasPermission($permission) ?? false);

            return [
                'id' => $notification->id,
                'title' => (string) $data['title'],
                'body' => (string) $data['body'],
                'level' => in_array($data['level'], ['success', 'info', 'warning', 'danger'], true)
                    ? (string) $data['level']
                    : 'info',
                'icon' => (string) $data['icon'],
                'read' => $notification->read_at !== null,
                'at' => $notification->created_at,
                'url' => $exists && $allowed ? $this->urlFor($data) : null,
                'note' => match (true) {
                    ! $exists => (string) $data['gone_note'],
                    ! $allowed => 'Your role can no longer open the screen this refers to.',
                    default => '',
                },
            ];
        })->values();
    }

    /**
     * The stored payload, with every key the screens read guaranteed present.
     *
     * `data` is cast to array by Laravel, but a row written by an older
     * release — or by hand — can be missing keys or hold the wrong types, and
     * a bell that fatals takes every page in the product with it.
     *
     * @return array<string, mixed>
     */
    protected function payloadOf(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        if (! is_array($data)) {
            $data = [];
        }

        $data = array_replace(AccountNotification::DEFAULTS, array_filter(
            $data,
            fn ($value) => is_scalar($value),
        ));

        // A title is the one thing that cannot be empty: an untitled row in the
        // list is a row nobody can act on.
        if (trim((string) $data['title']) === '') {
            $data['title'] = AccountNotification::DEFAULTS['title'];
        }

        return $data;
    }

    /**
     * Which of the referenced targets still exist, as [kind => [id => true]].
     *
     * Reads through the ordinary (account-scoped, soft-delete aware) query, so
     * a row belonging to another account or in the recycle bin correctly
     * counts as gone.
     *
     * @param  Collection<string, array<string, mixed>>  $payloads
     * @return array<string, array<int, bool>>
     */
    protected function aliveTargets(Collection $payloads): array
    {
        $alive = [];

        foreach (self::MODELS as $kind => $model) {
            $ids = $payloads
                ->filter(fn (array $data) => $data['kind'] === $kind && (int) $data['target_id'] > 0)
                ->map(fn (array $data) => (int) $data['target_id'])
                ->unique()
                ->values();

            if ($ids->isEmpty()) {
                continue;
            }

            $alive[$kind] = $model::query()
                ->whereIn('id', $ids->all())
                ->pluck('id')
                ->mapWithKeys(fn ($id) => [(int) $id => true])
                ->all();
        }

        return $alive;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function urlFor(array $data): ?string
    {
        $name = (string) $data['route'];

        if ($name === '' || ! Route::has($name)) {
            return null;
        }

        $param = (string) $data['route_param'];
        $parameters = $param !== '' && (int) $data['target_id'] > 0
            ? [$param => (int) $data['target_id']]
            : [];

        return route($name, $parameters);
    }
}
