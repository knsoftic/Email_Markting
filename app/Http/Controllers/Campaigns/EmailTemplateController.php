<?php

namespace App\Http\Controllers\Campaigns;

use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\EmailTemplateRequest;
use App\Models\EmailTemplate;
use App\Models\Subscriber;
use App\Services\Campaigns\BlockCatalogue;
use App\Services\Campaigns\EmailCompiler;
use App\Services\Campaigns\PersonalizationEngine;
use App\Support\ActivityLogger;
use App\Support\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class EmailTemplateController extends Controller
{
    public function __construct(
        protected EmailCompiler $compiler,
        protected BlockCatalogue $catalogue,
        protected PersonalizationEngine $personalization,
    ) {}

    public function index(Request $request): View
    {
        $limits = PlanLimits::for($request->user()->account);

        // filter() rather than $request->string(): ?q[]=x hands the latter an
        // array, and "Array to string conversion" is a warning Laravel
        // promotes to a 500. See AppServiceProvider::registerRequestMacros().
        $term = $request->filter('q') ?? '';
        $category = $request->filter('category') ?? '';

        return view('templates.index', [
            'templates' => EmailTemplate::query()
                ->when($term, fn ($query) => $query->where('name', 'like', '%'.$term.'%'))
                ->when($category, fn ($query) => $query->where('category', $category))
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->paginate(24)
                ->withQueryString(),
            'filters' => ['q' => $term, 'category' => $category],
            'templateLimit' => $limits->limit('max_templates'),
            'templatesUsed' => $limits->usageFor('max_templates'),
            'builderAllowed' => $limits->allows('allow_template_builder'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->assertMayAdd($request);

        return view('templates.edit', $this->builderData(new EmailTemplate([
            'category' => 'general',
            'blocks' => $this->starterDocument($request),
        ])));
    }

    public function store(EmailTemplateRequest $request): RedirectResponse
    {
        $this->assertMayAdd($request);

        $template = EmailTemplate::create($this->compiled($request));

        ActivityLogger::log('template.created', "Created template {$template->name}", [], $template);

        return to_route('templates.edit', $template)->with('success', 'Template saved.');
    }

    public function edit(EmailTemplate $template): View
    {
        // A system template is readable by every account but only a super
        // admin may change it, so it opens as a starting point for a copy.
        abort_if($template->is_system, 403, 'System templates are read-only. Duplicate it to make changes.');

        $this->assertOwned($template);

        return view('templates.edit', $this->builderData($template));
    }

    public function update(EmailTemplateRequest $request, EmailTemplate $template): RedirectResponse
    {
        $this->assertOwned($template);

        $template->update($this->compiled($request));

        ActivityLogger::log('template.updated', "Updated template {$template->name}", [], $template);

        return back()->with('success', 'Template saved.');
    }

    /**
     * The rendered email, filled with a real contact so the preview shows what
     * a recipient would actually receive rather than raw placeholders.
     *
     * Served as a standalone document and embedded in a sandboxed iframe by
     * the builder — the email HTML must never share a DOM with the app.
     */
    public function preview(Request $request, EmailTemplate $template): Response
    {
        $this->assertVisible($template);

        $sample = Subscriber::query()->mailable()->first();

        $html = $this->personalization->render((string) $template->html, $sample);

        // frame-ancestors, NOT X-Frame-Options: this response is embedded in a
        // sandbox="" iframe, and a sandboxed frame has an opaque origin — so
        // "SAMEORIGIN" can never match and the browser refuses to render it at
        // all. frame-ancestors is evaluated against the EMBEDDING page's URL,
        // which is this app, so it gives the same clickjacking protection and
        // still works inside the sandbox.
        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header(
                'Content-Security-Policy',
                "default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; frame-ancestors 'self'"
            )
            // The remote images in a preview are fetched by the browser, and
            // without this each request tells the image's host which page of
            // this application was open. The inbox body has always said so.
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * A template's block document as JSON, so the campaign builder can load a
     * template's content into itself.
     *
     * This is what makes the nine ready-made templates worth having: without
     * it, picking a template on a campaign records a link and nothing else,
     * and there is no path from a template to a sent email.
     *
     * It returns the normalised document, never the raw stored JSON — the same
     * coercion every other path goes through, so an old or hand-edited
     * document cannot arrive in the builder with a colour that is really a
     * style-tag breakout.
     */
    public function document(EmailTemplate $template): JsonResponse
    {
        $this->assertVisible($template);

        return response()->json([
            'name' => $template->name,
            'subject' => $template->subject,
            'document' => $this->catalogue->normalise($template->blocks),
        ]);
    }

    /** Live compile for the builder, without saving. */
    public function compile(Request $request): JsonResponse
    {
        $request->validate([
            'blocks' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
        ]);

        // Normalised, exactly as the save path does. Two reasons, and the
        // second is the one that matters: 'blocks' => 'array' says nothing
        // about what is INSIDE a block, so a setting can arrive as an array
        // where text belongs and the compiler's (string) cast turns that into
        // a 500 on every keystroke of the preview. And a preview compiled from
        // raw input would show something the saved document never produces.
        $document = $this->catalogue->normalise($request->only(['blocks', 'settings']));

        $html = $this->compiler->compile($document);
        $sample = Subscriber::query()->mailable()->first();

        return response()->json([
            'html' => $this->personalization->render($html, $sample),
            'text' => $this->compiler->compileText($document),
            // Surfaced so a typo is caught in the builder, not in 50,000 inboxes.
            'unknown_tokens' => $this->personalization->unknownTokens($html),
            'bytes' => strlen($html),
            // Whether a contact was actually found to fill the placeholders.
            // A brand-new account has none, and the preview caption must not
            // claim it is showing what a recipient would see when every
            // placeholder has in fact fallen back to its default text.
            'sampled' => $sample !== null,
            // Gmail clips a message past ~102 KB and hides the rest behind
            // "View entire message", which usually hides the unsubscribe link.
            'gmail_clip' => strlen($html) > 102000,
        ]);
    }

    public function duplicate(EmailTemplate $template, Request $request): RedirectResponse
    {
        $this->assertVisible($template);
        $this->assertMayAdd($request);

        $copy = EmailTemplate::create([
            'user_id' => $request->user()->id,
            'name' => $template->name.' (copy)',
            'subject' => $template->subject,
            'description' => $template->description,
            'category' => $template->category,
            'blocks' => $template->blocks,
            'html' => $template->html,
            'plain_text' => $template->plain_text,
            'is_system' => false,
        ]);

        ActivityLogger::log('template.duplicated', "Duplicated {$template->name}", [], $copy);

        return to_route('templates.edit', $copy)->with('success', 'Template duplicated.');
    }

    public function destroy(EmailTemplate $template): RedirectResponse
    {
        $this->assertOwned($template);

        $name = $template->name;
        $template->delete();

        ActivityLogger::log('template.deleted', "Deleted template {$name}");

        return to_route('templates.index')
            ->with('success', "\"{$name}\" deleted. Campaigns already built from it keep their content.");
    }

    // ----------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    protected function builderData(EmailTemplate $template): array
    {
        return [
            'template' => $template,
            'document' => $this->catalogue->normalise($template->blocks),
            'blockTypes' => $this->catalogue->forUi(),
            'tokens' => $this->personalization->catalogue(),
            'categories' => ['general', 'welcome', 'newsletter', 'promotion', 'product',
                'course', 'event', 'discount', 'announcement', 'follow-up'],
        ];
    }

    /**
     * Compilation happens on save so the list and the preview are instant, and
     * again at send time so a campaign ships what it was given.
     *
     * @return array<string, mixed>
     */
    protected function compiled(EmailTemplateRequest $request): array
    {
        $data = $request->validated();
        $document = ['settings' => $data['settings'] ?? [], 'blocks' => $data['blocks'] ?? []];

        return array_merge(
            collect($data)->except(['settings', 'blocks'])->all(),
            [
                'user_id' => $request->user()->id,
                'blocks' => $this->catalogue->normalise($document),
                'html' => $this->compiler->compile($document),
                'plain_text' => $this->compiler->compileText($document),
                'is_system' => false,
            ]
        );
    }

    /**
     * A new template starts with something real rather than a blank page —
     * including the footer, so the unsubscribe link is there by default.
     *
     * @return array<string, mixed>
     */
    protected function starterDocument(Request $request): array
    {
        $account = $request->user()->account;

        return ['settings' => [], 'blocks' => [
            ['id' => 'b1', 'type' => 'heading', 'settings' => ['text' => 'Hello {{first_name|there}}', 'align' => 'center']],
            ['id' => 'b2', 'type' => 'text', 'settings' => ['html' => '<p>Write your message here.</p>']],
            ['id' => 'b3', 'type' => 'button', 'settings' => ['text' => 'Read more', 'href' => 'https://example.com']],
            ['id' => 'b4', 'type' => 'footer', 'settings' => ['companyLine' => $account?->name ?? '']],
        ]];
    }

    protected function assertVisible(EmailTemplate $template): void
    {
        abort_unless(
            $template->is_system || $template->account_id === auth()->user()->account_id,
            404
        );
    }

    protected function assertOwned(EmailTemplate $template): void
    {
        abort_if($template->is_system, 403, 'System templates are read-only. Duplicate it to make changes.');
        abort_unless($template->account_id === auth()->user()->account_id, 404);
    }

    protected function assertMayAdd(Request $request): void
    {
        $limits = PlanLimits::for($request->user()->account);

        $limits->ensureFeature('allow_template_builder', 'the template builder');
        $limits->ensure('max_templates');
    }
}
