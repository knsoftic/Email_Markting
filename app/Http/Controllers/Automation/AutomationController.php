<?php

namespace App\Http\Controllers\Automation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\AutomationRequest;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationStep;
use App\Models\Campaign;
use App\Models\CampaignLink;
use App\Models\SubscriberList;
use App\Models\Tag;
use App\Services\Smtp\SmtpSelector;
use App\Support\ActivityLogger;
use App\Support\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * The automations module.
 *
 * ── Why activation is guarded rather than trusted ───────────────────────────
 * An active automation is invisible: it enrols people from the middle of a
 * contact import and mails them hours later, and the only place its state is
 * ever shown is this module. So an automation that CANNOT work must not be
 * allowed to look active. AutomationEnroller refuses to enrol into one with no
 * steps and AutomationSweeper quietly returns 0 for a date trigger with no
 * date — in both cases the screen would show "Active" beside a row that will
 * never do anything, and the operator would spend a week wondering why nobody
 * got the email. activate() answers with the reason instead.
 *
 * ── The counters on screen are the ones the backend actually writes ─────────
 * entered_count, completed_count and emails_sent are incremented by the
 * enroller and the runner. Everything else here — how many people are waiting
 * right now, what step they are on, why one failed — is read live from
 * automation_runs, because those are questions a stored counter cannot answer.
 */
class AutomationController extends Controller
{
    /**
     * The seven triggers, in the words used on screen. The enum values are
     * never printed: "campaign_not_opened" is not a sentence.
     *
     * @var array<string, array{label: string, help: string}>
     */
    public const TRIGGER_LABELS = [
        'subscriber_added' => [
            'label' => 'A contact is added',
            'help' => 'Fires when a contact is created — by a form, an import, or by hand. Narrow it to the lists they land on, or leave it open for any.',
        ],
        'tag_added' => [
            'label' => 'A tag is added',
            'help' => 'Fires when a tag is put on a contact who did not already have it. Leave the tag empty to listen for any tag.',
        ],
        'list_joined' => [
            'label' => 'A contact joins a list',
            'help' => 'Fires when a contact is added to a list. Leave the list empty to listen for any list.',
        ],
        'campaign_opened' => [
            'label' => 'A campaign is opened',
            'help' => 'Fires the first time a contact opens a campaign. Opening it again later changes nothing.',
        ],
        'link_clicked' => [
            'label' => 'A link is clicked',
            'help' => 'Fires when a contact clicks a tracked link. Choose a specific link to mean "clicked the pricing link" rather than "clicked anything".',
        ],
        'campaign_not_opened' => [
            'label' => 'A campaign is not opened',
            'help' => 'Swept on a schedule rather than fired by an event — nobody does the act of not opening something. Needs the campaign and a window in hours.',
        ],
        'specific_date' => [
            'label' => 'On a set date',
            'help' => 'Runs once, on the date and time you set, for everybody on the chosen lists. It marks itself completed afterwards.',
        ],
    ];

    public function __construct(protected SmtpSelector $selector) {}

    // ------------------------------------------------------------- listing

    public function index(Request $request): View
    {
        $filters = $request->filters(['q', 'status', 'trigger']);

        $automations = Automation::query()
            ->withCount('steps')
            ->withCount(['steps as send_steps_count' => fn ($q) => $q->where('type', 'send_email')])
            ->withMax('runs', 'updated_at')
            ->when($filters['q'], fn ($q, $term) => $q->where(function ($inner) use ($term) {
                $inner->where('name', 'like', '%'.$term.'%')
                    ->orWhere('description', 'like', '%'.$term.'%');
            }))
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($filters['trigger'], fn ($q, $t) => $q->where('trigger_type', $t))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $limits = PlanLimits::for($request->user()->account);
        $hasSmtp = $this->hasSendableSmtp($request);
        $triggerTargets = $this->triggerTargets($automations->getCollection());

        return view('automations.index', [
            'automations' => $automations,
            'filters' => $filters,
            'triggers' => $this->describeAll($automations->getCollection(), $triggerTargets),
            // Only the blockers a list screen can honestly see. The per-step
            // content check needs every step's body loaded, which is not
            // something to do twenty times for one table — activate() runs the
            // full check and sends the operator to the automation's own screen
            // with anything it finds.
            'blockers' => $automations->getCollection()
                ->mapWithKeys(fn (Automation $a) => [
                    $a->id => $this->shallowBlockers($a, $hasSmtp, targets: $triggerTargets),
                ]),
            'statusCounts' => Automation::query()
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')->pluck('aggregate', 'status'),
            'automationsAllowed' => $limits->allows('allow_automation'),
            'automationLimit' => $limits->limit('max_automations'),
            'automationsUsed' => $limits->usageFor('max_automations'),
            'triggerLabels' => self::TRIGGER_LABELS,
        ]);
    }

    // ------------------------------------------------------------ the form

    public function create(Request $request): View
    {
        $this->assertMayCreate($request);

        return view('automations.edit', $this->formData($request, new Automation([
            'trigger_type' => 'subscriber_added',
            'status' => 'draft',
            'from_name' => $request->user()->account?->name,
            'allow_reentry' => false,
        ])));
    }

    public function store(AutomationRequest $request): RedirectResponse
    {
        $this->assertMayCreate($request);

        $automation = Automation::create(array_merge($request->persistable(), [
            'user_id' => $request->user()->id,
            'status' => 'draft',
        ]));

        ActivityLogger::log('automation.created', "Created automation {$automation->name}", [], $automation);

        return to_route('automations.show', $automation)
            ->with('success', 'Saved as a draft. Add the steps, then activate it — nothing runs until you do.');
    }

    public function edit(Request $request, Automation $automation): View
    {
        return view('automations.edit', $this->formData($request, $automation));
    }

    public function update(AutomationRequest $request, Automation $automation): RedirectResponse
    {
        $automation->update($request->persistable());

        ActivityLogger::log('automation.updated', "Updated automation {$automation->name}", [], $automation);

        return to_route('automations.show', $automation)->with('success', 'Automation updated.');
    }

    // ---------------------------------------------------- the step builder

    public function show(Request $request, Automation $automation): View
    {
        $automation->loadMissing(['steps.template', 'smtpAccount']);

        $counts = $this->runCounts($automation);

        return view('automations.show', [
            'automation' => $automation,
            'trigger' => $this->describeAll(collect([$automation]))[$automation->id],
            'blockers' => $this->blockers($automation, $this->hasSendableSmtp($request)),
            'warnings' => $this->warnings($automation),
            'stepNotes' => $this->stepNotes($automation),
            'runCounts' => $counts,
            'liveRuns' => (int) ($counts['waiting'] ?? 0) + (int) ($counts['running'] ?? 0),
            // How many contacts are parked on each step right now. This is what
            // makes "deleting this step strands nobody, but it does end their
            // run early" a statement with a number behind it.
            'runsPerStep' => AutomationRun::query()
                ->where('automation_id', $automation->id)
                ->whereIn('status', ['waiting', 'running'])
                ->whereNotNull('current_step_id')
                ->selectRaw('current_step_id, COUNT(*) as aggregate')
                ->groupBy('current_step_id')
                ->pluck('aggregate', 'current_step_id'),
            'stepTypes' => AutomationStepController::TYPES,
            'triggerLabels' => self::TRIGGER_LABELS,
            'names' => $this->stepNames($automation),
        ]);
    }

    // ------------------------------------------------------- runs/activity

    public function runs(Request $request, Automation $automation): View
    {
        $filters = $request->filters(['q', 'status']);

        $runs = AutomationRun::query()
            ->where('automation_id', $automation->id)
            ->with(['subscriber:id,email,first_name,last_name,status', 'currentStep:id,position,type,label'])
            // 'live' is waiting OR running, because that is what the "In
            // progress" card on the previous screen counts. Sending its link
            // to status=waiting made the card say 3 and the screen it opened
            // show 1 — the card was right and its own link disagreed with it,
            // which is the fastest way to make every number on a report look
            // untrustworthy.
            ->when($filters['status'] === 'live',
                fn ($q) => $q->whereIn('status', ['waiting', 'running']),
                fn ($q) => $q->when($filters['status'], fn ($inner, $st) => $inner->where('status', $st)))
            ->when($filters['q'], fn ($q, $term) => $q->whereHas(
                'subscriber',
                fn ($inner) => $inner->where('email', 'like', '%'.$term.'%')
            ))
            ->orderByRaw("FIELD(status, 'failed', 'running', 'waiting', 'cancelled', 'completed')")
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString();

        return view('automations.runs', [
            'automation' => $automation,
            'runs' => $runs,
            'filters' => $filters,
            'runCounts' => $this->runCounts($automation),
            'stepCount' => $automation->steps()->count(),
            'stepTypes' => AutomationStepController::TYPES,
        ]);
    }

    // ---------------------------------------------------------- state

    public function activate(Request $request, Automation $automation): RedirectResponse
    {
        PlanLimits::for($request->user()->account)->ensureFeature('allow_automation', 'automations');

        $automation->loadMissing('steps.template');

        $blockers = $this->blockers($automation, $this->hasSendableSmtp($request));

        if ($blockers !== []) {
            // Sent to the automation's own screen rather than answered with a
            // one-line flash: the reasons are listed there in full, next to the
            // things that have to be fixed.
            return to_route('automations.show', $automation)->with('error', count($blockers) === 1
                ? 'This automation was not activated: '.$blockers[0]
                : 'This automation was not activated. '.count($blockers).' things have to be fixed first — they are listed below.');
        }

        $automation->forceFill(['status' => 'active'])->save();

        ActivityLogger::log('automation.activated', "Activated automation {$automation->name}", [], $automation);

        return back()->with('success', $automation->trigger_type === 'specific_date'
            ? 'Active. It runs once, on the date you set, and marks itself completed afterwards.'
            : 'Active. New contacts matching the trigger from now on will be entered.');
    }

    public function pause(Automation $automation): RedirectResponse
    {
        $automation->forceFill(['status' => 'paused'])->save();

        ActivityLogger::log('automation.paused', "Paused automation {$automation->name}", [], $automation);

        $live = $this->liveRunCount($automation);

        return back()->with('warning', $live === 0
            ? 'Paused. Nobody new will be entered while it is paused.'
            : 'Paused. '.number_format($live).' contact(s) stay exactly where they are and continue from the same '
                .'step when you activate it again. Email already sent cannot be recalled.');
    }

    public function duplicate(Request $request, Automation $automation): RedirectResponse
    {
        $this->assertMayCreate($request);

        $automation->loadMissing('steps');

        $copy = DB::transaction(function () use ($request, $automation) {
            $copy = Automation::create([
                'user_id' => $request->user()->id,
                'name' => mb_substr($automation->name.' (copy)', 0, 191),
                'description' => $automation->description,
                'trigger_type' => $automation->trigger_type,
                'trigger_config' => $automation->trigger_config,
                'smtp_account_id' => $automation->smtp_account_id,
                'from_name' => $automation->from_name,
                'from_email' => $automation->from_email,
                'allow_reentry' => $automation->allow_reentry,
                // A copy has entered nobody and sent nothing. Carrying the
                // originals' counters over would be a lie on the first screen
                // somebody looks at.
                'status' => 'draft',
            ]);

            foreach ($automation->steps as $step) {
                AutomationStep::create([
                    'automation_id' => $copy->id,
                    'position' => $step->position,
                    'type' => $step->type,
                    'label' => $step->label,
                    'config' => $step->config,
                    'email_template_id' => $step->email_template_id,
                ]);
            }

            return $copy;
        });

        ActivityLogger::log('automation.duplicated', "Duplicated {$automation->name}", [], $copy);

        return to_route('automations.show', $copy)
            ->with('success', 'Duplicated as a draft, with its steps. Nobody is entered until you activate it.');
    }

    public function destroy(Automation $automation): RedirectResponse
    {
        $name = $automation->name;

        $stopped = DB::transaction(function () use ($automation) {
            /*
             * Runs are cancelled before the automation goes.
             *
             * automations are soft-deleted, so the rows in automation_runs
             * survive — and AutomationRunner::advance() looks its automation up
             * with find(), which does not return trashed models. A live run
             * whose automation has been deleted therefore falls into the
             * "paused" branch and is parked for another fifteen minutes, over
             * and over, forever. Cancelling them here with a reason is both the
             * honest record and the thing that stops the runner picking them up
             * every quarter of an hour for the rest of the installation's life.
             */
            $stopped = AutomationRun::query()
                ->where('automation_id', $automation->id)
                ->whereIn('status', ['waiting', 'running'])
                ->update([
                    'status' => 'cancelled',
                    'completed_at' => now(),
                    'next_run_at' => null,
                    'last_error' => 'The automation was deleted.',
                    'updated_at' => now(),
                ]);

            $automation->delete();

            return $stopped;
        });

        ActivityLogger::log('automation.deleted', "Deleted automation {$name}");

        return to_route('automations.index')->with('success', $stopped === 0
            ? "\"{$name}\" deleted. Email it already sent stays in the logs."
            : "\"{$name}\" deleted. ".number_format($stopped).' contact(s) part-way through it were stopped. '
                .'Email it already sent stays in the logs.');
    }

    // ============================================================= readiness

    /**
     * Every reason this automation cannot be activated, in plain words.
     *
     * Each one is a thing that would make an "Active" badge untrue: either the
     * enroller will refuse to enrol anybody, the trigger can never match, or
     * the first send will throw the moment the runner reaches it.
     *
     * @return array<int, string>
     */
    protected function blockers(Automation $automation, bool $hasSmtp): array
    {
        $steps = $automation->relationLoaded('steps') ? $automation->steps : $automation->steps()->with('template')->get();

        $reasons = $this->shallowBlockers($automation, $hasSmtp, $steps->count(), $steps->where('type', 'send_email')->count());

        foreach ($steps->where('type', 'send_email') as $step) {
            $config = (array) ($step->config ?? []);
            $where = 'Step '.$step->position.' ("'.($step->label ?: 'Send an email').'")';

            if (trim((string) ($config['subject'] ?? '')) === '' && trim((string) ($step->template?->subject ?? '')) === '') {
                $reasons[] = $where.' has no subject line, so the send would fail when the runner reached it.';
            }

            if (trim(strip_tags((string) ($config['html'] ?? ''))) === ''
                && trim(strip_tags((string) ($step->template?->html ?? ''))) === '') {
                $reasons[] = $where.' has no content, so the send would fail when the runner reached it.';
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * The blockers that can be seen without reading every step's body.
     *
     * @return array<int, string>
     */
    protected function shallowBlockers(Automation $automation, bool $hasSmtp, ?int $stepCount = null, ?int $sendCount = null, ?array $targets = null): array
    {
        $stepCount ??= (int) ($automation->steps_count ?? $automation->steps()->count());
        $sendCount ??= (int) ($automation->send_steps_count ?? $automation->steps()->where('type', 'send_email')->count());

        $reasons = [];

        if ($stepCount === 0) {
            // AutomationEnroller::enrol() returns null for an automation with
            // no steps, so this one would sit there "Active" and enrol nobody.
            $reasons[] = 'It has no steps yet, so there is nothing for a contact to be entered into.';
        }

        $config = (array) ($automation->trigger_config ?? []);

        $reasons = array_merge($reasons, $this->triggerBlockers($automation, $config, $targets));

        if ($sendCount > 0
            && trim((string) $automation->from_email) === ''
            && ! $automation->smtp_account_id
            && ! $hasSmtp) {
            $reasons[] = 'It sends email but has no "from" address, and there is no SMTP account to take one from. '
                .'Set a from address on the automation, or add an SMTP account.';
        }

        return $reasons;
    }

    /**
     * Trigger config that cannot do what the trigger promises.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    protected function triggerBlockers(Automation $automation, array $config, ?array $targets = null): array
    {
        $reasons = [];

        /*
         * On the list screen the caller has already looked every target up in
         * one query per kind, so this reads the map. On a single automation
         * (activate, show) there is no map and one query is the cheaper answer
         * than four. Same question either way.
         */
        $exists = function (string $kind, string $model, int $id) use ($targets): bool {
            if ($targets !== null) {
                return $targets[$kind]->has($id);
            }

            return $model::query()->whereKey($id)->exists();
        };

        /*
         * AutomationTrigger::idMatches() compares the id in the config with the
         * id in the event. A deleted tag can never be added to anybody, so an
         * automation watching one can never fire — and it would sit "Active"
         * forever with nothing whatever to show for it.
         */
        $gone = function (int $id, string $noun) use (&$reasons) {
            $reasons[] = 'The '.$noun.' this trigger watches (#'.$id.') no longer exists, '
                .'so nothing can ever start this automation. Pick another one.';
        };

        switch ($automation->trigger_type) {
            case 'tag_added':
                $id = (int) ($config['tag_id'] ?? 0);
                if ($id > 0 && ! $exists('tags', Tag::class, $id)) {
                    $gone($id, 'tag');
                }
                break;

            case 'list_joined':
                $id = (int) ($config['list_id'] ?? 0);
                if ($id > 0 && ! $exists('lists', SubscriberList::class, $id)) {
                    $gone($id, 'list');
                }
                break;

            case 'campaign_opened':
            case 'link_clicked':
                $id = (int) ($config['campaign_id'] ?? 0);
                if ($id > 0 && ! $exists('campaigns', Campaign::class, $id)) {
                    $gone($id, 'campaign');
                }
                break;

            case 'campaign_not_opened':
                $id = (int) ($config['campaign_id'] ?? 0);

                if ($id === 0) {
                    $reasons[] = 'The trigger has no campaign. "Did not open" needs to name the email that was '
                        .'not opened, so this one can never enrol anybody.';
                } elseif (! $exists('campaigns', Campaign::class, $id)) {
                    $gone($id, 'campaign');
                }
                break;

            case 'specific_date':
                if (trim((string) ($config['date'] ?? '')) === '') {
                    $reasons[] = 'The trigger has no date, so the sweep has nothing to compare against and will '
                        .'never enrol anybody.';
                }
                break;
        }

        return $reasons;
    }

    /**
     * Things worth saying that are not reasons to refuse activation.
     *
     * @return array<int, string>
     */
    protected function warnings(Automation $automation): array
    {
        $warnings = [];
        $config = (array) ($automation->trigger_config ?? []);
        $steps = $automation->relationLoaded('steps') ? $automation->steps : $automation->steps()->get();

        if ($automation->trigger_type === 'specific_date' && ($config['date'] ?? '') !== '') {
            $when = $this->parseDate($automation, $config);

            if ($when !== null && $when->isPast()) {
                $warnings[] = 'That date has already passed. Activating now enrols everybody who matches straight '
                    .'away, in one sweep, rather than waiting.';
            }
        }

        if ($automation->trigger_type === 'campaign_not_opened') {
            $hours = max(1, (int) ($config['after_hours'] ?? 48));
            $warnings[] = 'The window is measured from each contact\'s own delivery time, not from when the campaign '
                .'finished sending, so everybody gets the same '.$hours.' hour(s) of grace.';
        }

        if ($steps->where('type', 'send_email')->isEmpty() && $steps->isNotEmpty()) {
            $warnings[] = 'There is no email step, so this automation will not send anything. That is fine if it '
                .'only exists to tag or move contacts.';
        }

        if ($automation->allow_reentry) {
            $warnings[] = 'Re-entry is on: a contact who has finished this automation can be entered again by a new '
                .'trigger event. A contact who is still part-way through is never restarted.';
        }

        if ($steps->isNotEmpty() && $steps->first()?->type === 'send_email') {
            $warnings[] = 'The first step sends immediately on entry — there is no delay before it. Add a wait step '
                .'first if that is not what you want.';
        }

        return $warnings;
    }

    /**
     * Per-step problems that do not stop activation but change what a step does.
     *
     * Keyed by step id so the builder can print each one beside its step.
     *
     * @return array<int, array<int, string>>
     */
    protected function stepNotes(Automation $automation): array
    {
        $notes = [];

        foreach ($automation->steps as $step) {
            $config = (array) ($step->config ?? []);
            $problems = [];

            $tagId = (int) ($config['tag_id'] ?? 0);
            $listId = (int) ($config['list_id'] ?? 0);
            $fromListId = (int) ($config['from_list_id'] ?? 0);
            $campaignId = (int) ($config['campaign_id'] ?? 0);

            $tagGone = $tagId > 0 && ! Tag::query()->whereKey($tagId)->exists();
            $listGone = $listId > 0 && ! SubscriberList::query()->whereKey($listId)->exists();

            if (in_array($step->type, ['add_tag', 'remove_tag'], true) && $tagGone) {
                $problems[] = 'That tag has been deleted. The runner steps straight over this and carries on.';
            }

            if ($step->type === 'move_list') {
                if ($listGone) {
                    $problems[] = 'The destination list has been deleted. The runner steps straight over this — '
                        .'nobody is moved, and nobody is removed from the old list either.';
                }

                if ($fromListId > 0 && ! SubscriberList::query()->whereKey($fromListId)->exists()) {
                    $problems[] = 'The list to remove people from has been deleted, so only the destination list '
                        .'is applied.';
                }
            }

            if ($step->type === 'condition') {
                if (($config['check'] ?? '') === 'has_tag' && $tagGone) {
                    $problems[] = 'That tag has been deleted, so nobody can have it. This check is always false.';
                }

                if (($config['check'] ?? '') === 'on_list' && $listGone) {
                    $problems[] = 'That list has been deleted, so nobody can be on it. This check is always false.';
                }

                if (in_array($config['check'] ?? '', ['opened_campaign', 'clicked_campaign'], true)
                    && $campaignId > 0
                    && ! Campaign::query()->whereKey($campaignId)->exists()) {
                    $problems[] = 'That campaign has been deleted. This check is always false.';
                }
            }

            if ($step->type === 'send_email') {
                $config = (array) ($step->config ?? []);

                if (trim((string) ($config['subject'] ?? '')) === '' && trim((string) ($step->template?->subject ?? '')) === '') {
                    $problems[] = 'No subject line. The runner fails the run here rather than sending.';
                }

                if (trim(strip_tags((string) ($config['html'] ?? ''))) === ''
                    && trim(strip_tags((string) ($step->template?->html ?? ''))) === '') {
                    $problems[] = 'No content. The runner fails the run here rather than sending.';
                }
            }

            if ($problems !== []) {
                $notes[$step->id] = $problems;
            }
        }

        return $notes;
    }

    // ========================================================== descriptions

    /**
     * The trigger of every automation in the collection, in plain words.
     *
     * Names are looked up in three queries for the whole page rather than one
     * per row, and a missing name is said out loud rather than printed as a
     * bare id — an automation pointing at a deleted tag can never fire, and
     * that is the single most useful thing this column can tell anybody.
     *
     * @param  Collection<int, Automation>  $automations
     * @return array<int, array{summary: string, detail: string}>
     */
    /**
     * Every tag, list, campaign and link the given automations' triggers name,
     * looked up in four queries rather than one per row.
     *
     * Both the trigger descriptions and the "this can never fire" blockers ask
     * the same question of the same ids, so they ask it once. Before this, a
     * page of twenty automations that each watched a tag cost twenty extra
     * EXISTS queries — a screen that got slower the more the customer used it.
     *
     * @param  Collection<int, Automation>  $automations
     * @return array{tags: \Illuminate\Support\Collection, lists: \Illuminate\Support\Collection, campaigns: \Illuminate\Support\Collection, links: \Illuminate\Support\Collection}
     */
    protected function triggerTargets(Collection $automations): array
    {
        $configs = $automations->map(fn (Automation $a) => (array) ($a->trigger_config ?? []));

        $ids = function (string $key) use ($configs) {
            return $configs->flatMap(fn (array $c) => array_map('intval', (array) ($c[$key] ?? [])))
                ->filter()->unique()->values()->all();
        };

        return [
            'tags' => Tag::query()->whereIn('id', $ids('tag_id'))->pluck('name', 'id'),
            'lists' => SubscriberList::query()
                ->whereIn('id', array_merge($ids('list_id'), $ids('list_ids')))
                ->pluck('name', 'id'),
            'campaigns' => Campaign::query()->whereIn('id', $ids('campaign_id'))->pluck('name', 'id'),
            'links' => CampaignLink::query()->whereIn('id', $ids('link_id'))->pluck('url', 'id'),
        ];
    }

    protected function describeAll(Collection $automations, ?array $targets = null): array
    {
        // Reuse the caller's lookup when it has one — the list screen builds it
        // for the blockers, and looking the same ids up twice for one page
        // would undo half the point of batching them.
        $targets ??= $this->triggerTargets($automations);

        $tagNames = $targets['tags'];
        $listNames = $targets['lists'];
        $campaignNames = $targets['campaigns'];
        $linkUrls = $targets['links'];

        $out = [];

        foreach ($automations as $automation) {
            $c = (array) ($automation->trigger_config ?? []);

            $name = function ($lookup, string $key, string $noun) use ($c) {
                $id = (int) ($c[$key] ?? 0);

                if ($id === 0) {
                    return null;
                }

                return $lookup->has($id) ? '"'.$lookup[$id].'"' : 'a '.$noun.' that has since been deleted';
            };

            $lists = collect(array_map('intval', (array) ($c['list_ids'] ?? [])))
                ->filter()
                ->map(fn (int $id) => $listNames->has($id) ? '"'.$listNames[$id].'"' : 'a deleted list')
                ->all();

            $listPhrase = $lists === [] ? null : implode(', ', $lists);

            $out[$automation->id] = match ($automation->trigger_type) {
                'subscriber_added' => [
                    'summary' => 'A contact is added',
                    'detail' => $listPhrase
                        ? 'When a contact is created and put on '.$listPhrase.'.'
                        : 'When a contact is created, whatever list they land on.',
                ],

                'tag_added' => [
                    'summary' => 'A tag is added',
                    'detail' => ($tag = $name($tagNames, 'tag_id', 'tag'))
                        ? 'When the tag '.$tag.' is added to a contact.'
                        : 'When any tag is added to a contact.',
                ],

                'list_joined' => [
                    'summary' => 'A contact joins a list',
                    'detail' => ($list = $name($listNames, 'list_id', 'list'))
                        ? 'When a contact is put on '.$list.'.'
                        : 'When a contact is put on any list.',
                ],

                'campaign_opened' => [
                    'summary' => 'A campaign is opened',
                    'detail' => ($campaign = $name($campaignNames, 'campaign_id', 'campaign'))
                        ? 'The first time a contact opens '.$campaign.'.'
                        : 'The first time a contact opens any campaign.',
                ],

                'link_clicked' => [
                    'summary' => 'A link is clicked',
                    'detail' => 'When a contact clicks '
                        .(($link = $name($linkUrls, 'link_id', 'link')) ? $link : 'any link')
                        .' in '
                        .(($campaign = $name($campaignNames, 'campaign_id', 'campaign')) ? $campaign : 'any campaign')
                        .'.',
                ],

                'campaign_not_opened' => [
                    'summary' => 'A campaign is not opened',
                    'detail' => ($campaign = $name($campaignNames, 'campaign_id', 'campaign'))
                        ? max(1, (int) ($c['after_hours'] ?? 48)).' hour(s) after '.$campaign
                            .' reached a contact, if they still have not opened it.'
                        : 'No campaign chosen yet, so this cannot enrol anybody.',
                ],

                'specific_date' => [
                    'summary' => 'On a set date',
                    'detail' => trim((string) ($c['date'] ?? '')) === ''
                        ? 'No date chosen yet, so this cannot enrol anybody.'
                        : 'Once, on '.$c['date'].' at '.($c['time'] ?? '00:00').', for '
                            .($listPhrase ? 'every active contact on '.$listPhrase : 'every active contact').'.',
                ],

                default => ['summary' => 'Unknown trigger', 'detail' => 'This trigger is not one the runner knows.'],
            };
        }

        return $out;
    }

    // =============================================================== helpers

    /**
     * @return array<string, mixed>
     */
    protected function formData(Request $request, Automation $automation): array
    {
        $config = (array) ($automation->trigger_config ?? []);

        $campaigns = Campaign::query()->orderByDesc('id')->get(['id', 'name', 'status']);

        return [
            'automation' => $automation,
            'config' => $config,
            'lists' => SubscriberList::query()->orderBy('name')->get(['id', 'name', 'active_count']),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name', 'subscribers_count']),
            'campaigns' => $campaigns,
            // Links only exist for campaigns that were actually built with
            // tracked links, so the picker is populated per campaign and the
            // view says so where a campaign has none.
            'links' => CampaignLink::query()
                ->whereIn('campaign_id', $campaigns->pluck('id'))
                ->orderBy('campaign_id')
                ->get(['id', 'campaign_id', 'url'])
                ->groupBy('campaign_id')
                ->map(fn ($group) => $group->map(fn ($link) => [
                    'id' => (int) $link->id,
                    'url' => (string) $link->url,
                ])->values()),
            'smtpAccounts' => $this->selector->candidatesFor($request->user()->account),
            'timezone' => $request->user()->account?->timezone ?: config('app.timezone'),
            'triggerLabels' => self::TRIGGER_LABELS,
        ];
    }

    /**
     * @return Collection<string, int>
     */
    protected function runCounts(Automation $automation): Collection
    {
        return AutomationRun::query()
            ->where('automation_id', $automation->id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($value) => (int) $value);
    }

    /**
     * The tag, list and campaign names every step refers to, in three queries
     * for the whole builder rather than one per step.
     *
     * @return array{tags: Collection<int, string>, lists: Collection<int, string>, campaigns: Collection<int, string>}
     */
    protected function stepNames(Automation $automation): array
    {
        $ids = function (array $keys) use ($automation) {
            $out = [];

            foreach ($automation->steps as $step) {
                foreach ($keys as $key) {
                    if (($id = (int) (((array) ($step->config ?? []))[$key] ?? 0)) > 0) {
                        $out[] = $id;
                    }
                }
            }

            return array_values(array_unique($out));
        };

        return [
            'tags' => Tag::query()->whereIn('id', $ids(['tag_id']))->pluck('name', 'id'),
            'lists' => SubscriberList::query()->whereIn('id', $ids(['list_id', 'from_list_id']))->pluck('name', 'id'),
            'campaigns' => Campaign::query()->whereIn('id', $ids(['campaign_id']))->pluck('name', 'id'),
        ];
    }

    protected function liveRunCount(Automation $automation): int
    {
        return AutomationRun::query()
            ->where('automation_id', $automation->id)
            ->whereIn('status', ['waiting', 'running'])
            ->count();
    }

    protected function hasSendableSmtp(Request $request): bool
    {
        $account = $request->user()?->account;

        return $account !== null && $this->selector->candidatesFor($account)->isNotEmpty();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function parseDate(Automation $automation, array $config): ?Carbon
    {
        try {
            return Carbon::parse(
                trim((string) $config['date']).' '.trim((string) ($config['time'] ?? '00:00')),
                $automation->account?->timezone ?: config('app.timezone')
            );
        } catch (Throwable) {
            return null;
        }
    }

    protected function assertMayCreate(Request $request): void
    {
        $limits = PlanLimits::for($request->user()->account);

        $limits->ensureFeature('allow_automation', 'automations');
        $limits->ensure('max_automations');
    }
}
