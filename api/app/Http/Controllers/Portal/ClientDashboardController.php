<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Invoice;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The client's home page: what's running, what to pay, what's open. */
class ClientDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isClient() && $user->client_id, 404);
        $client = $user->client()->with('manager:id,name,email,phone')->first();

        $open = Invoice::visibleTo($user)->whereIn('status', ['unpaid', 'partial'])->orderBy('due_date');
        $next = (clone $open)->first();
        $campaigns = Campaign::visibleTo($user)->with('client:id,company_name')
            ->whereIn('status', ['active', 'planned'])->orderByRaw("case status when 'active' then 0 else 1 end")->limit(6)->get();

        return response()->json([
            'company' => $client->company_name,
            'account_manager' => $client->manager ? $client->manager->only(['name', 'email', 'phone']) : null,
            'billing' => [
                'to_pay' => round((float) (clone $open)->get()->sum(fn (Invoice $i) => $i->balance()), 2),
                'overdue' => (clone $open)->whereDate('due_date', '<', today())->count(),
                'next' => $next ? ['id' => $next->id, 'invoice_no' => $next->invoice_no, 'balance' => $next->balance(), 'due_date' => $next->due_date->toDateString()] : null,
            ],
            'campaigns' => $campaigns->map->toApiArray(),
            'totals' => [
                'active_campaigns' => Campaign::visibleTo($user)->where('status', 'active')->count(),
                'leads' => (int) Campaign::visibleTo($user)->where('status', 'active')->sum('leads'),
                'spend' => round((float) Campaign::visibleTo($user)->where('status', 'active')->sum('spend'), 2),
            ],
            'tickets' => [
                'open' => Ticket::visibleTo($user)->whereIn('status', Ticket::OPEN)->count(),
                'replied' => Ticket::visibleTo($user)->whereIn('status', Ticket::OPEN)->where('awaiting_staff', false)->count(),
                'latest' => Ticket::visibleTo($user)->with('client:id,company_name')->latest('updated_at')->limit(3)->get()->map(fn (Ticket $t) => $t->toApiArray($user)),
            ],
        ]);
    }
}
