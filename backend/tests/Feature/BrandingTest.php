<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function testDefaultsAreServedWithoutSigningIn(): void
    {
        $this->getJson('/api/branding')
            ->assertOk()
            ->assertJsonPath('data.company_name', 'Inofex Retail')
            ->assertJsonPath('data.logo', null)
            ->assertJsonPath('data.favicon', null);
    }

    public function testAdminRenamesAndUploadsLogoAndFavicon(): void
    {
        $this->signIn();

        $this->post('/api/settings/company', [
            'company_name' => '  Sri Murugan Departmental Store ',
            'tagline' => 'Since 1998',
            'logo' => UploadedFile::fake()->image('logo.png', 1200, 600),
            'favicon' => UploadedFile::fake()->image('icon.png', 300, 200),
        ], ['Accept' => 'application/json'])->assertOk();

        $data = $this->getJson('/api/branding')->assertJsonPath('data.company_name', 'Sri Murugan Departmental Store')
            ->assertJsonPath('data.tagline', 'Since 1998')
            ->json('data');

        // The logo keeps its 2:1 shape inside 512 px; the favicon becomes a 128 px square.
        $logo = getimagesizefromstring(base64_decode(substr($data['logo'], strlen('data:image/png;base64,'))));
        $favicon = getimagesizefromstring(base64_decode(substr($data['favicon'], strlen('data:image/png;base64,'))));
        $this->assertSame([512, 256], [$logo[0], $logo[1]]);
        $this->assertSame([128, 128], [$favicon[0], $favicon[1]]);

        // Saving again without files keeps them; remove flags clear them.
        $this->post('/api/settings/company', ['company_name' => 'Sri Murugan'], ['Accept' => 'application/json'])->assertOk();
        $this->assertNotNull($this->getJson('/api/branding')->json('data.logo'));

        $this->post('/api/settings/company', ['company_name' => 'Sri Murugan', 'remove_logo' => 1, 'remove_favicon' => 1], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.logo', null)
            ->assertJsonPath('data.favicon', null);
    }

    public function testCompanyDetailsAreTheSellerOnDocuments(): void
    {
        $this->signIn();
        $store = Store::main();
        $store->update(['address' => null, 'gstin' => null, 'phone' => null]);

        $this->post('/api/settings/company', [
            'company_name' => 'Inofex Retail',
            'legal_name' => 'Inofex Retail Private Limited',
            'gstin' => '33abcde1234f1z5',
            'pan' => 'abcde1234f',
            'address' => '12 Gandhi Road',
            'city' => 'Coimbatore',
            'state_code' => '33',
            'pincode' => '641001',
            'phone' => '+91 98765 00000',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.gstin', '33ABCDE1234F1Z5')
            ->assertJsonPath('data.pan', 'ABCDE1234F');

        $seller = $this->getJson('/api/stores')->json('data.0.seller');
        $this->assertSame('Inofex Retail Private Limited', $seller['name']);
        $this->assertSame($store->name, $seller['branch']);
        $this->assertSame('12 Gandhi Road', $seller['address']);
        $this->assertSame('Tamil Nadu', $seller['state']);
        $this->assertSame('33ABCDE1234F1Z5', $seller['gstin']);

        // A store with its own address and GSTIN (another state) overrides them.
        $store->update(['address' => '5 MG Road', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'state_code' => '29', 'gstin' => '29ABCDE1234F1Z5']);
        $seller = $this->getJson('/api/stores')->json('data.0.seller');
        $this->assertSame(['5 MG Road', 'Karnataka', '29ABCDE1234F1Z5'], [$seller['address'], $seller['state'], $seller['gstin']]);

        $this->post('/api/settings/company', ['company_name' => 'X', 'gstin' => 'bad', 'pincode' => '12'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['gstin', 'pincode']);
    }

    public function testValidationAndAccess(): void
    {
        $this->signIn();
        $this->post('/api/settings/company', [
            'company_name' => '',
            'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['company_name', 'logo']);

        $this->signIn(['reports.view']);
        $this->post('/api/settings/company', ['company_name' => 'X'], ['Accept' => 'application/json'])->assertForbidden();
    }
}
