<x-mail::message>
# A customer is waiting for your reply

Hi {{ $recipient->name }},

**{{ $customerName }}** has been waiting for approximately {{ $waitingMinutes }} minutes in {{ $conversation->workspace?->name ?? 'your inbox' }}.

> {{ $messagePreview ?: 'The customer sent a new message.' }}

@if($conversation->assignedUser)
This conversation is currently assigned to **{{ $conversation->assignedUser->name }}**.
@else
This conversation is currently unassigned.
@endif

<x-mail::button :url="$conversationUrl">
Reply to customer
</x-mail::button>

A quick response helps customers feel looked after. This reminder is sent once per unanswered customer message.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
