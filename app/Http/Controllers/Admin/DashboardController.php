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
 * Platform overview. Super admins run with no tenant bound, so every query
 * here still needs withoutGlobalScopes() on tenant-owned models to be explicit
 * about reading across all accounts.
 */
class DashboardController extends Controller
{
    public function __construct(protected SystemHealthService $health) {}

    public function __invoke(): View
    {
        $campaignTotals = Campaign::withoutGlobalScopes()
            ->selectRaw('COALESCE(SUM(sent_count),0) sent, COALESCE(SUM(failed_count),0) failed, COALESCE(SUM(unique_opens),0) opens, COALESCE(SUM(unique_clicks),0) clicks')
            ->first();

        $sent = (int) ($campaignTotals->sent ?? 0);

        return view('admin.dashboard', [
            'stats' => [
                'users_total' => User::withoutGlobalScopes()->count(),
                'users_active' => User::withoutGlobalScopes()->where('status', 'active')->count(),
                'users_suspended' => User::withoutGlobalScopes()->where('status', 'suspended')->count(),
                'accounts_total' => Account::count(),
                'accounts_active' => Account::where('status', 'active')->count(),
                'subscribers_total' => Subscriber::withoutGlobalScopes()->count(),
                'campaigns_total' => Campaign::withoutGlobalScopes()->count(),
                'emails_sent' => $sent,
                'emails_failed' => (int) ($campaignTotals->failed ?? 0),
                'emails_received' => Email::withoutGlobalScopes()->where('direction', 'incoming')->count(),
                'smtp_accounts' => SmtpAccount::withoutGlobalScope(AccountScope::class)->count(),
                'mailboxes' => Mailbox::withoutGlobalScopes()->count(),
                'open_rate' => $sent > 0 ? round(((int) $campaignTotals->opens / $sent) * 100, 1) : 0.0,
                'click_rate' => $sent > 0 ? round(((int) $campaignTotals->clicks / $sent) * 100, 1) : 0.0,
            ],
            'charts' => [
                'registrations' => $this->dailySeries(
                    User::withoutGlobalScopes()->getQuery(), 'created_at'
                ),
                'sending' => $this->dailySeries(
                    CampaignLog::withoutGlobalScopes()->where('status', 'sent')->getQuery(), 'created_at'
                ),
            ],
            'recentUsers' => User::withoutGlobalScopes()->with('account')->latest()->limit(8)->get(),
            'recentCampaigns' => Campaign::withoutGlobalScopes()->with('account')->latest()->limit(8)->get(),
            'recentActivity' => ActivityLog::withoutGlobalScopes()->with('user')->latest()->limit(10)->get(),
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
