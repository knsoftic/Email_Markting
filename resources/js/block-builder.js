/**
 * Block-based email builder, shared by the template editor and the campaign
 * editor. The Blade side passes the stored document, the block catalogue and
 * the token list; everything here is ordering, editing and preview.
 *
 * Two rules this component exists to keep:
 *
 *  1. The rendered email is NEVER put in the page's own DOM. It goes into an
 *     iframe with sandbox="" — no scripts, no forms, no same-origin access —
 *     because a template can contain a Custom HTML block and the preview must
 *     not become a way to run code inside the app.
 *
 *  2. The server compiles the preview, not the browser. What is previewed is
 *     what EmailCompiler will actually send, so the two cannot drift apart.
 */
/**
 * The Quill editor that last held focus, kept OUTSIDE the Alpine data object
 * on purpose. Alpine makes plain objects reactive, and a class instance reads
 * as a plain object — wrapping Quill in a Proxy breaks the identity checks it
 * does internally against its own instances. A DOM element is safe to store in
 * the data (Alpine leaves host objects alone); a Quill instance is not.
 */
let focusedEditor = null;

export default function blockBuilder(config) {
    return {
        doc: { settings: {}, blocks: [] },
        types: [],
        tokens: [],

        selected: null,
        device: 'desktop',
        view: 'preview', // preview | html | text

        previewHtml: '',
        plainText: '',
        unknownTokens: [],
        bytes: 0,
        gmailClip: false,
        // Whether the server had a contact to fill the placeholders with. Null
        // until the first compile answers, so the caption claims nothing it
        // has not been told.
        sampled: null,

        compiling: false,
        compileError: '',
        dirty: false,
        dragging: null,
        timer: null,
        lastField: null,

        init() {
            const source = config.document || {};

            this.doc = {
                settings: { ...(source.settings || {}) },
                blocks: (source.blocks || []).map((b) => ({ ...b, id: b.id || this.uid() })),
            };

            this.types = config.blockTypes || [];
            this.tokens = config.tokens || [];

            if (this.doc.blocks.length > 0) this.selected = 0;

            this.compile();

            this.$watch('doc', () => {
                this.dirty = true;
                this.queueCompile();
            });

            // Losing a half-built email to a stray click is the kind of thing
            // nobody forgives, so leaving with unsaved changes is confirmed.
            window.addEventListener('beforeunload', (e) => {
                if (!this.dirty) return;
                e.preventDefault();
                e.returnValue = '';
            });
        },

        uid() {
            return 'b' + Math.random().toString(36).slice(2, 10);
        },

        // --------------------------------------------------------- catalogue

        typeOf(block) {
            return this.types.find((t) => t.type === block?.type) || null;
        },

        labelOf(block) {
            return this.typeOf(block)?.label || block?.type || 'Block';
        },

        iconOf(block) {
            return this.typeOf(block)?.icon || '?';
        },

        fieldsOf(block) {
            return this.typeOf(block)?.fields || [];
        },

        /** A one-line description, so the block list reads at a glance. */
        summaryOf(block) {
            const s = block.settings || {};
            const strip = (html) => String(html || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

            switch (block.type) {
                case 'heading': return s.text || '';
                case 'text': return strip(s.html);
                case 'button': return s.text ? s.text + ' → ' + (s.href || 'no link yet') : '';
                case 'image':
                case 'logo': return s.src ? String(s.src).split('/').pop() : 'No image yet';
                case 'divider': return (s.thickness || 1) + 'px line';
                case 'spacer': return (s.height || 0) + 'px of space';
                case 'columns': return (s.count || 2) + ' columns';
                case 'social': return (s.links || []).filter((l) => l.href).length + ' link(s) set';
                case 'footer': return s.companyLine || 'Unsubscribe footer';
                case 'html': return strip(s.html).slice(0, 60);
                default: return '';
            }
        },

        // ------------------------------------------------------- block edits

        add(type) {
            const def = this.types.find((t) => t.type === type);
            if (!def) return;

            const block = {
                id: this.uid(),
                type,
                settings: JSON.parse(JSON.stringify(def.defaults || {})),
            };

            const at = this.selected === null ? this.doc.blocks.length : this.selected + 1;

            this.doc.blocks.splice(at, 0, block);
            this.selected = at;
        },

        remove(index) {
            const block = this.doc.blocks[index];

            const lastFooter = block?.type === 'footer' && this.footerCount() === 1;

            if (!window.confirm(lastFooter
                ? 'The footer holds the unsubscribe link. Without it this email cannot be sent. Remove it anyway?'
                : 'Remove this block?')) {
                return;
            }

            this.doc.blocks.splice(index, 1);

            // The selection follows the block it was on, so deleting something
            // above it does not silently move the editor to a different block.
            if (this.doc.blocks.length === 0) this.selected = null;
            else if (this.selected !== null && index < this.selected) this.selected -= 1;
            else if (this.selected >= this.doc.blocks.length) this.selected = this.doc.blocks.length - 1;
        },

        footerCount() {
            return this.doc.blocks.filter((b) => b.type === 'footer').length;
        },

        hasFooter() {
            return this.footerCount() > 0;
        },

        duplicate(index) {
            const copy = JSON.parse(JSON.stringify(this.doc.blocks[index]));
            copy.id = this.uid();

            this.doc.blocks.splice(index + 1, 0, copy);
            this.selected = index + 1;
        },

        move(index, delta) {
            const to = index + delta;
            if (to < 0 || to >= this.doc.blocks.length) return;

            const [block] = this.doc.blocks.splice(index, 1);
            this.doc.blocks.splice(to, 0, block);
            this.selected = to;
        },

        // Drag reordering. The up/down buttons stay as the keyboard path.
        dragStart(index, event) {
            this.dragging = index;

            // Firefox refuses to start a drag at all unless dragstart puts
            // something on the dataTransfer, so this is not optional.
            if (event?.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(index));
            }
        },

        dragOver(index) {
            if (this.dragging === null || this.dragging === index) return;

            const [block] = this.doc.blocks.splice(this.dragging, 1);
            this.doc.blocks.splice(index, 0, block);

            this.dragging = index;
            this.selected = index;
        },

        dragEnd() {
            this.dragging = null;
        },

        current() {
            return this.selected === null ? null : this.doc.blocks[this.selected];
        },

        select(index) {
            this.selected = index;
        },

        // ------------------------------------------------------ field access

        settingValue(key) {
            const block = this.current();

            return block ? (block.settings[key] ?? '') : '';
        },

        setSetting(key, value) {
            const block = this.current();

            if (block) block.settings[key] = value;
        },

        /**
         * A colour input cannot hold "inherit", so an unset colour shows the
         * document default in the swatch while the stored value stays empty —
         * BlockCatalogue treats empty as "use the document setting".
         */
        colourValue(key, fallback = '#000000') {
            const value = String(this.settingValue(key) || '');

            return /^#[0-9a-fA-F]{6}$/.test(value) ? value : fallback;
        },

        docColour(key, fallback) {
            const value = String(this.doc.settings[key] || '');

            return /^#[0-9a-fA-F]{6}$/.test(value) ? value : fallback;
        },

        // --------------------------------------------- repeater sub-editors

        /** Keeps the columns array as long as the chosen column count. */
        syncColumns(block) {
            const count = parseInt(block.settings.count, 10) || 2;
            const cols = block.settings.columns || [];

            while (cols.length < count) cols.push({ html: '<p>Column</p>' });

            block.settings.columns = cols.slice(0, count);
        },

        addSocial(block) {
            block.settings.links = [...(block.settings.links || []), { network: 'facebook', href: '' }];
        },

        removeSocial(block, index) {
            block.settings.links.splice(index, 1);
        },

        // ---------------------------------------------------- rich text

        /**
         * Mounts a Quill editor on the given element and keeps it in sync with
         * the block setting.
         *
         * Quill is imported dynamically so it lands in its own Vite chunk: it
         * is ~40 KB and only the two builder screens need it, so loading it in
         * the main bundle would slow down every other page for nothing.
         *
         * The toolbar is deliberately short. Email clients ignore most of what
         * a general-purpose editor can produce — alignment classes, indents,
         * colour spans — so offering them would be offering formatting that
         * silently disappears in the inbox. Block alignment is a block setting,
         * where it compiles to a real table attribute.
         */
        async mountEditor(el, key) {
            if (!el || el.dataset.quillMounted) return;

            el.dataset.quillMounted = '1';

            const [{ default: Quill }] = await Promise.all([
                import('quill'),
                import('quill/dist/quill.snow.css'),
            ]);

            const editor = new Quill(el, {
                theme: 'snow',
                placeholder: 'Write your message…',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ list: 'ordered' }, { list: 'bullet' }],
                        ['blockquote', 'link'],
                        ['clean'],
                    ],
                },
            });

            const existing = String(this.settingValue(key) || '');

            if (existing !== '') {
                editor.clipboard.dangerouslyPasteHTML(existing);
            }

            // Only the author's own typing is written back. Loading the block
            // above re-serialises it through Quill, and that fires text-change
            // with an "api" source: writing it back would flag a template
            // nobody has touched as unsaved, and the next save would store
            // Quill's rewrite over HTML the author put in by hand.
            editor.on('text-change', (delta, oldDelta, source) => {
                if (source !== 'user') return;

                const html = this.emailSafeHtml(editor.root.innerHTML);

                // Quill's "empty" is a paragraph holding a line break. Storing
                // that would put a stray blank line in every email.
                this.setSetting(key, html === '<p><br></p>' ? '' : html);
            });

            // The token buttons insert into the last focused field, and a Quill
            // editor is a contenteditable div rather than a textarea — so it
            // registers itself the same way.
            editor.root.addEventListener('focus', () => {
                this.lastField = null;
                focusedEditor = editor;
            });
        },

        /**
         * Quill 2 marks up EVERY list as <ol>, telling a bullet list apart by
         * data-list="bullet" on the <li> plus a rule in its own stylesheet.
         * Neither reaches an inbox — the sanitiser drops the attribute and the
         * stylesheet was never part of the email — so a bullet list stored as
         * Quill writes it arrives numbered, which is not what the author saw.
         * This turns it into the <ul>/<ol> an email client understands (Quill
         * reads both back, so editing it again still works) and removes the
         * <span class="ql-ui"> markers Quill uses for its own list controls.
         */
        emailSafeHtml(html) {
            const holder = document.createElement('div');
            holder.innerHTML = html;

            holder.querySelectorAll('span.ql-ui').forEach((span) => span.remove());

            holder.querySelectorAll('ol').forEach((list) => {
                const runs = [];

                Array.from(list.children).forEach((item) => {
                    // Only an explicit bullet becomes a <ul>; anything else
                    // keeps the numbering the markup already claimed.
                    const bullet = item.getAttribute('data-list') === 'bullet';

                    item.removeAttribute('data-list');

                    const run = runs[runs.length - 1];

                    if (run && run.bullet === bullet) run.items.push(item);
                    else runs.push({ bullet, items: [item] });
                });

                runs.forEach((run) => {
                    const replacement = document.createElement(run.bullet ? 'ul' : 'ol');

                    run.items.forEach((item) => replacement.appendChild(item));
                    list.parentNode.insertBefore(replacement, list);
                });

                list.remove();
            });

            return holder.innerHTML;
        },

        // --------------------------------------------------------- tokens

        tokenGroups() {
            return this.tokens.reduce((groups, t) => {
                (groups[t.group] = groups[t.group] || []).push(t);
                return groups;
            }, {});
        },

        rememberField(event) {
            const el = event.target;

            if (el.matches('input[type=text], input[type=url], textarea')) {
                this.lastField = el;
                focusedEditor = null;
            }
        },

        /**
         * Drops a token into whichever field was focused last, at the cursor.
         * The input event is dispatched by hand: assigning .value directly
         * does not notify x-model.
         */
        insertToken(token) {
            // A Quill editor was focused more recently than any plain field.
            // hasFocus() is already false here — pressing the button took the
            // focus off the editor — so this leans on the caret Quill saved on
            // blur, exactly as the plain-field path leans on lastField. The
            // editor is only usable while its element is still in the page:
            // switching blocks tears the old one down.
            if (focusedEditor && focusedEditor.root.isConnected) {
                const range = focusedEditor.getSelection(true);

                // "user", because the text-change handler ignores api writes.
                focusedEditor.insertText(
                    range ? range.index : focusedEditor.getLength(),
                    token,
                    'user',
                );

                return;
            }

            const el = this.lastField;

            if (!el || !el.isConnected) {
                // Nothing to insert into, so the token goes to the clipboard —
                // but only the resolved promise proves that worked. The API is
                // absent outside a secure context and rejects when permission
                // is refused, and announcing "copied" either way both lies to
                // the author and leaves an unhandled rejection behind.
                const hint = 'Click into a field first, then press ' + token + ' to place it.';
                const copying = navigator.clipboard?.writeText(token);

                if (copying) {
                    copying
                        .then(() => this.toast(token + ' copied — paste it into any field.'))
                        .catch(() => this.toast(hint));
                } else {
                    this.toast(hint);
                }

                return;
            }

            const start = el.selectionStart ?? el.value.length;
            const end = el.selectionEnd ?? el.value.length;

            el.value = el.value.slice(0, start) + token + el.value.slice(end);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.focus();
            el.setSelectionRange(start + token.length, start + token.length);
        },

        toast(message) {
            window.dispatchEvent(new CustomEvent('toast', { detail: { message, type: 'info' } }));
        },

        // -------------------------------------------------- load a template

        /**
         * Replaces the whole document with a template's content.
         *
         * Destructive by nature, so it always asks first when there is
         * anything to lose — and it only ever REPLACES, never merges: merging
         * two block documents produces something neither the user nor the
         * template author intended.
         */
        async applyTemplate(url, label) {
            if (!url) return;

            if (this.doc.blocks.length > 0
                && !window.confirm(`Replace the ${this.doc.blocks.length} block(s) here with the content of "${label}"? What you have built now will be lost.`)) {
                return;
            }

            this.loadingTemplate = true;

            try {
                const response = await fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error(`That template could not be loaded (HTTP ${response.status}).`);
                }

                const data = await response.json();

                // Fresh ids: two campaigns built from one template must not
                // share block ids, or a later merge would collide.
                this.doc = {
                    settings: { ...(data.document.settings || {}) },
                    blocks: (data.document.blocks || []).map((b) => ({ ...b, id: this.uid() })),
                };

                this.selected = this.doc.blocks.length > 0 ? 0 : null;
                this.dirty = true;

                await this.compile();

                this.toast(`Loaded the content of "${data.name}". Nothing is saved until you save the campaign.`);
            } catch (error) {
                this.compileError = error.message;
            } finally {
                this.loadingTemplate = false;
            }
        },

        loadingTemplate: false,

        // ------------------------------------------------------- compiling

        queueCompile() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.compile(), 450);
        },

        async compile() {
            this.compiling = true;
            this.compileError = '';

            try {
                const response = await fetch(config.compileUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ blocks: this.doc.blocks, settings: this.doc.settings }),
                });

                if (!response.ok) {
                    throw new Error(await this.compileFailureMessage(response));
                }

                const data = await response.json();

                this.previewHtml = data.html;
                this.plainText = data.text;
                this.unknownTokens = data.unknown_tokens || [];
                this.bytes = data.bytes || 0;
                this.gmailClip = !!data.gmail_clip;
                this.sampled = !!data.sampled;

                this.paint();
            } catch (error) {
                this.compileError = error.message;
            } finally {
                this.compiling = false;
            }
        },

        /**
         * A failed compile has to say something the author can act on. 419 is
         * the one that really matters: the session has expired, so Save is
         * about to fail the same way, and only a reload fixes it.
         */
        async compileFailureMessage(response) {
            if (response.status === 419) {
                return 'Your session has expired, so the preview could not be rebuilt — and saving would fail too. '
                    + 'Copy anything you cannot lose, then reload this page and sign in again.';
            }

            if (response.status === 422) {
                const body = await response.json().catch(() => null);

                return body?.message
                    ? 'The preview could not be built: ' + body.message
                    : 'The preview could not be built — the server rejected the content of one of the blocks.';
            }

            return 'The preview could not be built (HTTP ' + response.status + '). '
                + 'Your content is untouched — press Refresh to try again.';
        },

        /**
         * srcdoc, not innerHTML: the email lives in its own sandboxed document
         * and can never reach this one.
         */
        paint() {
            const frame = this.$refs.frame;
            if (frame) frame.srcdoc = this.previewHtml;
        },

        sizeLabel() {
            return this.bytes < 1024
                ? this.bytes + ' B'
                : (this.bytes / 1024).toFixed(1) + ' KB';
        },

        // ---------------------------------------------------------- saving

        /** Called from the form's @submit — the document travels as JSON. */
        serialise() {
            this.$refs.blocksField.value = JSON.stringify(this.doc.blocks);
            this.$refs.settingsField.value = JSON.stringify(this.doc.settings);
            this.dirty = false;
        },
    };
}
