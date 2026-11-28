<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PosCashSession;
use App\Models\PosRegister;
use App\Models\User;
use App\Services\PosRegisterService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosRegisterController extends Controller
{
    public function __construct(private PosRegisterService $registers) {}

    public function index()
    {
        $registers = PosRegister::query()->with([
            'warehouse:id,branch_id,code,name,is_active',
            'warehouse.branch:id,code,name,is_active',
            'assignments' => fn ($query) => $query->where('is_active', true)->orderBy('id'),
            'assignments.user:id,name,email,role,is_active',
        ])->orderBy('id')->get();

        return response()->json(['data' => $registers->map(fn (PosRegister $register) => $this->registerData($register))]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'code' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._-]+\z/', Rule::unique('pos_registers', 'code')],
            'name' => ['required', 'string', 'max:120'],
        ]);
        $register = $this->registers->create($data, $request->user());

        return response()->json(['data' => $this->registerData($register)], 201);
    }

    public function update(Request $request, PosRegister $register)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $register = $this->registers->setActive($register, (bool) $data['is_active'], $request->user());

        return response()->json(['data' => $this->registerData($register)]);
    }

    public function assign(Request $request, PosRegister $register, User $user)
    {
        $assignment = $this->registers->assign($register, $user, $request->user());

        return response()->json(['data' => [
            'id' => $assignment->id, 'register_id' => $assignment->register_id,
            'user' => ['id' => $assignment->user->id, 'name' => $assignment->user->name, 'role' => $assignment->user->role],
            'is_active' => (bool) $assignment->is_active, 'assigned_at' => $assignment->assigned_at?->toISOString(),
        ]], $assignment->wasRecentlyCreated ? 201 : 200);
    }

    public function revoke(Request $request, PosRegister $register, User $user)
    {
        $assignment = $this->registers->revoke($register, $user, $request->user());

        return response()->json(['data' => [
            'id' => $assignment->id, 'register_id' => $assignment->register_id,
            'user_id' => $assignment->user_id, 'is_active' => (bool) $assignment->is_active,
            'revoked_at' => $assignment->revoked_at?->toISOString(),
        ]]);
    }

    public function sessions(Request $request)
    {
        $filters = $request->validate([
            'register_id' => ['nullable', 'integer', 'exists:pos_registers,id'],
            'status' => ['nullable', Rule::in([PosCashSession::OPEN, PosCashSession::CLOSED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = PosCashSession::query()->with(['register.warehouse.branch', 'cashier:id,name', 'closedBy:id,name'])
            ->when(isset($filters['register_id']), fn ($query) => $query->where('register_id', $filters['register_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('opened_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (PosCashSession $session) => $this->sessionData($session)),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function showSession(PosCashSession $session)
    {
        $session->load(['register.warehouse.branch', 'cashier:id,name', 'closedBy:id,name', 'movements.actor:id,name', 'auditEvents.actor:id,name']);

        return response()->json(['data' => $this->sessionData($session) + [
            'movements' => $session->movements->map(fn ($movement) => [
                'id' => $movement->id, 'kind' => $movement->kind, 'cash_received' => $movement->cash_received,
                'change_given' => $movement->change_given, 'net_amount' => $movement->net_amount,
                'actor_name' => $movement->actor?->name, 'created_at' => $movement->created_at?->toISOString(),
            ])->values(),
            'history' => $session->auditEvents->map(fn ($event) => [
                'event' => $event->event, 'actor_name' => $event->actor?->name,
                'before' => $event->before_values, 'after' => $event->after_values, 'created_at' => $event->created_at?->toISOString(),
            ])->values(),
        ]]);
    }

    private function registerData(PosRegister $register): array
    {
        return [
            'id' => $register->id, 'code' => $register->code, 'name' => $register->name, 'is_active' => (bool) $register->is_active,
            'warehouse' => $register->warehouse ? ['id' => $register->warehouse->id, 'code' => $register->warehouse->code, 'name' => $register->warehouse->name, 'is_active' => (bool) $register->warehouse->is_active] : null,
            'branch' => $register->warehouse?->branch ? ['id' => $register->warehouse->branch->id, 'code' => $register->warehouse->branch->code, 'name' => $register->warehouse->branch->name, 'is_active' => (bool) $register->warehouse->branch->is_active] : null,
            'cashiers' => $register->assignments->map(fn ($assignment) => [
                'id' => $assignment->user?->id, 'name' => $assignment->user?->name, 'role' => $assignment->user?->role,
                'is_active' => (bool) $assignment->is_active, 'assigned_at' => $assignment->assigned_at?->toISOString(),
            ])->values(),
        ];
    }

    private function sessionData(PosCashSession $session): array
    {
        return [
            'id' => $session->id, 'register_id' => $session->register_id, 'status' => $session->status,
            'cashier' => $session->cashier ? ['id' => $session->cashier->id, 'name' => $session->cashier->name] : null,
            'register' => $session->register ? ['id' => $session->register->id, 'code' => $session->register->code, 'name' => $session->register->name,
                'warehouse' => $session->register->warehouse?->only(['id', 'code', 'name']), 'branch' => $session->register->warehouse?->branch?->only(['id', 'code', 'name'])] : null,
            'opening_cash' => $session->opening_cash, 'opened_at' => $session->opened_at?->toISOString(),
            'closed_by' => $session->closedBy ? ['id' => $session->closedBy->id, 'name' => $session->closedBy->name] : null,
            'closed_at' => $session->closed_at?->toISOString(), 'expected_cash' => $session->expected_cash,
            'counted_cash' => $session->counted_cash, 'difference' => $session->difference, 'closing_reason' => $session->closing_reason,
        ];
    }
}
