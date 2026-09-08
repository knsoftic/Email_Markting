<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Email;
use App\Models\Mailbox;
use App\Models\Scopes\AccountScope;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\SystemHealthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Platform overview. Super admins run with no tenant bound, so every query here
 * still lifts AccountScope explicitly to read across all accounts.
 *
 * Note the singular: withoutGlobalScope(AccountScope::class), never
 * withoutGlobalScopes(). The plural form also lifts SoftDeletingScope, which is
 * how these cards came to count deleted users, contacts, campaigns, mailboxes
 * and inbox mail as if they were still there — while the accounts card, which
 * never lifted anything, correctly did not. Two answers to "how many" on one
 * screen, and the deleted rows also reached the "recent" lists below.
 */
class DashboardController extends Controller
{
    public function __construct(protected SystemHealthService $health) {}

    public function __invoke(): View
    {
        $campaignTotals = Campaign::withoutGlobalScope(AccountScope::class)
            ->selectRaw('COALESCE(SUM(sent_count),0) sent, COALESCE(SUM(failed_count),0) failed, COALESCE(SUM(unique_opens),0) opens, COALESCE(SUM(unique_clicks),0) clicks')
            ->first();

        $sent = (int) ($campaignTotals->sent ?? 0);

        return view('admin.dashboard', [
            'stats' => [
                'users_total' => User::withoutGlobalScope(AccountScope::class)->count(),
                'users_active' => User::withoutGlobalScope(AccountScope::class)->where('status', 'active')->count(),
                'users_suspended' => User::withoutGlobalScope(AccountScope::class)->where('status', 'suspended')->count(),
                'accounts_total' => Account::count(),
                'accounts_active' => Account::where('status', 'active')->count(),
                'subscribers_total' => Subscriber::withoutGlobalScope(AccountScope::class)->count(),
                'campaigns_total' => Campaign::withoutGlobalScope(AccountScope::class)->count(),
                'emails_sent' => $sent,
                'emails_failed' => (int) ($campaignTotals->failed ?? 0),
                'emails_received' => Email::withoutGlobalScope(AccountScope::class)->where('direction', 'incoming')->count(),
                'smtp_accounts' => SmtpAccount::withoutGlobalScope(AccountScope::class)->count(),
                'mailboxes' => Mailbox::withoutGlobalScope(AccountScope::class)->count(),
                'open_rate' => $sent > 0 ? round(((int) $campaignTotals->opens / $sent) * 100, 1) : 0.0,
                'click_rate' => $sent > 0 ? round(((int) $campaignTotals->clicks / $sent) * 100, 1) : 0.0,
            ],
            'charts' => [
                'registrations' => $this->dailySeries(
                    User::withoutGlobalScope(AccountScope::class)->getQuery(), 'created_at'
                ),
                'sending' => $this->dailySeries(
                    CampaignLog::withoutGlobalScope(AccountScope::class)->where('status', 'sent')->getQuery(), 'created_at'
                ),
            ],
            'recentUsers' => User::withoutGlobalScope(AccountScope::class)->with('account')->latest()->limit(8)->get(),
            'recentCampaigns' => Campaign::withoutGlobalScope(AccountScope::class)->with('account')->latest()->limit(8)->get(),
            'recentActivity' => ActivityLog::withoutGlobalScope(AccountScope::class)->with('user')->latest()->limit(10)->get(),
            'queue' => $this->health->queue(),
            'health' => $this->health->checks(),
        ]);
    }

    /**
     * Counts per day for the last 14 days, zero-filled so the chart has no
     * gaps on quiet days.
     *
     * @return array{labels: array<int, string>, data: array<int, int>}
     */
    protected function dailySeries($query, string $column): array
    {
        $from = Carbon::today()->subDays(13);

        $rows = $query
            ->where($column, '>=', $from)
            ->select(DB::raw("DATE({$column}) as day"), DB::raw('COUNT(*) as aggregate'))
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        $labels = [];
        $data = [];

        for ($i = 0; $i < 14; $i++) {
            $date = $from->copy()->addDays($i);
            $labels[] = $date->format('d M');
            $data[] = (int) ($rows[$date->toDateString()] ?? 0);
        }

        return ['labels' => $labels, 'data' => $data];
    }
}
