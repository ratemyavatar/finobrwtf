<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

final class Mailer
{
    public static function send(string $email, string $name, string $subject, string $html): bool
    {
        $from = config('finobe.mail.from');

        try {
            return match (config('finobe.mail.mode')) {
                1 => Http::withHeaders(['authorization' => 'Zoho-enczapikey ' . config('finobe.mail.zepto_key')])
                    ->acceptJson()->post('https://api.zeptomail.com/v1.1/email', [
                        'from' => ['address' => $from],
                        'to' => [['email_address' => ['address' => $email, 'name' => $name]]],
                        'subject' => $subject,
                        'htmlbody' => $html,
                    ])->successful(),
                2 => (bool) Http::withHeaders(['X-Smtp2go-Api-Key' => config('finobe.mail.smtp2go_key')])
                    ->acceptJson()->post('https://api.smtp2go.com/v3/email/send', [
                        'sender' => 'Finobe <' . $from . '>',
                        'to' => [$name . ' <' . $email . '>'],
                        'subject' => $subject,
                        'html_body' => $html,
                    ])->json('data.succeeded'),
                default => self::smtp($email, $name, $subject, $html),
            };
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public static function template(string $file, array $values): string
    {
        $values['APPURL'] = rtrim(config('app.url'), '/');

        return str_replace(array_keys($values), array_values($values), file_get_contents(storage_path($file)));
    }

    private static function smtp(string $email, string $name, string $subject, string $html): bool
    {
        Mail::html($html, fn ($message) => $message->to($email, $name)->subject($subject));

        return true;
    }
}
