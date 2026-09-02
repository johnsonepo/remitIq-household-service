<?php

namespace Tests\Feature\Remittance;

use App\Models\TransferProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TransferProviderTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = '/api/v1/transfer-providers';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Transfer provider operations are part of the household service.
         * Keep external HTTP dependencies isolated from feature tests.
         */
        Http::fake();
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson(self::BASE_URL);

        $response->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Index
    |--------------------------------------------------------------------------
    */

    public function test_authenticated_user_can_list_transfer_providers(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        TransferProvider::factory()->create([
            'name' => 'MoneyGram',
            'logo_url' => null,
            'is_active' => true,
        ]);

        TransferProvider::factory()->create([
            'name' => 'Wise',
            'logo_url' => 'https://example.com/wise.png',
            'is_active' => true,
        ]);

        $response = $this->getJson(self::BASE_URL);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'logo_url',
                        'is_active',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'meta',
                'timestamp',
            ]);

        $response->assertJsonPath('success', true);

        $response->assertJsonPath(
            'message',
            'Transfer providers retrieved successfully.'
        );

        $response->assertJsonCount(2, 'data');
    }

    public function test_index_returns_only_active_transfer_providers(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        TransferProvider::factory()->create([
            'name' => 'MoneyGram',
            'is_active' => true,
        ]);

        TransferProvider::factory()->create([
            'name' => 'Wise',
            'is_active' => true,
        ]);

        TransferProvider::factory()->create([
            'name' => 'Inactive Provider',
            'is_active' => false,
        ]);

        $response = $this->getJson(self::BASE_URL);

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'name' => 'MoneyGram',
                'is_active' => true,
            ])
            ->assertJsonFragment([
                'name' => 'Wise',
                'is_active' => true,
            ])
            ->assertJsonMissing([
                'name' => 'Inactive Provider',
            ]);
    }

    public function test_index_orders_transfer_providers_by_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        TransferProvider::factory()->create([
            'name' => 'Wise',
            'is_active' => true,
        ]);

        TransferProvider::factory()->create([
            'name' => 'MoneyGram',
            'is_active' => true,
        ]);

        TransferProvider::factory()->create([
            'name' => 'Western Union',
            'is_active' => true,
        ]);

        $response = $this->getJson(self::BASE_URL);

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.0.name',
                'MoneyGram'
            )
            ->assertJsonPath(
                'data.1.name',
                'Western Union'
            )
            ->assertJsonPath(
                'data.2.name',
                'Wise'
            );
    }

    public function test_index_returns_transfer_provider_data(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $provider = TransferProvider::factory()->create([
            'name' => 'Wise',
            'logo_url' => 'https://example.com/wise.png',
            'is_active' => true,
        ]);

        $response = $this->getJson(self::BASE_URL);

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.0.id',
                $provider->id
            )
            ->assertJsonPath(
                'data.0.name',
                'Wise'
            )
            ->assertJsonPath(
                'data.0.logo_url',
                'https://example.com/wise.png'
            )
            ->assertJsonPath(
                'data.0.is_active',
                true
            );
    }

    public function test_index_returns_empty_data_when_no_active_providers_exist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        TransferProvider::factory()->create([
            'name' => 'Inactive Provider',
            'is_active' => false,
        ]);

        $response = $this->getJson(self::BASE_URL);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'Transfer providers retrieved successfully.'
            )
            ->assertJsonCount(0, 'data');
    }
}
