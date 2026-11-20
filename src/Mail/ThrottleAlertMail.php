<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ThrottleAlertMail extends Mailable
{
    /**
     * @param  list<string>  $alertLines
     */
    public function __construct(
        public readonly string $alertSubject,
        public readonly array $alertLines,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alertSubject);
    }

    public function content(): Content
    {
        $items = array_map(
            static fn (string $line): string => '<li>'.e($line).'</li>',
            $this->alertLines
        );

        return new Content(
            htmlString: '<p>'.e($this->alertSubject).'</p><ul>'.implode('', $items).'</ul>',
        );
    }
}
