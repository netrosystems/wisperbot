<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
{{ Illuminate\Mail\Markdown::parse($slot) }}
<p style="margin-top: 16px; font-size: 12px; color: #6B7280;">
    &copy; {{ date('Y') }} {{ config('app.name') }} &mdash; All rights reserved.<br>
    @if(config('app.url'))
    <a href="{{ config('app.url') }}/unsubscribe" style="color: #6B7280; text-decoration: underline;">Unsubscribe</a>
    &nbsp;&bull;&nbsp;
    <a href="{{ config('app.url') }}/privacy" style="color: #6B7280; text-decoration: underline;">Privacy Policy</a>
    @endif
</p>
</td>
</tr>
</table>
</td>
</tr>
