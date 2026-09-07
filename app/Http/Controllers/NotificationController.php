<?php

namespace App\Http\Controllers;

use App\Notifications\NotificationPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

/**
 * The bell's full screen, and the four things a person can do with a
 * notification: read one, read them all, delete one, and page back through the
 * rest.
 *
 * ── Everything is scoped through the signed-in user's own relation ──────────
 * Notifications are personal rows, not account rows: two people in the same
 * account each get their own copy and read them independently. So every lookup
 * here goes through $request->user()->notifications(), never through
 * DatabaseNotification::find(). That is not a convenience — it is the whole
 * authorisation check. A uuid guessed or copied from a colleague's screen
 * simply does not exist inside the caller's own relation and 404s, and there
 * is no path by which a missing check could leak one.
 */
class NotificationController extends Controller
{
    /** Rows per page. Enough to scan, small enough to render fast. */
    public const PER_PAGE = 20;

    public function __construct(protected NotificationPresenter $presenter) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // filter() rather than input(): ?show[]=x must not reach a comparison
        // as an array and 500 the page.
        $show = $request->filter('show') === 'unread' ? 'unread' : 'all';

        $query = $user->notifications();

        if ($show === 'unread') {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('notifications.index', [
            'notifications' => $notifications,
            'rows' => $this->presenter->present($notifications->getCollection(), $user),
            'show' => $show,
            'unreadCount' => $user->unreadNotifications()->count(),
            'totalCount' => $user->notifications()->count(),
        ]);
    }

    /**
     * Marks one as read.
     *
     * Two callers, two sensible destinations. The bell and the list use the
     * notification's title as the control, and there the point of clicking is
     * to go and look at the thing — so this lands on the target. A "mark read"
     * button that is only about clearing the badge passes back=1 and stays put,
     * rather than throwing the reader off the page they were on.
     *
     * `back` is a boolean, never a URL: a caller-supplied redirect target is an
     * open redirect, and this route is reachable from a bell on every page.
     */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $row = $this->find($request, $notification);

        // markAsRead() is a no-op on an already-read row, so a double click
        // does not rewrite the timestamp.
        $row->markAsRead();

        if ($request->boolean('back')) {
            return back();
        }

        $url = $this->presenter->present([$row], $request->user())->first()['url'] ?? null;

        if ($url === null) {
            // The target is gone, or this role can no longer open it. Landing
            // on a 404 would be worse than saying so.
            return redirect()->route('notifications.index')
                ->with('info', 'Marked as read. There is nothing left to open for that one.');
        }

        return redirect()->to($url);
    }

    /**
     * Marks every unread notification read.
     *
     * update() in one statement rather than a loop over models: a person who
     * has ignored the bell for a month can have hundreds, and the count is
     * reported honestly instead of claiming a fixed "all done".
     */
    public function readAll(Request $request): RedirectResponse
    {
        $marked = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with(
            'success',
            $marked === 0
                ? 'Nothing was unread.'
                : $marked.' '.($marked === 1 ? 'notification' : 'notifications').' marked as read.'
        );
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $this->find($request, $notification)->delete();

        return back()->with('success', 'Notification deleted.');
    }

    /**
     * The caller's own notification, or a 404. See the class docblock for why
     * this may never be relaxed into a global lookup.
     */
    protected function find(Request $request, string $id): DatabaseNotification
    {
        /** @var DatabaseNotification $row */
        $row = $request->user()->notifications()->whereKey($id)->firstOrFail();

        return $row;
    }
}
