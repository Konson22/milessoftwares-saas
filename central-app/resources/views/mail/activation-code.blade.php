<x-mail::message>
# Your activation code

Here is the activation code for Miles Pharmacy on PC **{{ $device }}**@if ($pharmacyName) ({{ $pharmacyName }})@endif.

<x-mail::panel>
**To activate**<br>
On that PC, open **Settings, Activation**. Either choose **Open a licence file** and pick the file attached to this email (copied over on a USB stick), or copy the code below and paste it under **Activation code**.
</x-mail::panel>

<x-mail::panel>
<span style="font-family: monospace; font-size: 12px; word-break: break-all;">{{ $code }}</span>
</x-mail::panel>

The code works only on that PC, for that pharmacy, and needs no internet. Keep this email: if the app is reinstalled on the same PC, the same code activates it again.

Questions? Email [{{ $supportEmail }}](mailto:{{ $supportEmail }}).

Regards,<br>
The Miles Global Softwares team

<x-slot:subcopy>
You received this email because this address was entered on the activation page of {{ $website }} with a Miles Pharmacy licence key.
</x-slot:subcopy>
</x-mail::message>
