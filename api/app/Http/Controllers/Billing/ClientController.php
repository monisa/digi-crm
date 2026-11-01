<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\EmployeeInvite;
use App\Services\Billing\Gst;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])]]);
        $user = $request->user();

        $clients = Client::visibleTo($user)->with('manager:id,name')
            ->when(($f['status'] ?? 'active') !== 'all', fn ($q) => $q->where('status', $f['status'] ?? 'active'))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('company_name', 'like', "%$s%")
                ->orWhere('contact_name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('city', 'like', "%$s%")))
            ->withSum(['invoices as outstanding' => fn ($q) => $q->whereIn('status', ['unpaid', 'partial'])], \DB::raw('total - amount_paid'))
            ->orderBy('company_name')->get();

        return response()->json(['data' => $clients->map(fn (Client $c) => [...$c->toApiArray(), 'outstanding' => round((float) $c->outstanding, 2)])]);
    }

    public function show(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClient($request->user(), $client);
        $inv = Invoice::where('client_id', $client->id)->where('status', '!=', 'draft')->where('status', '!=', 'cancelled');

        return response()->json([
            ...$client->load('manager:id,name')->toApiArray(),
            'onboarding' => $client->onboarding_data,
            'has_credentials' => (bool) $client->credentials,
            'files' => $client->files->map->toApiArray(),
            'logins' => $client->users()->get(['id', 'name', 'email', 'status', 'last_login_at']),
            'stats' => [
                'billed' => round((float) (clone $inv)->sum('total'), 2),
                'paid' => round((float) (clone $inv)->sum('amount_paid'), 2),
                'outstanding' => round((float) (clone $inv)->sum(\DB::raw('total - amount_paid')), 2),
                'overdue' => (clone $inv)->whereIn('status', ['unpaid', 'partial'])->whereDate('due_date', '<', today())->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['account_manager_id'] ??= $request->user()->isClient() ? null : $request->user()->id;
        $client = Client::create($data);

        return response()->json($client->load('manager:id,name')->toApiArray(), 201);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClient($request->user(), $client);
        $client->update($this->validated($request, partial: true));

        return response()->json($client->fresh('manager:id,name')->toApiArray());
    }

    /** Create a portal login for the client and email a link to set the password. */
    public function invite(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClient($request->user(), $client);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
        ], ['email.unique' => 'There is already a login with this email.']);

        $user = new User;
        $user->forceFill([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'password' => Str::password(32),
            'role' => User::ROLE_CLIENT,
            'client_id' => $client->id,
            'company_name' => $client->company_name,
            // They still fill in the onboarding form on first login.
            'status' => $client->onboarding_data ? User::STATUS_ACTIVE : User::STATUS_PENDING_ONBOARDING,
        ])->save();
        $user->notify(new EmployeeInvite(Password::createToken($user)));

        return response()->json(['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => $user->status], 201);
    }

    private function authorizeClient(User $user, Client $client): void
    {
        abort_unless(Client::visibleTo($user)->whereKey($client->id)->exists(), 403, 'This client is managed by someone else.');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'company_name' => [$req, 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'industry' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string', 'max:190'],
            'gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', Rule::in([...array_values(Gst::STATES), 'Outside India'])],
            'pincode' => ['nullable', 'string', 'max:10'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'account_manager_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', ['admin', 'employee'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['gstin.regex' => 'Enter a valid 15-character GSTIN.']);
    }
}
