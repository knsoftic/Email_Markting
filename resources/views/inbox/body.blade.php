{{--
    The message body, and nothing else.

    This document is served by InboxController@body and loaded into a
    <iframe sandbox=""> on the reading screen. It is deliberately not part of
    the application:

      * no layout, no Tailwind, no @vite, no <script> of any kind — the frame
        has no scripting permission and nothing here should imply otherwise;
      * the only variable it receives is $body, which the controller has
        already put through IncomingHtmlSanitizer. It is printed raw with
        {!! !!} because escaping it here would show the reader the markup
        instead of the message;
      * the response carries its own Content-Security-Policy
        (default-src 'none') from the controller, so even a sanitiser bypass
        has nothing to reach.

    The styling below is deliberately small. An email carries its own layout,
    usually as inline styles on nested tables, and a stylesheet with opinions
    would fight it. All this does is give an unstyled message a readable
    default, keep a 900px-wide table inside a scroller instead of letting it
    push the frame open, and make an image the sanitiser held back visible as a
    placeholder rather than as nothing at all.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Belt and braces: the controller sends Referrer-Policy: no-referrer too. --}}
    <meta name="referrer" content="no-referrer">
    <title>Message body</title>

    <style>
        html {
            -webkit-text-size-adjust: 100%;
        }

        body {
            margin: 0;
            padding: 16px;
            background: #ffffff;
            color: #1f2937;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                         "Helvetica Neue", Arial, "Noto Sans", sans-serif;
            font-size: 14px;
            line-height: 1.55;
        }

        /*
         * The scroller. A message built on a fixed-width table is wider than
         * this frame on a phone; it scrolls sideways in here rather than
         * stretching the document and pushing the reading screen out of shape.
         * Long unbroken strings — a tracking URL pasted as text — wrap instead
         * of doing the same thing.
         */
        .kn-mail {
            overflow-x: auto;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .kn-mail > *:first-child { margin-top: 0; }
        .kn-mail > *:last-child { margin-bottom: 0; }

        p, ul, ol, dl, blockquote, pre, table { margin: 0 0 1em; }
        h1, h2, h3, h4, h5, h6 { margin: 1.2em 0 0.5em; line-height: 1.3; }
        h1 { font-size: 22px; }
        h2 { font-size: 19px; }
        h3 { font-size: 17px; }
        h4, h5, h6 { font-size: 15px; }

        a { color: #1d4ed8; }

        img { max-width: 100%; height: auto; border: 0; }

        table { border-collapse: collapse; }
        td, th { padding: 2px; }

        blockquote {
            padding: 0 0 0 12px;
            border-left: 3px solid #e5e7eb;
            color: #4b5563;
        }

        /* A quoted reply chain is long and it is not what the reader came for. */
        pre, code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 13px;
        }

        pre {
            white-space: pre-wrap;
            overflow-x: auto;
        }

        hr {
            border: 0;
            border-top: 1px solid #e5e7eb;
            margin: 1.4em 0;
        }

        /*
         * A remote image the sanitiser held back. It has no src at all — the
         * address was moved to data-kn-blocked-src — so the browser would
         * otherwise render nothing, or a broken-image icon, and the message
         * would silently look like it had a gap in it. This makes the gap
         * deliberate and obvious. The min sizes matter for the common case: a
         * tracking pixel is 1x1 and would be invisible without them.
         */
        img.kn-blocked-image {
            display: inline-block;
            box-sizing: border-box;
            min-width: 130px;
            min-height: 30px;
            max-width: 100%;
            padding: 5px 9px;
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
            background: #f8fafc;
            color: #64748b;
            font-size: 12px;
            font-style: italic;
            line-height: 18px;
            text-align: left;
            vertical-align: middle;
        }

        /* Shown after the alt text when the browser renders it, on its own
           when the sender left the alt empty. */
        img.kn-blocked-image::after {
            content: " (image blocked)";
        }

        .kn-empty {
            margin: 0;
            color: #6b7280;
            font-style: italic;
        }
    </style>
</head>
<body>
    @if (trim($body) === '')
        {{-- Reachable on its own URL, so it says what happened rather than
             rendering a blank white frame that reads as a failure. --}}
        <p class="kn-empty">This message has no body — nothing arrived in either the HTML or the plain-text part.</p>
    @else
        <div class="kn-mail">{!! $body !!}</div>
    @endif
</body>
</html>
