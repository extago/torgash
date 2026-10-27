<?php

namespace Torgash\Newsletter\Campaigns;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Headers;

/**
 * A campaign's letter, already written by CampaignLetter (core's frame — no
 * markup of its own), with the headers a bulk letter needs: the mail
 * client's own "Unsubscribe" (RFC 2369 + RFC 8058 one-click), which Gmail and
 * Yahoo require of anybody sending in bulk.
 */
class CampaignMail extends Mailable
{
    public function __construct(public string $letterSubject, public string $letterHtml, public ?string $oneClick = null) {}

    public function build(): self
    {
        return $this->subject($this->letterSubject)->html($this->letterHtml);
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->oneClick ? [
            'List-Unsubscribe' => '<'.$this->oneClick.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'Precedence' => 'bulk',
        ] : []);
    }
}
