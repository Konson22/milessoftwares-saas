@props(['url'])
{{-- Every email opens with the logo; mail clients load it from the public site. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ \App\Support\Hosts\PlatformHosts::url(\App\Support\Hosts\PlatformHosts::root(), 'images/logo-with-text.png') }}" class="logo" alt="{{ trim(strip_tags($slot)) ?: 'Miles Global Softwares' }}" width="180" style="height: auto; width: 180px; max-width: 100%;">
</a>
</td>
</tr>
