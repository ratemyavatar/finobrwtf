<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use MailerSend\Helpers\Builder\Personalization;
use MailerSend\LaravelDriver\MailerSendTrait;

class DynamicContentEmail extends Mailable
{
    use Queueable, SerializesModels, MailerSendTrait;

    public string $htmlContent;
    public string $textContent;
    public string $subjectLine;

    public function __construct(string $htmlContent, string $textContent = "", string $subjectLine)
    {
        $this->htmlContent = $htmlContent;
        $this->textContent = $textContent;
        $this->subjectLine = $subjectLine;
    }

    public function build()
    {
        return $this->subject($this->subjectLine)
            ->html($this->htmlContent);
    }
}
