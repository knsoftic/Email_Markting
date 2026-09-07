<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Email;
use App\Models\Subscriber;
use App\Models\UsageCounter;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Account dashboard. Every figure is a real query against this account's own
 * rows — the tenant scope guarantees it can only ever see its own data.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $account = $user->account;

        $subscription = $account?->subscription;
        $plan = $subscription?->plan;

        $usage = UsageCounter::firstOrNew([
            'account_id' => $account?->id,
            'period' => UsageCounter::currentPeriod(),
        ]);

        $subscriberCounts = Subscriber::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $campaignCounts = Campaign::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $sendTotals = Campaign::query()
            ->selectRaw('COALESCE(SUM(sent_count),0) as sent, COALESCE(SUM(unique_opens),0) as opens, COALESCE(SUM(unique_clicks),0) as clicks, COALESCE(SUM(replied_count),0) as replies')
            ->first();

        $sent = (int) ($sendTotals->sent ?? 0);

        return view('dashboard', [
            'account' => $account,
            'plan' => $plan,
            'subscription' => $subscription,
            'usage' => $usage,
            'stats' => [
                'contacts_total' => (int) $subscriberCounts->sum(),
                'contacts_active' => (int) ($subscriberCounts['active'] ?? 0),
                'contacts_unsubscribed' => (int) ($subscriberCounts['unsubscribed'] ?? 0),
                'campaigns_total' => (int) $campaignCounts->sum(),
                'campaigns_sending' => (int) (($campaignCounts['sending'] ?? 0) + ($campaignCounts['queued'] ?? 0)),
                'campaigns_scheduled' => (int) ($campaignCounts['scheduled'] ?? 0),
                'emails_sent' => $sent,
                'emails_received' => Email::where('direction', 'incoming')->count(),
                'emails_unread' => Email::where('direction', 'incoming')->where('is_read', false)->count(),
                'campaign_replies' => Email::where('is_campaign_reply', true)->count(),
                'open_rate' => $sent > 0 ? round(((int) $sendTotals->opens / $sent) * 100, 1) : 0.0,
                'click_rate' => $sent > 0 ? round(((int) $sendTotals->clicks / $sent) * 100, 1) : 0.0,
            ],
            'recentCampaigns' => Campaign::latest()->limit(5)->get(),
            'recentEmails' => Email::where('direction', 'incoming')->latest('received_at')->limit(5)->get(),
        ]);
    }
}
