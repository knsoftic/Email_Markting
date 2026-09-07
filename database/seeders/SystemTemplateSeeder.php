<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Services\Campaigns\BlockCatalogue;
use App\Services\Campaigns\EmailCompiler;
use Illuminate\Database\Seeder;

/**
 * The ready-made templates every account starts with.
 *
 * They are platform-owned (account_id = null, is_system = true): readable by
 * every tenant, editable by none — a tenant duplicates one and edits the copy.
 * EmailTemplate::accountScopeIncludesGlobal() is what lets them through the
 * tenant scope.
 *
 * Each document is compiled here, at seed time, by the same EmailCompiler that
 * builds a campaign, so a system template can never contain HTML the compiler
 * would not produce. Re-running the seeder recompiles them, which is how a
 * change to the compiler reaches templates that already exist.
 *
 * Every one of them carries a footer block. That is not decoration: the footer
 * is where the unsubscribe link comes from, and CampaignDispatcher refuses to
 * send content without one.
 */
class SystemTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $compiler = app(EmailCompiler::class);
        $catalogue = app(BlockCatalogue::class);

        foreach ($this->templates() as $definition) {
            $document = $catalogue->normalise([
                'settings' => $definition['settings'] ?? [],
                'blocks' => $definition['blocks'],
            ]);

            EmailTemplate::withoutGlobalScopes()->updateOrCreate(
                ['account_id' => null, 'is_system' => true, 'name' => $definition['name']],
                [
                    'subject' => $definition['subject'],
                    'description' => $definition['description'],
                    'category' => $definition['category'],
                    'blocks' => $document,
                    'html' => $compiler->compile($document),
                    'plain_text' => $compiler->compileText($document),
                    'is_active' => true,
                    'user_id' => null,
                ]
            );
        }
    }

    // ------------------------------------------------------------ building

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function block(string $type, array $settings = []): array
    {
        return ['id' => 'b'.substr(md5($type.serialize($settings)), 0, 8), 'type' => $type, 'settings' => $settings];
    }

    /** The sign-off every template ends with. */
    protected function footer(string $company = '{{company_name}}'): array
    {
        return $this->block('footer', [
            'companyLine' => $company,
            'addressLine' => '',
            'unsubscribeText' => 'Unsubscribe',
            'align' => 'center',
            'fontSize' => 12,
        ]);
    }

    protected function logo(): array
    {
        return $this->block('logo', ['src' => '', 'alt' => '{{company_name}}', 'width' => 150, 'align' => 'center']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function templates(): array
    {
        return [
            // ------------------------------------------------------ welcome
            [
                'name' => 'Welcome — new subscriber',
                'subject' => 'Welcome aboard, {{first_name|there}}',
                'description' => 'Sent the moment someone joins. Sets expectations and gives one clear next step.',
                'category' => 'welcome',
                'settings' => ['preheader' => 'Here is what to expect from us.'],
                'blocks' => [
                    $this->logo(),
                    $this->block('heading', ['text' => 'Welcome, {{first_name|friend}}', 'level' => 1, 'align' => 'center', 'fontSize' => 30]),
                    $this->block('text', [
                        'html' => '<p>Thanks for signing up. You are on the list, and we are glad to have you.</p>'
                            .'<p>You will hear from us about once a week — practical things only, no filler. '
                            .'If it ever stops being useful, the unsubscribe link at the bottom works instantly.</p>',
                        'align' => 'left',
                    ]),
                    $this->block('button', ['text' => 'Start here', 'href' => 'https://example.com/start', 'align' => 'center']),
                    $this->block('divider', []),
                    $this->block('text', [
                        'html' => '<p>Questions? Just reply to this email — a real person reads it.</p>',
                        'fontSize' => 14,
                        'align' => 'center',
                    ]),
                    $this->footer(),
                ],
            ],

            // --------------------------------------------------- newsletter
            [
                'name' => 'Newsletter — regular issue',
                'subject' => '{{company_name}} — this week',
                'description' => 'A repeatable issue layout: a lead story, two shorter items, one call to action.',
                'category' => 'newsletter',
                'settings' => ['preheader' => 'The short version, in two minutes.'],
                'blocks' => [
                    $this->logo(),
                    $this->block('heading', ['text' => 'This week at {{company_name}}', 'level' => 2, 'align' => 'left']),
                    $this->block('text', [
                        'html' => '<p>Hello {{first_name|there}},</p><p>Here is what is worth your time this week.</p>',
                    ]),
                    $this->block('divider', []),
                    $this->block('heading', ['text' => 'The main story', 'level' => 3, 'fontSize' => 20, 'align' => 'left']),
                    $this->block('image', ['src' => '', 'alt' => 'Lead story', 'align' => 'center']),
                    $this->block('text', [
                        'html' => '<p>Two or three sentences on the thing that matters most. Then send them somewhere '
                            .'to read the rest.</p>',
                    ]),
                    $this->block('button', ['text' => 'Read the full story', 'href' => 'https://example.com/story', 'align' => 'left']),
                    $this->block('divider', []),
                    $this->block('columns', [
                        'count' => 2,
                        'columns' => [
                            ['html' => '<p><strong>Second item</strong><br>One short paragraph and a link.</p>'],
                            ['html' => '<p><strong>Third item</strong><br>One short paragraph and a link.</p>'],
                        ],
                    ]),
                    $this->block('social', ['links' => [
                        ['network' => 'facebook', 'href' => ''],
                        ['network' => 'linkedin', 'href' => ''],
                        ['network' => 'instagram', 'href' => ''],
                    ]]),
                    $this->footer(),
                ],
            ],

            // ---------------------------------------------------- promotion
            [
                'name' => 'Promotion — limited time',
                'subject' => '{{first_name|Hi}} — this ends Sunday',
                'description' => 'One offer, one deadline, one button. Nothing else competing for the click.',
                'category' => 'promotion',
                'settings' => ['preheader' => 'Ends Sunday at midnight.', 'contentBackground' => '#ffffff'],
                'blocks' => [
                    $this->logo(),
                    $this->block('heading', ['text' => 'Ends Sunday', 'level' => 1, 'align' => 'center', 'fontSize' => 34]),
                    $this->block('text', [
                        'html' => '<p>Hi {{first_name|there}},</p><p>Say exactly what the offer is in one sentence, '
                            .'then say when it ends. People decide on those two facts alone.</p>',
                        'align' => 'center',
                    ]),
                    $this->block('button', ['text' => 'Claim the offer', 'href' => 'https://example.com/offer', 'align' => 'center', 'paddingY' => 16]),
                    $this->block('text', [
                        'html' => '<p>Offer closes Sunday at 23:59. No code needed — the price is applied at checkout.</p>',
                        'fontSize' => 13,
                        'align' => 'center',
                    ]),
                    $this->footer(),
                ],
            ],

            // ------------------------------------------------ product offer
            [
                'name' => 'Product — feature and price',
                'subject' => 'A closer look at {{company_name}}',
                'description' => 'A single product laid out properly: image, what it does, what it costs, one button.',
                'category' => 'product',
                'settings' => ['preheader' => 'What it does, and what it costs.'],
                'blocks' => [
                    $this->logo(),
                    $this->block('image', ['src' => '', 'alt' => 'Product photo', 'align' => 'center']),
                    $this->block('heading', ['text' => 'The product name', 'level' => 2, 'align' => 'center']),
                    $this->block('text', [
                        'html' => '<p style="text-align:center">One sentence on what it does for the reader.</p>',
                        'align' => 'center',
                    ]),
                    $this->block('columns', [
                        'count' => 3,
                        'columns' => [
                            ['html' => '<p><strong>Built for</strong><br>Who it suits</p>'],
                            ['html' => '<p><strong>Ships in</strong><br>2 working days</p>'],
                            ['html' => '<p><strong>Price</strong><br>From $49</p>'],
                        ],
                    ]),
                    $this->block('button', ['text' => 'See the details', 'href' => 'https://example.com/product', 'align' => 'center']),
                    $this->block('divider', []),
                    $this->block('text', [
                        'html' => '<p>Not the right fit? Reply and tell us what you were looking for — it genuinely '
                            .'shapes what we build next.</p>',
                        'fontSize' => 14,
                    ]),
                    $this->footer(),
                ],
            ],

            // ------------------------------------------------------- course
            [
                'name' => 'Course — enrolment open',
                'subject' => 'Enrolment is open: {{company_name}} training',
                'description' => 'For a class or programme: what you learn, when it runs, how to enrol.',
                'category' => 'course',
                'settings' => ['preheader' => 'Starts on the 1st. Twelve places.'],
                'blocks' => [
                    $this->logo(),
                    $this->block('heading', ['text' => 'Enrolment is open', 'level' => 1, 'align' => 'center']),
                    $this->block('text', [
                        'html' => '<p>Hello {{first_name|there}},</p><p>Describe the course in two sentences: who it is '
                            .'for and what they will be able to do afterwards.</p>',
                    ]),
                    $this->block('heading', ['text' => 'What you will cover', 'level' => 3, 'fontSize' => 19, 'align' => 'left']),
                    $this->block('text', [
                        'html' => '<ul><li>Module one — the foundation</li><li>Module two — putting it to work</li>'
                            .'<li>Module three — the parts people get wrong</li></ul>',
                    ]),
                    $this->block('columns', [
                        'count' => 2,
                        'columns' => [
                            ['html' => '<p><strong>Starts</strong><br>1st of next month</p>'],
                            ['html' => '<p><strong>Runs for</strong><br>6 weeks, 2 evenings a week</p>'],
                        ],
                    ]),
                    $this->block('button', ['text' => 'Reserve a place', 'href' => 'https://example.com/enrol', 'align' => 'center']),
                    $this->footer(),
                ],
            ],

            // -------------------------------------------------------- event
            [
                'name' => 'Event — invitation',
                'subject' => 'You are invited, {{first_name|there}}',
                'description' => 'An invitation with the details people actually need: date, time, place, and RSVP.',
                'category' => 'event',
                'settings' => ['preheader' => 'The date, the place, and how to say yes.'],
                'blocks' => [
                    $this->logo(),
                    $this->block('heading', ['text' => 'You are invited', 'level' => 1, 'align' => 'center']),
                    $this->block('text', [
                        'html' => '<p style="text-align:center">One line on what the event is and why it is worth '
                            .'the evening.</p>',
                        'align' => 'center',
                    ]),
                    $this->block('divider', []),
                    $this->block('columns', [
                        'count' => 3,
                        'columns' => [
                            ['html' => '<p><strong>When</strong><br>Friday, 7:00 pm</p>'],
                            ['html' => '<p><strong>Where</strong><br>The venue, city</p>'],
                            ['html' => '<p><strong>Cost</strong><br>Free to attend</p>'],
                        ],
                    ]),
                    $this->block('button', ['text' => 'RSVP', 'href' => 'https://example.com/rsvp', 'align' => 'center']),
                    $this->block('text', [
                        'html' => '<p>Cannot make it? Reply and we will send the recording.</p>',
                        'fontSize' => 13,
                        'align' => 'center',
                    ]),
                    $this->footer(),
                ],
            ],

            // ----------------------------------------------------- discount
            [
                'name' => 'Discount — code inside',
                'subject' => 'Your code is inside, {{first_name|friend}}',
                'description' => 'A discount code shown large enough to read on a phone, with the terms stated plainly.',
                'category' => 'discount',
                'settings' => ['preheader' => 'One code, valid for 14 days.'],
                'blocks' => [
                    $this->logo(),
                    $this->block('heading', ['text' => 'Here is your code', 'level' => 2, 'align' => 'center']),
                    $this->block('text', [
                        'html' => '<p style="text-align:center;font-size:28px;letter-spacing:3px;'
                            .'border:2px dashed #94a3b8;padding:16px"><strong>SAVE20</strong></p>',
                        'align' => 'center',
                    ]),
                    $this->block('text', [
                        'html' => '<p style="text-align:center">Use it at checkout for 20% off. Valid for 14 days, '
                            .'one use per customer.</p>',
                        'align' => 'center',
                        'fontSize' => 14,
                    ]),
                    $this->block('button', ['text' => 'Shop now', 'href' => 'https://example.com/shop', 'align' => 'center']),
                    $this->footer(),
                ],
            ],

            // ------------------------------------------------- announcement
            [
                'name' => 'Announcement — something changed',
                'subject' => 'A change you should know about',
                'description' => 'For news that affects the reader: what changed, when, and what they need to do.',
                'category' => 'announcement',
                'settings' => ['preheader' => 'What changed, and what it means for you.'],
                'blocks' => [
                    $this->logo(),
                    $this->block('heading', ['text' => 'Something is changing', 'level' => 2, 'align' => 'left']),
                    $this->block('text', [
                        'html' => '<p>Hello {{first_name|there}},</p>'
                            .'<p><strong>What is changing:</strong> say it in one sentence.</p>'
                            .'<p><strong>When:</strong> the date it takes effect.</p>'
                            .'<p><strong>What you need to do:</strong> nothing, or exactly one thing.</p>',
                    ]),
                    $this->block('button', ['text' => 'Read the details', 'href' => 'https://example.com/news', 'align' => 'left']),
                    $this->block('divider', []),
                    $this->block('text', [
                        'html' => '<p>If this affects you and something is unclear, reply to this email and we will '
                            .'answer directly.</p>',
                        'fontSize' => 14,
                    ]),
                    $this->footer(),
                ],
            ],

            // ---------------------------------------------------- follow-up
            [
                'name' => 'Follow-up — checking in',
                'subject' => 'Following up, {{first_name|there}}',
                'description' => 'A short, plain follow-up. Deliberately light on design — it should read like a person wrote it.',
                'category' => 'follow-up',
                'settings' => ['preheader' => 'A short note, nothing more.', 'backgroundColor' => '#ffffff'],
                'blocks' => [
                    $this->block('text', [
                        'html' => '<p>Hi {{first_name|there}},</p>'
                            .'<p>I wanted to follow up on my last email. If now is not the right time, that is '
                            .'completely fine — just let me know and I will stop chasing.</p>'
                            .'<p>If it is useful, here is the one thing I would suggest looking at:</p>',
                        'fontSize' => 16,
                        'lineHeight' => 26,
                    ]),
                    $this->block('button', ['text' => 'Take a look', 'href' => 'https://example.com', 'align' => 'left']),
                    $this->block('text', [
                        'html' => '<p>Best,<br>{{company_name}}</p>',
                        'fontSize' => 16,
                    ]),
                    // The body already signs off, so the footer does not
                    // repeat the name two lines below it.
                    $this->footer(''),
                ],
            ],
        ];
    }
}
