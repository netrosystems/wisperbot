<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function clientUser(): User
    {
        return User::factory()->create([
            'role'              => 'client',
            'email_verified_at' => now(),
        ]);
    }

    public function test_user_can_view_media_library(): void
    {
        $user = $this->clientUser();
        $this->actingAs($user)
            ->get(route('client.media.index'))
            ->assertOk();
    }

    public function test_user_can_upload_file(): void
    {
        Storage::fake('public');
        $user = $this->clientUser();

        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);

        $this->actingAs($user)
            ->post(route('client.media.store'), ['file' => $file])
            ->assertRedirect();

        $this->assertDatabaseHas('media', [
            'mediable_type' => User::class,
            'mediable_id'   => $user->id,
        ]);
    }

    public function test_social_composer_can_upload_a_two_megabyte_image_and_receive_media_metadata(): void
    {
        Storage::fake('public');
        $user = $this->clientUser();
        $file = UploadedFile::fake()->create('scheduled-post.jpg', 2048, 'image/jpeg');

        $response = $this->actingAs($user)
            ->postJson(route('client.media.store'), [
                'file' => $file,
                'collection' => 'social',
            ]);

        $response
            ->assertCreated()
            ->assertJsonStructure(['id', 'filename', 'url', 'size_bytes'])
            ->assertJsonPath('filename', 'scheduled-post.jpg');

        $this->assertDatabaseHas('media', [
            'mediable_type' => User::class,
            'mediable_id' => $user->id,
            'collection' => 'social',
            'filename' => 'scheduled-post.jpg',
        ]);
    }

    public function test_user_can_delete_own_media(): void
    {
        Storage::fake('public');
        $user  = $this->clientUser();
        $media = Media::factory()->create([
            'mediable_type' => User::class,
            'mediable_id'   => $user->id,
            'disk'          => 'public',
            'path'          => 'media/test.jpg',
        ]);

        $this->actingAs($user)
            ->deleteJson(route('client.media.destroy', $media))
            ->assertOk();

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_guest_cannot_access_media_library(): void
    {
        $this->get(route('client.media.index'))
            ->assertRedirect(route('login'));
    }
}
