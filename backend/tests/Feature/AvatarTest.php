<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    public function testUploadedPhotoIsCroppedToASmallSquareAndShownInTheProfile(): void
    {
        $this->signIn(['billing.create']);

        $avatar = $this->post('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 1200, 800)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.avatar');

        $this->assertStringStartsWith('data:image/jpeg;base64,', $avatar);
        [$width, $height] = getimagesizefromstring(base64_decode(substr($avatar, strlen('data:image/jpeg;base64,'))));
        $this->assertSame([256, 256], [$width, $height]);

        $this->getJson('/api/me')->assertJsonPath('user.avatar', $avatar);
    }

    public function testPhotoCanBeRemoved(): void
    {
        $this->signIn();
        $this->post('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg')], ['Accept' => 'application/json'])->assertOk();

        $this->deleteJson('/api/me/avatar')->assertOk()->assertJsonPath('data.avatar', null);
        $this->getJson('/api/me')->assertJsonPath('user.avatar', null);
    }

    public function testOnlyImagesUpToFiveMegabytesAreAccepted(): void
    {
        $this->signIn();

        $this->post('/api/me/avatar', ['avatar' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->post('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('big.jpg')->size(6000)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');
    }

    public function testUsersCanChangeTheirNameWithoutAPassword(): void
    {
        $this->signIn(['billing.create'], ['name' => 'Karthik', 'email' => 'karthik@store.com']);

        $this->putJson('/api/me', ['name' => '  Karthik Raja  ', 'email' => 'KARTHIK@store.com'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Karthik Raja')
            ->assertJsonPath('data.email', 'karthik@store.com');

        $this->putJson('/api/me', ['name' => '', 'email' => 'karthik@store.com'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function testChangingTheEmailNeedsTheCurrentPasswordAndAFreeAddress(): void
    {
        $user = $this->signIn(['billing.create'], ['email' => 'karthik@store.com', 'password' => 'secret123']);
        User::factory()->create(['email' => 'taken@store.com']);

        $this->putJson('/api/me', ['name' => 'Karthik', 'email' => 'new@store.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/me', ['name' => 'Karthik', 'email' => 'new@store.com', 'current_password' => 'wrong999'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/me', ['name' => 'Karthik', 'email' => 'taken@store.com', 'current_password' => 'secret123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->putJson('/api/me', ['name' => 'Karthik', 'email' => 'not-an-email', 'current_password' => 'secret123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->putJson('/api/me', ['name' => 'Karthik', 'email' => 'New@Store.com', 'current_password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('data.email', 'new@store.com');
        $this->assertSame('new@store.com', $user->fresh()->email);
    }

    public function testGuestsCannotUpload(): void
    {
        $this->post('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg')], ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }
}
