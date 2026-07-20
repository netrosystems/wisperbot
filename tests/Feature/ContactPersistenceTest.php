<?php

namespace Tests\Feature;

use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_save_contact_profile_fields(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'source' => 'webchat',
            'first_name' => 'Website visitor',
        ]);

        $this->actingAs($user)
            ->from(route('client.contacts.show', $contact))
            ->put(route('client.contacts.update', $contact), [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.test',
                'country' => 'GB',
                'language' => 'en',
                'opt_in_whatsapp' => false,
                'opt_in_sms' => false,
                'opt_in_email' => true,
                'segment_ids' => [],
            ])
            ->assertRedirect(route('client.contacts.show', $contact));

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
            'country' => 'GB',
            'language' => 'en',
            'opt_in_email' => true,
        ]);
    }

    public function test_client_can_save_a_contact_avatar(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        Storage::fake('public');
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'source' => 'webchat',
            'first_name' => 'Website visitor',
        ]);
        $avatar = UploadedFile::fake()->image('avatar.png', 120, 120);

        $this->actingAs($user)
            ->from(route('client.contacts.show', $contact))
            ->post(route('client.contacts.avatar.upload', $contact), ['avatar' => $avatar])
            ->assertRedirect(route('client.contacts.show', $contact));

        $contact->refresh();
        $this->assertNotNull($contact->avatar);
        Storage::disk('public')->assertExists($contact->avatar);
    }
}
