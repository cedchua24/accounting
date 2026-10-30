<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_creates_a_viewer_with_a_profile_and_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Juan Dela Cruz',
            'username' => 'juan.cruz',
            'email' => 'juan@example.test',
            'password' => 'Secure123',
            'password_confirmation' => 'Secure123',
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => false,
            'device_name' => 'web-registration',
            'profile' => [
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'country' => 'ph',
            ],
        ])->assertCreated()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.role', User::ROLE_VIEWER)
            ->assertJsonPath('user.is_active', true)
            ->assertJsonPath('user.profile.country', 'PH')
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseHas('users', [
            'id' => $response->json('user.id'),
            'role' => User::ROLE_VIEWER,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $response->json('user.id'),
            'name' => 'web-registration',
        ]);
    }

    public function test_registration_validates_unique_credentials_and_password_confirmation(): void
    {
        User::factory()->create([
            'username' => 'existing.user',
            'email' => 'existing@example.test',
        ]);

        $this->postJson('/api/register', [
            'name' => 'Duplicate User',
            'username' => 'existing.user',
            'email' => 'existing@example.test',
            'password' => 'Secure123',
            'password_confirmation' => 'Different123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'email', 'password']);
    }

    public function test_super_admin_can_create_a_user_with_credentials_and_profile(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]));

        $response = $this->postJson('/api/users', [
            'name' => 'Maria Santos',
            'username' => 'maria.santos',
            'email' => 'maria@example.test',
            'password' => 'Secure123',
            'password_confirmation' => 'Secure123',
            'role' => User::ROLE_ACCOUNTANT,
            'profile' => [
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'phone' => '+63 917 123 4567',
                'department' => 'Finance',
                'country' => 'ph',
            ],
        ])->assertCreated()
            ->assertJsonPath('username', 'maria.santos')
            ->assertJsonPath('role', User::ROLE_ACCOUNTANT)
            ->assertJsonPath('profile.department', 'Finance')
            ->assertJsonPath('profile.country', 'PH')
            ->assertJsonMissingPath('password');

        $user = User::findOrFail($response->json('id'));
        $this->assertTrue(Hash::check('Secure123', $user->password));
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]);
    }

    public function test_non_super_admin_cannot_manage_users(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->getJson('/api/users')
            ->assertForbidden()
            ->assertJsonPath('message', 'This action requires a super administrator.');
    }

    public function test_user_can_login_with_username_or_existing_email_field(): void
    {
        $user = User::factory()->create([
            'username' => 'super.accounting',
            'email' => 'super@example.test',
            'password' => 'Secure123',
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
        $user->profile()->create(['job_title' => 'Super Administrator']);

        $this->postJson('/api/login', [
            'login' => 'super.accounting',
            'password' => 'Secure123',
        ])->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.profile.job_title', 'Super Administrator')
            ->assertJsonStructure(['token']);

        $this->postJson('/api/login', [
            'email' => 'super@example.test',
            'password' => 'Secure123',
        ])->assertOk()->assertJsonPath('user.id', $user->id);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'username' => 'disabled.user',
            'password' => 'Secure123',
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'login' => 'disabled.user',
            'password' => 'Secure123',
        ])->assertUnprocessable()->assertJsonValidationErrors('login');
    }

    public function test_authenticated_user_can_logout_and_revoke_the_current_token(): void
    {
        $user = User::factory()->create([
            'username' => 'logout.user',
            'email' => 'logout@example.test',
            'password' => 'Secure123',
        ]);

        $token = $this->postJson('/api/login', [
            'login' => 'logout.user',
            'password' => 'Secure123',
            'device_name' => 'logout-test',
        ])->assertOk()->json('token');

        $this->withToken($token)->postJson('/api/logout')->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'logout-test',
        ]);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/users')->assertUnauthorized();
    }

    public function test_last_active_super_admin_cannot_be_demoted_deactivated_or_deleted(): void
    {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/users/{$superAdmin->id}", ['role' => User::ROLE_ADMIN])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->putJson("/api/users/{$superAdmin->id}", ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->deleteJson("/api/users/{$superAdmin->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');
    }

    public function test_super_admin_can_update_profile_and_delete_another_user(): void
    {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $user = User::factory()->create();
        $user->profile()->create(['department' => 'Operations']);
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/users/{$user->id}", [
            'role' => User::ROLE_VIEWER,
            'profile' => ['department' => 'Audit', 'job_title' => 'Reviewer'],
        ])->assertOk()
            ->assertJsonPath('role', User::ROLE_VIEWER)
            ->assertJsonPath('profile.department', 'Audit');

        $this->deleteJson("/api/users/{$user->id}")->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('user_profiles', ['user_id' => $user->id]);
    }
}
