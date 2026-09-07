<?php

namespace App\Http\Controllers\Inbox;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\EmailAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves attachments.
 *
 * ── Nothing here is served as its own content type ──────────────────────────
 * An attachment is a file a stranger sent. Serving it with the MIME type they
 * claimed, from our origin, is how an "invoice.html" becomes a script running
 * with the reader's session. So a download is always an octet-stream
 * attachment, and the one exception — an inline image referenced by Content-ID
 * from the message body — is served only when the stored type really is an
 * image, and with nosniff so the browser cannot decide otherwise.
 */
class AttachmentController extends Controller
{
    /** The only types allowed to render inline in a message body. */
    protected const INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'];

    public function download(Email $email, EmailAttachment $attachment): StreamedResponse
    {
        $this->assertBelongs($email, $attachment);

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->name,
            [
                // Never the claimed type. A download is a download.
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'",
            ]
        );
    }

    /**
     * An inline image, for a cid: reference the sanitiser rewrote.
     */
    public function inline(Email $email, EmailAttachment $attachment): StreamedResponse
    {
        $this->assertBelongs($email, $attachment);

        $type = mb_strtolower((string) $attachment->mime_type);

        // Not an image by our own record, so it does not get to render as one
        // just because the body asked it to.
        abort_unless(in_array($type, self::INLINE_TYPES, true), 404);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->name,
            [
                'Content-Type' => $type,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'",
                // The body is re-rendered on each view; a cached image that
                // outlives a deleted message is not worth the saved request.
                'Cache-Control' => 'private, max-age=0, must-revalidate',
            ],
            'inline'
        );
    }

    /**
     * The attachment must belong to the message in the URL, and the message
     * must belong to this account — the model binding enforces the second, and
     * this enforces the first. Without it, any attachment id could be read
     * through any message the account owns.
     */
    protected function assertBelongs(Email $email, EmailAttachment $attachment): void
    {
        abort_unless((int) $attachment->email_id === (int) $email->id, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);
    }
}
