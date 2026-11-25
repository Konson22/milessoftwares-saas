<x-mail::message>
# Your free trial has started

Thank you for choosing Miles Global Softwares. Your **{{ $days }}-day {{ $application }} trial** is running, and your invoice is attached. There is nothing to pay.

<x-mail::table>
| Invoice {{ $number }} | |
|:--|--:|
@foreach ($lines as $line)
| {{ $line['description'] }}<br><small>{{ $line['detail'] }}</small> | {{ $line['amount'] }} |
@endforeach
| **Total due** | **{{ $total }}** |
</x-mail::table>

Your trial runs until **{{ $trialEnd }}**. Nothing is charged automatically: to carry on after that, choose a plan and keep everything you entered.

<x-mail::button :url="$dashboardUrl">
Open your dashboard
</x-mail::button>

@if ($plans !== [])
**Plans after your trial**

@foreach ($plans as $plan)
- {{ $plan['name'] }}: {{ $plan['price'] }}{{ $plan['price'] === 'Free' || $plan['interval'] === 'custom' ? '' : ($plan['interval'] === 'yearly' ? ' per year' : ' per month') }}
@endforeach

[See all plans]({{ $plansUrl }})
@endif

Questions? Reply to this email or message us on WhatsApp at {{ $from['phone'] }}.

Regards,<br>
{{ $from['name'] }}<br>
<small>{{ $from['address'] }}</small>
</x-mail::message>
