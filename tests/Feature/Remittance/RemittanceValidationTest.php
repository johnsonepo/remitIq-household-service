<?php

namespace Tests\Feature\Remittance;

use App\Models\Household;
use App\Models\Remittance;
use App\Models\TransferProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemittanceValidationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_CREATE_PAYLOAD = [
        'amount_sent' => 100,
        'sent_currency_code' => 'USD',
        'amount_received' => 60000,
        'received_currency_code' => 'XAF',
        'exchange_rate' => 600,
        'rate_source' => 'market-service-official',
        'sent_at' => '2026-08-20',
        'notes' => 'Test remittance',
    ];

    protected function authenticatedUser(): User
    {
        return User::factory()->create();
    }

    protected function household(User $user): Household
    {
        return Household::factory()->create([
            'owner_id' => $user->id,
        ]);
    }

    protected function provider(): TransferProvider
    {
        return TransferProvider::factory()->create();
    }

    protected function createPayload(
        Household $household,
        array $overrides = []
    ): array {
        return array_merge(
            self::BASE_CREATE_PAYLOAD,
            [
                'household_id' => $household->id,
                'transfer_provider_id' => $this->provider()->id,
            ],
            $overrides
        );
    }

    protected function createRemittance(
        User $user,
        Household $household,
        array $overrides = []
    ): Remittance {
        return Remittance::factory()->create(array_merge([
            'user_id' => $user->id,
            'household_id' => $household->id,
            'transfer_provider_id' => $this->provider()->id,
            'amount_sent' => 100,
            'sent_currency_code' => 'USD',
            'amount_received' => 60000,
            'received_currency_code' => 'XAF',
            'exchange_rate' => 600,
            'rate_source' => 'market-service-official',
            'sent_at' => '2026-08-20',
            'notes' => 'Test remittance',
        ], $overrides));
    }

    protected function createEndpoint(): string
    {
        return '/api/v1/remittances';
    }

    protected function historyEndpoint(): string
    {
        return '/api/v1/remittances/history';
    }

    protected function analyticsEndpoint(): string
    {
        return '/api/v1/remittances/analytics';
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function test_create_requires_authentication(): void
    {
        $this->postJson($this->createEndpoint())
            ->assertUnauthorized();
    }

    public function test_update_requires_authentication(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->putJson(
            "{$this->createEndpoint()}/{$remittance->id}",
            ['amount_sent' => 200]
        )->assertUnauthorized();
    }

    public function test_history_requires_authentication(): void
    {
        $this->getJson($this->historyEndpoint())
            ->assertUnauthorized();
    }

    public function test_analytics_requires_authentication(): void
    {
        $this->getJson($this->analyticsEndpoint())
            ->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Create - required fields
    |--------------------------------------------------------------------------
    */

    public function test_create_requires_household_id(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household);
        unset($payload['household_id']);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['household_id']);
    }

    public function test_create_requires_amount_sent(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household);
        unset($payload['amount_sent']);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_sent']);
    }

    public function test_create_requires_sent_currency_code(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household);
        unset($payload['sent_currency_code']);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sent_currency_code']);
    }

    public function test_create_requires_amount_received(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household);
        unset($payload['amount_received']);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_received']);
    }

    public function test_create_requires_received_currency_code(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household);
        unset($payload['received_currency_code']);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['received_currency_code']);
    }

    public function test_create_requires_exchange_rate(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household);
        unset($payload['exchange_rate']);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exchange_rate']);
    }

    public function test_create_requires_sent_at(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household);
        unset($payload['sent_at']);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sent_at']);
    }

    /*
    |--------------------------------------------------------------------------
    | Create - household/provider identifiers
    |--------------------------------------------------------------------------
    */

    public function test_create_rejects_invalid_household_uuid(): void
    {
        $user = $this->authenticatedUser();

        $payload = array_merge(self::BASE_CREATE_PAYLOAD, [
            'household_id' => 'not-a-uuid',
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['household_id']);
    }

    public function test_create_rejects_unknown_household(): void
    {
        $user = $this->authenticatedUser();

        $payload = array_merge(self::BASE_CREATE_PAYLOAD, [
            'household_id' => fake()->uuid(),
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['household_id']);
    }

    public function test_create_rejects_invalid_transfer_provider_uuid(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'transfer_provider_id' => 'not-a-uuid',
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transfer_provider_id']);
    }

    public function test_create_rejects_unknown_transfer_provider(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'transfer_provider_id' => fake()->uuid(),
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transfer_provider_id']);
    }

    public function test_create_allows_nullable_transfer_provider(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'transfer_provider_id' => null,
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertCreated()
            ->assertJsonPath('data.transfer_provider_id', null);
    }

    /*
    |--------------------------------------------------------------------------
    | Create - monetary values
    |--------------------------------------------------------------------------
    */

    public function test_create_rejects_zero_amount_sent(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'amount_sent' => 0,
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_sent']);
    }

    public function test_create_rejects_negative_amount_sent(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'amount_sent' => -1,
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_sent']);
    }

    public function test_create_accepts_decimal_amount_sent(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'amount_sent' => 100.50,
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertCreated();
    }

    public function test_create_rejects_zero_amount_received(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'amount_received' => 0,
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_received']);
    }

    public function test_create_rejects_negative_amount_received(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'amount_received' => -1,
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_received']);
    }

    public function test_create_rejects_zero_exchange_rate(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'exchange_rate' => 0,
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exchange_rate']);
    }

    public function test_create_rejects_negative_exchange_rate(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'exchange_rate' => -0.01,
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exchange_rate']);
    }

    /*
    |--------------------------------------------------------------------------
    | Create - currency validation
    |--------------------------------------------------------------------------
    */

    public function test_create_accepts_valid_three_letter_uppercase_currency_codes(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'sent_currency_code' => 'EUR',
            'received_currency_code' => 'XAF',
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertCreated();
    }

    public function test_create_rejects_lowercase_sent_currency_code(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'sent_currency_code' => 'usd',
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sent_currency_code']);
    }

    public function test_create_rejects_lowercase_received_currency_code(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'received_currency_code' => 'xaf',
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['received_currency_code']);
    }

    public function test_create_rejects_currency_code_with_wrong_length(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'sent_currency_code' => 'US',
            'received_currency_code' => 'EURO',
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'sent_currency_code',
                'received_currency_code',
            ]);
    }

    public function test_create_rejects_non_alphabetic_currency_code(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'sent_currency_code' => 'U1D',
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sent_currency_code']);
    }

    /*
    |--------------------------------------------------------------------------
    | Create - date and optional fields
    |--------------------------------------------------------------------------
    */

    public function test_create_accepts_valid_sent_date(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'sent_at' => '2026-12-31',
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertCreated();
    }

    public function test_create_rejects_invalid_sent_date_format(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'sent_at' => '31-12-2026',
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sent_at']);
    }

    public function test_create_rejects_invalid_calendar_date(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'sent_at' => '2026-02-30',
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sent_at']);
    }

    public function test_create_allows_nullable_rate_source(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'rate_source' => null,
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertCreated();
    }

    public function test_create_allows_nullable_notes(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'notes' => null,
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertCreated();
    }

    public function test_create_rejects_rate_source_longer_than_255_characters(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'rate_source' => str_repeat('a', 256),
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rate_source']);
    }

    /*
    |--------------------------------------------------------------------------
    | Update - partial validation
    |--------------------------------------------------------------------------
    */

    public function test_update_accepts_partial_payload(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['amount_sent' => 250]
            )
            ->assertOk()
            ->assertJsonPath('data.amount_sent', '250.00');
    }

    public function test_update_allows_nullable_transfer_provider(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['transfer_provider_id' => null]
            )
            ->assertOk()
            ->assertJsonPath('data.transfer_provider_id', null);
    }

    public function test_update_allows_nullable_rate_source(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['rate_source' => null]
            )
            ->assertOk()
            ->assertJsonPath('data.rate_source', null);
    }

    public function test_update_allows_nullable_notes(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['notes' => null]
            )
            ->assertOk()
            ->assertJsonPath('data.notes', null);
    }

    public function test_update_rejects_zero_amount_sent(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['amount_sent' => 0]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_sent']);
    }

    public function test_update_rejects_negative_amount_received(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['amount_received' => -10]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_received']);
    }

    public function test_update_rejects_zero_exchange_rate(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['exchange_rate' => 0]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exchange_rate']);
    }

    public function test_update_rejects_invalid_currency_code(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['sent_currency_code' => 'usd']
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sent_currency_code']);
    }

    public function test_update_rejects_invalid_date_format(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['sent_at' => '20/08/2026']
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sent_at']);
    }

    public function test_update_rejects_unknown_household(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['household_id' => fake()->uuid()]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['household_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | History validation
    |--------------------------------------------------------------------------
    */

    public function test_history_accepts_no_filters(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'api')
            ->getJson($this->historyEndpoint())
            ->assertOk();
    }

    public function test_history_rejects_invalid_household_uuid(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'api')
            ->getJson($this->historyEndpoint() . '?household_id=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['household_id']);
    }

    public function test_history_rejects_unknown_household(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'api')
            ->getJson(
                $this->historyEndpoint() . '?household_id=' . fake()->uuid()
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['household_id']);
    }

    public function test_history_rejects_invalid_provider_uuid(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'api')
            ->getJson($this->historyEndpoint() . '?transfer_provider_id=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transfer_provider_id']);
    }

    public function test_history_rejects_invalid_from_date(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'api')
            ->getJson($this->historyEndpoint() . '?from=20-08-2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from']);
    }

    public function test_history_rejects_invalid_to_date(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'api')
            ->getJson($this->historyEndpoint() . '?to=20-08-2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }

    public function test_history_accepts_valid_date_range(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'api')
            ->getJson(
                $this->historyEndpoint() .
                '?from=2026-01-01&to=2026-08-31'
            )
            ->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Analytics validation
    |--------------------------------------------------------------------------
    */

    public function test_analytics_accepts_no_filters(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'api')
            ->getJson($this->analyticsEndpoint())
            ->assertOk();
    }

    public function test_analytics_rejects_invalid_household_uuid(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'api')
            ->getJson($this->analyticsEndpoint() . '?household_id=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['household_id']);
    }

    public function test_analytics_rejects_invalid_provider_uuid(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'api')
            ->getJson(
                $this->analyticsEndpoint() .
                '?transfer_provider_id=invalid'
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transfer_provider_id']);
    }

    public function test_analytics_rejects_invalid_from_date(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'api')
            ->getJson(
                $this->analyticsEndpoint() .
                '?from=20-08-2026'
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from']);
    }

    public function test_analytics_rejects_invalid_to_date(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'api')
            ->getJson(
                $this->analyticsEndpoint() .
                '?to=20-08-2026'
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }

    public function test_analytics_rejects_to_date_before_from_date(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'api')
            ->getJson(
                $this->analyticsEndpoint() .
                '?from=2026-08-31&to=2026-08-01'
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }

    public function test_analytics_accepts_equal_from_and_to_dates(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'api')
            ->getJson(
                $this->analyticsEndpoint() .
                '?from=2026-08-20&to=2026-08-20'
            )
            ->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Boundary and payload behavior
    |--------------------------------------------------------------------------
    */

    public function test_update_with_empty_payload_is_allowed_by_current_rules(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                []
            )
            ->assertOk();
    }

    public function test_create_rejects_non_numeric_amount_sent(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'amount_sent' => 'abc',
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_sent']);
    }

    public function test_create_rejects_non_numeric_amount_received(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'amount_received' => 'abc',
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_received']);
    }

    public function test_create_rejects_non_numeric_exchange_rate(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);

        $payload = $this->createPayload($household, [
            'exchange_rate' => 'abc',
        ]);

        $this->actingAs($user, 'api')
            ->postJson($this->createEndpoint(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exchange_rate']);
    }

    public function test_update_rejects_rate_source_longer_than_255_characters(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['rate_source' => str_repeat('a', 256)]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rate_source']);
    }

    public function test_update_rejects_invalid_transfer_provider_uuid(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['transfer_provider_id' => 'invalid']
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transfer_provider_id']);
    }

    public function test_update_rejects_unknown_transfer_provider(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['transfer_provider_id' => fake()->uuid()]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transfer_provider_id']);
    }

    public function test_update_rejects_currency_code_with_wrong_length(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['received_currency_code' => 'XAFR']
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['received_currency_code']);
    }

    public function test_update_rejects_non_alphabetic_currency_code(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['sent_currency_code' => 'U1D']
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sent_currency_code']);
    }

    public function test_update_accepts_valid_currency_codes(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                [
                    'sent_currency_code' => 'EUR',
                    'received_currency_code' => 'XAF',
                ]
            )
            ->assertOk();
    }

    public function test_update_accepts_valid_sent_date(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['sent_at' => '2026-12-31']
            )
            ->assertOk();
    }

    public function test_update_rejects_invalid_calendar_date(): void
    {
        $user = $this->authenticatedUser();
        $household = $this->household($user);
        $remittance = $this->createRemittance($user, $household);

        $this->actingAs($user, 'api')
            ->putJson(
                "{$this->createEndpoint()}/{$remittance->id}",
                ['sent_at' => '2026-02-30']
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sent_at']);
    }
}
