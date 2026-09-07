<?php

namespace App\Http\Controllers\Automation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\AutomationStepRequest;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationStep;
use App\Models\Campaign;
use App\Models\EmailTemplate;
use App\Models\SubscriberList;
use App\Models\Tag;
use App\Services\Campaigns\BlockCatalogue;
use App\Services\Campaigns\EmailCompiler;
use App\Services\Campaigns\PersonalizationEngine;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The steps of one automation.
 *
 * ── Order is a number, not a list position ──────────────────────────────────
 * AutomationRunner::nextStepId() finds the next step with
 * `position > current, ORDER BY position, id`. So the builder's job on reorder
 * is to write `position` — reordering rows in the UI without writing it would
 * show one sequence and run another, which is the worst kind of wrong here
 * because the difference only appears in somebody's inbox days later.
 *
 * ── Deleting a step does not strand anybody, but it does cut them short ─────
 * `automation_runs.current_step_id` is nullOnDelete, and when the runner finds
 * no step it calls complete(). So contacts parked on a deleted step are marked
 * completed on the next tick and never receive the steps that came after it.
 * That is a defensible behaviour and a terrible surprise, so the number of
 * affected contacts is shown on the button before it is pressed and again in
 * the message afterwards.
 */
class AutomationStepController extends Controller
{
    /**
     * Every step type, with the words used for it on screen.
     *
     * The `runs` line is what the step does to the RUN, not to the contact —
     * that is the part people get wrong, and it is the part that decides
     * whether anybody further down the sequence ever gets an email.
     *
     * @var array<string, array{label: string, summary: string, runs: string}>
     */
    public const TYPES = [
        'send_email' => [
            'label' => 'Send an email',
            'summary' => 'Sends one email to the contact.',
            'runs' => 'The run carries on to the next step once the message has been handed to the SMTP server.',
        ],
        'wait' => [
            'label' => 'Wait',
            'summary' => 'Pauses this contact for a set amount of time.',
            'runs' => 'The run sleeps until the time is up, then continues. Waits are capped at about a year.',
        ],
        'condition' => [
            'label' => 'Check something',
            'summary' => 'Asks a yes/no question about the contact.',
            'runs' => 'Yes carries on. No either ends the run or skips the single step that follows.',
        ],
        'add_tag' => [
            'label' => 'Add a tag',
            'summary' => 'Puts a tag on the contact.',
            'runs' => 'Instant — the run continues to the next step in the same tick.',
        ],
        'remove_tag' => [
            'label' => 'Remove a tag',
            'summary' => 'Takes a tag off the contact.',
            'runs' => 'Instant — the run continues to the next step in the same tick.',
        ],
        'move_list' => [
            'label' => 'Move between lists',
            'summary' => 'Adds the contact to a list, optionally removing them from another.',
            'runs' => 'Instant — the run continues to the next step in the same tick.',
        ],
        'unsubscribe' => [
            'label' => 'Unsubscribe',
            'summary' => 'Opts the contact out of all email from this account.',
            'runs' => 'This ends the run. Nothing after this step is ever reached.',
        ],
    ];

    public function __construct(
        protected EmailCompiler $compiler,
        protected BlockCatalogue $catalogue,
        protected PersonalizationEngine $personalization,
    ) {}

    public function create(Request $request, Automation $automation): View
    {
        $type = $request->filter('type');
        $type = is_string($type) && isset(self::TYPES[$type]) ? $type : 'send_email';

        return view('automations.step', $this->formData($request, $automation, new AutomationStep([
            'type' => $type,
            'position' => $this->nextPosition($automation),
            'config' => $type === 'wait' ? ['amount' => 1, 'unit' => 'days'] : [],
        ])));
    }

    public function store(AutomationStepRequest $request, Automation $automation): RedirectResponse
    {
        $step = AutomationStep::create(array_merge($this->attributes($request), [
            'automation_id' => $automation->id,
            'position' => $this->nextPosition($automation),
        ]));

        ActivityLogger::log('automation.step.created',
            "Added a {$step->type} step to {$automation->name}", [], $automation);

        return to_route('automations.show', $automation)
            ->with('success', 'Step added at position '.$step->position.'.');
    }

    public function edit(Request $request, Automation $automation, AutomationStep $step): View
    {
        return view('automations.step', $this->formData($request, $automation, $step));
    }

    public function update(AutomationStepRequest $request, Automation $automation, AutomationStep $step): RedirectResponse
    {
        $waiting = AutomationRun::query()
            ->where('automation_id', $automation->id)
            ->where('current_step_id', $step->id)
            ->whereIn('status', ['waiting', 'running'])
            ->count();

        $step->update($this->attributes($request));

        ActivityLogger::log('automation.step.updated',
            "Edited step {$step->position} of {$automation->name}", [], $automation);

        return to_route('automations.show', $automation)->with('success', $waiting === 0
            ? 'Step saved.'
            : 'Step saved. '.number_format($waiting).' contact(s) are waiting on this step right now and will '
                .'get the new version when they reach it.');
    }

    /**
     * Writes `position` for every step in one go.
     *
     * The whole order is posted rather than a "move this one up" instruction,
     * so two people reordering at once cannot interleave into a sequence
     * neither of them chose — the last save wins outright, which is at least
     * a sequence somebody actually looked at.
     */
    public function reorder(Request $request, Automation $automation): RedirectResponse
    {
        $ids = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ])['order'];

        $own = $automation->steps()->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Only this automation's steps, each one once, in the order given.
        $ordered = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id) => in_array($id, $own, true)
        )));

        // Anything the post left out keeps its place at the end, so a stale
        // form cannot silently drop a step out of the sequence.
        foreach ($own as $id) {
            if (! in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }

        foreach ($ordered as $index => $id) {
            AutomationStep::query()
                ->where('automation_id', $automation->id)
                ->whereKey($id)
                ->update(['position' => $index + 1, 'updated_at' => now()]);
        }

        return to_route('automations.show', $automation)->with('success', 'Order saved.');
    }

    public function destroy(Automation $automation, AutomationStep $step): RedirectResponse
    {
        $stranded = AutomationRun::query()
            ->where('automation_id', $automation->id)
            ->where('current_step_id', $step->id)
            ->whereIn('status', ['waiting', 'running'])
            ->count();

        $position = $step->position;
        $step->delete();

        // Positions are closed up so the remaining steps read 1, 2, 3 rather
        // than 1, 3, 4. The runner only compares positions, so the gap would
        // have run identically — but a builder that shows "step 4" of three
        // steps is a builder nobody trusts.
        foreach ($automation->steps()->orderBy('position')->orderBy('id')->get() as $index => $remaining) {
            $remaining->forceFill(['position' => $index + 1])->save();
        }

        ActivityLogger::log('automation.step.deleted',
            "Deleted step {$position} of {$automation->name}", [], $automation);

        return to_route('automations.show', $automation)->with($stranded === 0 ? 'success' : 'warning',
            $stranded === 0
                ? 'Step deleted and the remaining steps renumbered.'
                : 'Step deleted. '.number_format($stranded).' contact(s) were waiting on it: the runner finds no '
                    .'step there now, so it marks their run completed on the next pass. They will not receive the '
                    .'steps that came after it.');
    }

    // =============================================================== helpers

    /**
     * The columns to write for a step.
     *
     * `html` and `plain_text` are compiled here rather than trusted from the
     * browser: the builder posts a block document, and what the runner sends
     * has to be the compiled result of the document that was saved, not a
     * separate string that can drift away from it.
     *
     * @return array<string, mixed>
     */
    protected function attributes(AutomationStepRequest $request): array
    {
        $type = $request->stepType();
        $config = $request->config();

        if ($type === 'send_email') {
            $document = $this->catalogue->normalise($request->document());

            $config['blocks'] = $document;
            $config['html'] = $this->compiler->compile($document);
            $config['plain_text'] = $this->compiler->compileText($document);
        }

        return [
            'type' => $type,
            'label' => $request->validated()['label'] ?? null,
            'config' => $config,
            /*
             * Always cleared.
             *
             * The builder takes its own copy of the blocks, exactly as the
             * campaign editor does, and compiles them above — so the step no
             * longer needs the template, and keeping the link would be worse
             * than useless: AutomationRunner::compose() falls back to the
             * template's subject and html, so an edit to that template months
             * later would silently change what this step sends. A step opened
             * in this editor is self-contained from the moment it is saved,
             * and formData() below loads a template-backed step's content into
             * the builder first so nothing is lost in the conversion.
             */
            'email_template_id' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(Request $request, Automation $automation, AutomationStep $step): array
    {
        $step->loadMissing('template');

        $config = (array) ($step->config ?? []);

        /*
         * A step that was built here carries its own block document. One that
         * was created another way may carry only a template id, and opening it
         * in the builder has to show that template's content — otherwise the
         * first save would replace the email with an empty starter document
         * and nobody would know until it went out.
         */
        $source = match (true) {
            is_array($config['blocks'] ?? null) => $config['blocks'],
            is_array($step->template?->blocks) => $step->template->blocks,
            default => $this->starterDocument($request),
        };

        $document = $this->catalogue->normalise($source);

        return [
            'automation' => $automation,
            'step' => $step,
            'config' => $config,
            'document' => $document,
            'blockTypes' => $this->catalogue->forUi(),
            'tokens' => $this->personalization->catalogue(),
            'stepTypes' => self::TYPES,
            'templates' => EmailTemplate::active()->orderByDesc('is_system')->orderBy('name')->get(['id', 'name', 'is_system']),
            // Loading a template's blocks into the builder reads
            // templates.document, which is gated on its own set of slugs. A
            // role that can manage automations but not templates or campaigns
            // simply does not get the picker, rather than getting one that
            // answers 403.
            'canLoadTemplate' => (bool) collect(['templates.view', 'campaigns.create', 'campaigns.update'])
                ->first(fn (string $slug) => $request->user()->hasPermission($slug)),
            // Whether this step still points at a template. Shown once, because
            // saving here converts it to its own copy.
            'fromTemplate' => $step->exists && ! is_array($config['blocks'] ?? null) ? $step->template : null,
            'lists' => SubscriberList::query()->orderBy('name')->get(['id', 'name', 'active_count']),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name', 'subscribers_count']),
            'campaigns' => Campaign::query()->orderByDesc('id')->get(['id', 'name', 'status']),
            // The live preview in the block builder posts to templates.compile,
            // which is gated on the template and campaign permissions. A role
            // that can manage automations but not campaigns still saves and
            // sends correctly — only the preview would answer 403 — so the view
            // says so rather than letting it look broken.
            'canPreview' => (bool) collect(['templates.view', 'campaigns.view', 'campaigns.create', 'campaigns.update'])
                ->first(fn (string $slug) => $request->user()->hasPermission($slug)),
            'stranded' => $step->exists
                ? AutomationRun::query()
                    ->where('automation_id', $automation->id)
                    ->where('current_step_id', $step->id)
                    ->whereIn('status', ['waiting', 'running'])
                    ->count()
                : 0,
        ];
    }

    /**
     * What a brand new email step starts with.
     *
     * A footer block is included from the start because it carries the
     * unsubscribe link. AutomationRunner adds the List-Unsubscribe headers
     * whatever the content is, but the visible link is what most readers
     * actually use, and starting without one means most steps ship without one.
     *
     * @return array<string, mixed>
     */
    protected function starterDocument(Request $request): array
    {
        return [
            'settings' => [],
            'blocks' => [
                ['id' => 'b1', 'type' => 'heading', 'settings' => ['text' => 'Hello {{first_name|there}}']],
                ['id' => 'b2', 'type' => 'text', 'settings' => ['html' => '<p>Write your message here.</p>']],
                ['id' => 'b3', 'type' => 'footer', 'settings' => [
                    'companyLine' => (string) ($request->user()->account?->company_name ?: $request->user()->account?->name ?: ''),
                ]],
            ],
        ];
    }

    protected function nextPosition(Automation $automation): int
    {
        return ((int) $automation->steps()->max('position')) + 1;
    }
}
