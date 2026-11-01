<x-mail::message>
# Your activation code

Here is the activation code for {{ $app }} on {{ $restaurant ? 'phone' : 'PC' }} **{{ $device }}**@if ($pharmacyName) ({{ $pharmacyName }})@endif.

<x-mail::panel>
**To activate**<br>
@if ($restaurant)
On that phone, open this email (or forward it there), copy the code below, then in the app open **Account, Activation** and tap **Paste** under **Activation code**.
@else
On that PC, open **Settings, Activation**. Either choose **Open a licence file** and pick the file attached to this email (copied over on a USB stick), or copy the code below and paste it under **Activation code**.
@endif
</x-mail::panel>

<x-mail::panel>
<span style="font-family: monospace; font-size: 12px; word-break: break-all;">{{ $code }}</span>
</x-mail::panel>

The code works only on that {{ $restaurant ? 'phone, for that restaurant' : 'PC, for that pharmacy' }}, and needs no internet. Keep this email: if the app is reinstalled on the same {{ $restaurant ? 'phone' : 'PC' }}, the same code activates it again.

Questions? Email [{{ $supportEmail }}](mailto:{{ $supportEmail }}).

Regards,<br>
The Miles Global Softwares team

<x-slot:subcopy>
You received this email because this address was entered on the activation page of {{ $website }} with a {{ $app }} licence key.
</x-slot:subcopy>
</x-mail::message>
