<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email copy of an important Logbook notification: its title, its
 * short text and a link. Never entry values: the link opens Logbook, which
 * checks access again.
 */
class LogbookNotice extends Mailable
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $heading, public ?string $text, public string $link, public string $workspace) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->heading} · {$this->workspace}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.notice', text: 'mail.notice-text');
    }
}
