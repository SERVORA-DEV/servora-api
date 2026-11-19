@props(['url'])
{{-- The SERVORA wordmark over a short gold rule (text, so it shows even when a mail app blocks images). --}}
<tr>
<td class="header">
<a href="{{ $url }}" class="brand" style="display: inline-block;">{!! trim($slot) !!}</a>
<table class="brand-rule" align="center" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>&nbsp;</td>
</tr>
</table>
</td>
</tr>
