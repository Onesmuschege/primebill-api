<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantRegistrationController extends Controller
{
    use ApiResponse;

    // POST /api/tenants/register
    // Public — this is how a new ISP signs up for PrimeBill itself.
    public function register(Request $request)
    {
        $request->validate([
            'company_name'   => 'required|string|max:255',
            'admin_name'     => 'required|string|max:255',
            'admin_email'    => 'required|email|unique:users,email',
            'admin_password' => 'required|string|min:8|confirmed',
            'timezone'       => 'nullable|string|max:64',
            'currency'       => 'nullable|string|size:3',
        ]);

        $slug = $this->generateUniqueSlug($request->company_name);

        $result = DB::transaction(function () use ($request, $slug) {
            $tenant = Tenant::create([
                'name'     => $request->company_name,
                'slug'     => $slug,
                'status'   => 'trial',
                'plan'     => 'starter',
                'timezone' => $request->timezone ?? 'Africa/Nairobi',
                'currency' => strtoupper($request->currency ?? 'KES'),
            ]);

            $user = User::create([
                'name'      => $request->admin_name,
                'email'     => $request->admin_email,
                'password'  => Hash::make($request->admin_password),
            ]);

            // tenant_id is deliberately excluded from User::$fillable for
            // security (see BelongsToTenant's docblock on User) — set it
            // explicitly here rather than relying on mass assignment.
            $user->tenant_id = $tenant->id;
            $user->save();

            $user->assignRole('admin');

            $this->seedDefaultSettings($tenant);

            return [$tenant, $user];
        });

        [$tenant, $user] = $result;

        $token = $user->createToken('auth_token', ['admin'])->plainTextToken;

        return $this->success([
            'tenant' => [
                'id'   => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
            ],
            'token' => $token,
        ], 'ISP workspace created successfully', 201);
    }

    // GET /api/tenants/check-slug?name=Acme+ISP
    // Lets the signup form show a live preview of the slug before submitting.
    public function checkSlug(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);

        return $this->success([
            'slug' => $this->generateUniqueSlug($request->name, dryRun: true),
        ]);
    }

    protected function generateUniqueSlug(string $name, bool $dryRun = false): string
    {
        $base = Str::slug($name) ?: 'isp';
        $slug = $base;
        $i    = 1;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = "{$base}-" . ++$i;
        }

        return $slug;
    }

    protected function seedDefaultSettings(Tenant $tenant): void
    {
        $defaults = [
            ['key' => 'company_name',           'value' => $tenant->name,   'group' => 'company'],
            ['key' => 'invoice_prefix',         'value' => 'INV',           'group' => 'billing'],
            ['key' => 'tax_rate',               'value' => '0',             'group' => 'billing'],
            ['key' => 'grace_period',           'value' => '3',             'group' => 'billing'],
            ['key' => 'currency',               'value' => $tenant->currency, 'group' => 'billing'],
            ['key' => 'timezone',               'value' => $tenant->timezone, 'group' => 'system'],
            ['key' => 'date_format',            'value' => 'd/m/Y',         'group' => 'system'],
            ['key' => 'portal_business_name',   'value' => $tenant->name,  'group' => 'portal'],
            ['key' => 'portal_welcome_message', 'value' => 'Select a plan and pay via M-Pesa', 'group' => 'portal'],
            ['key' => 'portal_primary_color',   'value' => '#2563eb',      'group' => 'portal'],
            ['key' => 'portal_secondary_color', 'value' => '#06b6d4',      'group' => 'portal'],
            ['key' => 'portal_support_phone',   'value' => '',             'group' => 'portal'],
            ['key' => 'portal_terms_text',      'value' => '',             'group' => 'portal'],
        ];

        foreach ($defaults as $setting) {
            // tenant_id isn't in Setting::$fillable (also deliberate —
            // keeping it out of every model's fillable avoids any
            // mass-assignment path letting a request spoof another
            // tenant's ID). Set explicitly instead of via create().
            $model = new Setting($setting);
            $model->tenant_id = $tenant->id;
            $model->save();
        }
    }
}
