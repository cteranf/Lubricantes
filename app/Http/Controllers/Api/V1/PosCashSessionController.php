<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PosCashSession;
use App\Models\PosRegister;
use App\Services\PosCashSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PosCashSessionController extends Controller
{
    public function __construct(private PosCashSessionService $sessions) {}

    public function registers(Request $request)
    {
        $assignments = $request->user()->posCashierAssignments()
            ->where('is_active', true)
            ->whereHas('register', fn ($query) => $query->where('is_active', true))
            ->with(['register.warehouse.branch'])
            ->orderBy('register_id')->get();

        $registers = $assignments->map(function ($assignment) use ($request): array {
            $register = $assignment->register;
            $sessionId = DB::table('pos_register_open_sessions')->where('register_id', $register->id)->value('session_id');
            $session = $sessionId ? PosCashSession::query()->whereKey($sessionId)->where('cashier_user_id', $request->user()->id)->first() : null;

            return [
                'id' => $register->id, 'code' => $register->code, 'name' => $register->name,
                'warehouse' => $register->warehouse?->only(['id', 'code', 'name']),
                'branch' => $register->warehouse?->branch?->only(['id', 'code', 'name']),
                'current_session' => $session ? $this->sessionData($session) : null,
            ];
        })->values();

        return response()->json(['data' => $registers]);
    }

    public function showRegisterSession(Request $request, PosRegister $register)
    {
        $this->sessions->assertAssignedCashier($register, $request->user());
        $sessionId = DB::table('pos_register_open_sessions')->where('register_id', $register->id)->value('session_id');
        if (! $sessionId) {
            return response()->json(['data' => null]);
        }
        $session = PosCashSession::query()->whereKey($sessionId)->where('cashier_user_id', $request->user()->id)->first();
        if (! $session) {
            throw new AccessDeniedHttpException('La sesión abierta pertenece a otro cajero.');
        }

        return response()->json(['data' => $this->sessionData($session)]);
    }

    public function open(Request $request, PosRegister $register)
    {
        $data = $request->validate([
            'opening_cash' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $session = $this->sessions->open($register, $request->user(), (string) $data['opening_cash'], $data['idempotency_key']);

        return response()->json(['data' => $this->sessionData($session)], $session->wasRecentlyCreated ? 201 : 200);
    }

    public function close(Request $request, PosCashSession $session)
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'closing_reason' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $session = $this->sessions->close($session, $request->user(), (string) $data['counted_cash'], $data['closing_reason'] ?? null, $data['idempotency_key']);

        return response()->json(['data' => $this->sessionData($session)]);
    }

    private function sessionData(PosCashSession $session): array
    {
        $session->loadMissing(['register.warehouse.branch', 'cashier', 'closedBy']);

        return [
            'id' => $session->id, 'status' => $session->status,
            'cashier' => ['id' => $session->cashier->id, 'name' => $session->cashier->name],
            'register' => [
                'id' => $session->register->id, 'code' => $session->register->code, 'name' => $session->register->name,
                'warehouse' => $session->register->warehouse?->only(['id', 'code', 'name']),
                'branch' => $session->register->warehouse?->branch?->only(['id', 'code', 'name']),
            ],
            'opening_cash' => $session->opening_cash, 'opened_at' => $session->opened_at?->toISOString(),
            'closed_at' => $session->closed_at?->toISOString(), 'expected_cash' => $session->expected_cash,
            'counted_cash' => $session->counted_cash, 'difference' => $session->difference,
            'closing_reason' => $session->closing_reason,
        ];
    }
}
