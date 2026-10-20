<?php

use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;

test('production mail goes out through Resend once its key is set', function () {
    config(['mail.default' => 'resend', 'services.resend.key' => 're_test_key']);

    expect(Mail::mailer()->getSymfonyTransport())->toBeInstanceOf(ResendTransport::class);
});
