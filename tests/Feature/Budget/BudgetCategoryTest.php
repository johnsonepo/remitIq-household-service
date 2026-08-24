<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class BudgetCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function auth(User $user): static
    {
        return $this->withToken(JWTAuth::fromUser($user));
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/budget-categories')
            ->assertUnauthorized();
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/v1/budget-categories', [])
            ->assertUnauthorized();
    }

    public function test_show_requires_authentication(): void
    {
        $category = BudgetCategory::factory()->create();

        $this->getJson("/api/v1/budget-categories/{$category->id}")
            ->assertUnauthorized();
    }

    public function test_update_requires_authentication(): void
    {
        $category = BudgetCategory::factory()->create([
            'is_default' => false,
        ]);

        $this->patchJson("/api/v1/budget-categories/{$category->id}", ['name' => 'Updated'])->assertUnauthorized();
    }

    public function test_delete_requires_authentication(): void
    {
        $category = BudgetCategory::factory()->create([
            'is_default' => false,
        ]);

        $this->deleteJson("/api/v1/budget-categories/{$category->id}")->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Index
    |--------------------------------------------------------------------------
    */

    public function test_user_can_list_default_categories(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'is_default' => true,
            'name' => 'Food',
        ]);

        $this->auth($user)
            ->getJson('/api/v1/budget-categories')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $category->id,
                'name' => 'Food',
            ]);
    }

    public function test_user_can_list_own_custom_categories(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $user->id,
            'is_default' => false,
            'name' => 'School Fees',
        ]);

        $this->auth($user)
            ->getJson('/api/v1/budget-categories')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $category->id,
                'name' => 'School Fees',
            ]);
    }

    public function test_user_cannot_list_another_users_custom_category(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $owner->id,
            'is_default' => false,
            'name' => 'Private Category',
        ]);

        $response = $this->auth($attacker)
            ->getJson('/api/v1/budget-categories');

        $response->assertOk();

        $ids = collect($response->json('data'))
            ->pluck('id');

        $this->assertFalse($ids->contains($category->id));
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    public function test_user_can_create_custom_category(): void
    {
        $user = User::factory()->create();

        $response = $this->auth($user)
            ->postJson('/api/v1/budget-categories', [
                'name' => 'School Fees',
                'icon' => 'school',
                'color' => '#123456',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('budget_categories', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'name' => 'School Fees',
            'icon' => 'school',
            'color' => '#123456',
            'is_default' => false,
        ]);
    }

    public function test_user_id_cannot_be_injected_when_creating_category(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->auth($user)
            ->postJson('/api/v1/budget-categories', [
                'name' => 'Injected Category',
                'user_id' => $other->id,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('budget_categories', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseMissing('budget_categories', [
            'id' => $response->json('data.id'),
            'user_id' => $other->id,
        ]);
    }

    public function test_category_requires_name(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->postJson('/api/v1/budget-categories', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_category_name_must_be_string(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->postJson('/api/v1/budget-categories', [
                'name' => 123,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_category_name_has_maximum_length(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->postJson('/api/v1/budget-categories', [
                'name' => str_repeat('a', 256),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_category_icon_is_optional(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->postJson('/api/v1/budget-categories', [
                'name' => 'No Icon',
            ])
            ->assertCreated();
    }

    public function test_category_icon_may_be_null(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->postJson('/api/v1/budget-categories', [
                'name' => 'Null Icon',
                'icon' => null,
            ])
            ->assertCreated();
    }

    public function test_category_icon_has_maximum_length(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->postJson('/api/v1/budget-categories', [
                'name' => 'Long Icon',
                'icon' => str_repeat('a', 101),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['icon']);
    }

    public function test_category_color_is_optional(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->postJson('/api/v1/budget-categories', [
                'name' => 'No Color',
            ])
            ->assertCreated();
    }

    public function test_category_color_may_be_null(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->postJson('/api/v1/budget-categories', [
                'name' => 'Null Color',
                'color' => null,
            ])
            ->assertCreated();
    }

    public function test_category_color_must_be_valid_hex(): void
    {
        $user = User::factory()->create();

        $invalidColors = [
            'red',
            '#fff',
            '#12345',
            '#1234567',
            '123456',
            '#GGGGGG',
        ];

        foreach ($invalidColors as $color) {
            $this->auth($user)
                ->postJson('/api/v1/budget-categories', [
                    'name' => 'Invalid Color',
                    'color' => $color,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['color']);
        }
    }

    public function test_category_accepts_uppercase_hex_color(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->postJson('/api/v1/budget-categories', [
                'name' => 'Uppercase Color',
                'color' => '#AABBCC',
            ])
            ->assertCreated();
    }

    public function test_category_accepts_lowercase_hex_color(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->postJson('/api/v1/budget-categories', [
                'name' => 'Lowercase Color',
                'color' => '#aabbcc',
            ])
            ->assertCreated();
    }

    /*
    |--------------------------------------------------------------------------
    | Show
    |--------------------------------------------------------------------------
    */

    public function test_user_can_view_default_category(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        $this->auth($user)
            ->getJson("/api/v1/budget-categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $category->id);
    }

    public function test_user_can_view_own_custom_category(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $user->id,
            'is_default' => false,
        ]);

        $this->auth($user)
            ->getJson("/api/v1/budget-categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $category->id);
    }

    public function test_user_cannot_view_another_users_custom_category(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $owner->id,
            'is_default' => false,
        ]);

        $this->auth($attacker)
            ->getJson("/api/v1/budget-categories/{$category->id}")
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    public function test_user_can_update_own_custom_category(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $user->id,
            'is_default' => false,
        ]);

        $this->auth($user)
            ->patchJson("/api/v1/budget-categories/{$category->id}", [
                'name' => 'Updated Category',
                'icon' => 'updated',
                'color' => '#654321',
            ])
            ->assertOk();

        $this->assertDatabaseHas('budget_categories', [
            'id' => $category->id,
            'name' => 'Updated Category',
            'icon' => 'updated',
            'color' => '#654321',
        ]);
    }

    public function test_custom_category_update_is_partial(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $user->id,
            'is_default' => false,
            'name' => 'Original',
            'icon' => 'original',
            'color' => '#123456',
        ]);

        $this->auth($user)
            ->patchJson("/api/v1/budget-categories/{$category->id}", [
                'name' => 'Changed',
            ])
            ->assertOk();

        $category->refresh();

        $this->assertSame('Changed', $category->name);
        $this->assertSame('original', $category->icon);
        $this->assertSame('#123456', $category->color);
    }

    public function test_user_cannot_update_another_users_category(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $owner->id,
            'is_default' => false,
            'name' => 'Original',
        ]);

        $this->auth($attacker)
            ->patchJson("/api/v1/budget-categories/{$category->id}", ['name' => 'Hacked'])
            ->assertForbidden();

        $this->assertDatabaseHas('budget_categories', [
            'id' => $category->id,
            'name' => 'Original',
        ]);
    }

    public function test_default_category_cannot_be_updated(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        $this->auth($user)
            ->patchJson("/api/v1/budget-categories/{$category->id}", ['name' => 'Hacked'])
            ->assertForbidden();

        $this->assertDatabaseHas('budget_categories', [
            'id' => $category->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    public function test_user_can_delete_own_custom_category(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $user->id,
            'is_default' => false,
        ]);

        $this->auth($user)
            ->deleteJson("/api/v1/budget-categories/{$category->id}")
            ->assertOk();

        $this->assertDatabaseMissing('budget_categories', [
            'id' => $category->id,
        ]);
    }

    public function test_user_cannot_delete_another_users_category(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $owner->id,
            'is_default' => false,
        ]);

        $this->auth($attacker)
            ->deleteJson("/api/v1/budget-categories/{$category->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('budget_categories', [
            'id' => $category->id,
        ]);
    }

    public function test_default_category_cannot_be_deleted(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        $this->auth($user)
            ->deleteJson("/api/v1/budget-categories/{$category->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('budget_categories', [
            'id' => $category->id,
        ]);
    }

    public function test_unknown_category_returns_not_found(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->getJson('/api/v1/budget-categories/'.fake()->uuid())
            ->assertNotFound();
    }
}
