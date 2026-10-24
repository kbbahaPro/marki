<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->user = User::factory()->create(['name' => 'Jane Host', 'username' => 'jane']);
    }

    public function test_title_and_company_can_be_saved(): void
    {
        $this->update(['job_title' => 'Head of Sales', 'company' => 'Acme'])->assertSessionHasNoErrors();

        $this->user->refresh();
        $this->assertSame('Head of Sales', $this->user->job_title);
        $this->assertSame('Acme', $this->user->company);
        $this->assertSame('Head of Sales at Acme', $this->user->headline());
    }

    public function test_headline_handles_missing_parts(): void
    {
        $this->assertNull($this->user->headline());
        $this->assertSame('Acme', $this->user->fill(['company' => 'Acme'])->headline());
        $this->assertSame('CTO', $this->user->fill(['company' => null, 'job_title' => 'CTO'])->headline());
        $this->assertSame('JH', $this->user->initials());
        $this->assertSame('M', User::factory()->make(['name' => 'Madonna'])->initials());
    }

    public function test_a_photo_is_cropped_square_and_re_encoded_as_webp(): void
    {
        $this->update(['avatar' => UploadedFile::fake()->image('me.jpg', 900, 600)])->assertSessionHasNoErrors();

        $path = $this->user->fresh()->avatar_path;
        $this->assertStringStartsWith('avatars/', $path);
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('public')->assertExists($path);

        $info = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([400, 400, 'image/webp'], [$info[0], $info[1], $info['mime']]);
        $this->assertSame(Storage::disk('public')->url($path), $this->user->fresh()->avatarUrl());
    }

    public function test_replacing_or_removing_a_photo_deletes_the_old_file(): void
    {
        $this->update(['avatar' => UploadedFile::fake()->image('one.png', 300, 300)]);
        $first = $this->user->fresh()->avatar_path;

        $this->update(['avatar' => UploadedFile::fake()->image('two.png', 300, 300)]);
        $second = $this->user->fresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->update(['remove_avatar' => '1']);

        $this->assertNull($this->user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_invalid_photos_are_rejected(): void
    {
        $this->update(['avatar' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])->assertSessionHasErrors('avatar');
        $this->update(['avatar' => UploadedFile::fake()->image('tiny.png', 50, 50)])->assertSessionHasErrors('avatar');
        $this->update(['avatar' => UploadedFile::fake()->image('huge.jpg', 500, 500)->size(5000)])->assertSessionHasErrors('avatar');

        // An "image" whose bytes are not an image never gets stored.
        $fake = UploadedFile::fake()->createWithContent('evil.png', '<?php echo "hi";');
        $this->update(['avatar' => $fake])->assertSessionHasErrors('avatar');

        $this->assertNull($this->user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_photo_title_and_company_appear_on_public_pages(): void
    {
        $this->update(['job_title' => 'Head of Sales', 'company' => 'Acme & Co', 'avatar' => UploadedFile::fake()->image('me.jpg', 400, 400)]);
        $url = $this->user->fresh()->avatarUrl();
        $eventType = EventType::factory()->for($this->user)->create(['slug' => 'intro']);

        $this->get('/jane')->assertOk()->assertSee($url)->assertSee('Head of Sales at Acme &amp; Co', escape: false);
        $this->get('/jane/intro')->assertOk()->assertSee($url)->assertSee('Head of Sales at Acme &amp; Co', escape: false);

        $booking = Booking::factory()->at($eventType, '2030-01-07 10:00')->create();
        $this->get("/bookings/{$booking->uid}")->assertSee('Head of Sales at Acme &amp; Co', escape: false);
    }

    public function test_initials_are_shown_without_a_photo(): void
    {
        EventType::factory()->for($this->user)->create(['slug' => 'intro']);

        $this->get('/jane/intro')->assertOk()->assertSee('>JH<', escape: false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function update(array $data)
    {
        return $this->actingAs($this->user)->patch('/profile', array_merge([
            'name' => $this->user->name,
            'email' => $this->user->email,
            'username' => $this->user->username,
            'timezone' => $this->user->timezone,
        ], $data));
    }
}
