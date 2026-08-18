<?php

namespace Tests\Feature\Filament;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Enums\SuggestionAttachmentType;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Bid;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::create([
            'first_name' => 'مدیر',
            'last_name' => 'سامانه',
            'mobile' => '09120000001',
            'national_id' => '0499370899',
            'person_type' => PersonType::Individual,
            'password' => Hash::make('Secret123'),
            'mobile_verified_at' => now(),
            'is_active' => true,
        ]);
        $admin->assignRole(RoleName::Admin->value);

        return $admin;
    }

    public function test_admin_can_create_a_staff_user_without_otp(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->admin();

        $this->actingAs($admin);
        $this->get(UserResource::getUrl('create'))->assertOk();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'first_name' => 'سارا',
                'last_name' => 'احمدی',
                'mobile' => '09129998877',
                'national_id' => '1234567891',
                'person_type' => 'individual',
                'password' => 'Secret123',
                'role' => 'staff',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $staff = User::where('mobile', '09129998877')->first();

        $this->assertNotNull($staff);
        $this->assertNotNull($staff->mobile_verified_at, 'admin-created accounts skip OTP entirely');
        $this->assertTrue($staff->hasRole(RoleName::Staff->value));
        $this->assertSame($admin->id, $staff->created_by);
    }

    /**
     * Switching نوع شخص to حقوقی must reveal نام شرکت and شناسه ملی.
     *
     * This pins a real bug: `->options(PersonType::class)` makes Filament
     * hand back a PersonType *object* from $get('person_type'), so the old
     * `=== PersonType::Company->value` string comparison never matched and
     * the two fields stayed hidden. See UserForm::isCompany().
     *
     * The edit form is used rather than create because that is where the
     * state starts life as an enum straight off the model.
     */
    public function test_company_fields_appear_when_person_type_is_switched_to_company(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->admin();

        $individual = User::create([
            'first_name' => 'رضا',
            'last_name' => 'کریمی',
            'mobile' => '09121114455',
            'national_id' => '1234567891',
            'person_type' => PersonType::Individual,
            'password' => Hash::make('Secret123'),
            'mobile_verified_at' => now(),
            'is_active' => true,
        ]);
        $individual->assignRole(RoleName::User->value);

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $individual->getRouteKey()])
            ->assertFormFieldIsHidden('company_name')
            ->assertFormFieldIsHidden('company_national_id')
            ->fillForm(['person_type' => PersonType::Company->value])
            ->assertFormFieldIsVisible('company_name')
            ->assertFormFieldIsVisible('company_national_id')
            ->fillForm([
                'company_name' => 'شرکت نمونه',
                'company_national_id' => '12345678901',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $individual->refresh();
        $this->assertSame(PersonType::Company, $individual->person_type);
        $this->assertSame('شرکت نمونه', $individual->company_name);
    }

    /*
     * ---- Deleting an account ----------------------------------------------
     */

    /** A plain کاربر, ready to have bids hung off them. */
    private function bidder(string $mobile = '09121234567', string $nationalId = '1234567891'): User
    {
        $user = User::create([
            'first_name' => 'حسن',
            'last_name' => 'مرادی',
            'mobile' => $mobile,
            'national_id' => $nationalId,
            'person_type' => PersonType::Individual,
            'password' => Hash::make('Secret123'),
            'mobile_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole(RoleName::User->value);

        return $user;
    }

    /**
     * The whole point of the feature: the account goes, its پیشنهادها go,
     * their price lines and attachment rows go, and the uploaded FILES go —
     * the last of which no database cascade would have done.
     */
    public function test_deleting_a_user_removes_their_suggestions_and_their_files(): void
    {
        $this->seed(RoleSeeder::class);
        Storage::fake('public');

        $admin = $this->admin();
        $user = $this->bidder();

        $bid = Bid::create([
            'title' => 'مناقصه تست',
            'description' => 'x',
            'start_at' => now()->subDay(),
            'expire_at' => now()->addDay(),
            'created_by' => $admin->id,
        ]);

        $suggestion = $bid->suggestions()->create([
            'user_id' => $user->id,
            'note' => 'پیشنهاد',
            'submitted_at' => now(),
        ]);

        Storage::disk('public')->put('bid-suggestions/receipt.pdf', 'x');
        $suggestion->attachments()->create([
            'type' => SuggestionAttachmentType::BankGuaranteeLetter,
            'disk' => 'public',
            'path' => 'bid-suggestions/receipt.pdf',
            'original_name' => 'receipt.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($user));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('bid_suggestions', ['id' => $suggestion->id]);
        Storage::disk('public')->assertMissing('bid-suggestions/receipt.pdf');
    }

    /** Admins are off limits — for other admins and for themselves. */
    public function test_an_admin_cannot_be_deleted(): void
    {
        $this->seed(RoleSeeder::class);

        $admin = $this->admin();
        $otherAdmin = $this->bidder('09121234599', '0084575948');
        $otherAdmin->syncRoles([RoleName::Admin->value]);

        $this->assertFalse($admin->can('delete', $otherAdmin));
        $this->assertFalse($admin->can('delete', $admin));
        $this->assertTrue($admin->can('delete', $this->bidder('09121234588', '0013542419')));
    }

    /**
     * `bids.created_by` cascades, so deleting the publisher of a tender
     * would take that tender — and every other user's bid on it — with it.
     * The action must refuse rather than do that quietly.
     */
    public function test_deleting_a_user_who_published_tenders_is_refused(): void
    {
        $this->seed(RoleSeeder::class);

        $admin = $this->admin();
        $staff = $this->bidder('09121234577');
        $staff->syncRoles([RoleName::Staff->value]);

        $bid = Bid::create([
            'title' => 'مناقصه کارشناس',
            'description' => 'x',
            'start_at' => now()->subDay(),
            'expire_at' => now()->addDay(),
            'created_by' => $staff->id,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($staff));

        $this->assertDatabaseHas('users', ['id' => $staff->id]);
        $this->assertDatabaseHas('bids', ['id' => $bid->id]);
    }

    public function test_non_admin_cannot_access_user_resource(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::create([
            'first_name' => 'کاربر',
            'last_name' => 'عادی',
            'mobile' => '09121112233',
            'national_id' => '1234567891',
            'person_type' => PersonType::Individual,
            'password' => Hash::make('Secret123'),
            'mobile_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole(RoleName::User->value);

        $this->actingAs($user);
        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }
}
