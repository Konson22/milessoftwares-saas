<x-mail::message>
# We received your message

{{ $greeting }}

Thank you for contacting Miles Global Softwares. Your message has reached our team, and we will reply within **one working day**.

<x-mail::panel>
**What happens next**<br>
Someone from our team reads your message and replies to this email address. If you need to add anything in the meantime, simply reply to this email.
</x-mail::panel>

While you wait, you can explore our products or start a free trial. No payment details are needed.

<x-mail::button :url="$productsUrl">
Explore our products
</x-mail::button>

Need us sooner? Email [{{ $supportEmail }}](mailto:{{ $supportEmail }})@if ($phone !== '') or message us on WhatsApp at {{ $phone }}@endif.

Regards,<br>
{{ $from }}<br>
@if ($address !== '')
<small>{{ $address }}</small>
@endif

<x-slot:subcopy>
You received this email because this address was entered in the contact form on {{ $website }}. If that was not you, you can ignore this email.
</x-slot:subcopy>
</x-mail::message>
