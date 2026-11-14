<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Headers;

class GenericInboxNotice extends Mailable
{
    public function __construct(public string $language, public string $origin, public string $receiptId) {}

    public function headers(): Headers
    {
        $domain = substr(strrchr((string) config('mail.from.address'), '@'), 1);

        return new Headers(messageId: 'notice-'.$this->receiptId.'@'.$domain, text: ['Auto-Submitted' => 'auto-generated', 'X-Auto-Response-Suppress' => 'All']);
    }

    public function build(): self
    {
        return $this->subject($this->language === 'ar' ? 'إشعار جديد في حساب كريا بيز' : 'New Crea-Biz account notification')
            ->text('mail.generic-inbox-notice', ['ar' => $this->language === 'ar', 'origin' => $this->origin]);
    }
}
