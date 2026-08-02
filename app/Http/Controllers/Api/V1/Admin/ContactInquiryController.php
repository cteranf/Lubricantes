<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignContactInquiryRequest;
use App\Http\Requests\Admin\ListContactInquiriesRequest;
use App\Http\Requests\Admin\StoreContactInquiryNoteRequest;
use App\Http\Requests\Admin\UpdateContactInquiryStatusRequest;
use App\Http\Resources\AdminContactInquiryResource;
use App\Models\ContactInquiry;
use App\Models\User;
use App\Services\ContactInquiryService;
use Illuminate\Http\Request;

class ContactInquiryController extends Controller
{
    public function __construct(private ContactInquiryService $service) {}

    public function index(ListContactInquiriesRequest $request)
    {
        $this->authorize('viewAny', ContactInquiry::class);
        $data = $request->validated();
        $query = ContactInquiry::query()->with('assignee:id,name');

        $archived = $data['archived'] ?? 'active';
        if ($archived === 'active') $query->whereNull('archived_at');
        if ($archived === 'archived') $query->whereNotNull('archived_at');
        if (! empty($data['status'])) $query->where('status', $data['status']);
        if (! empty($data['assigned_to'])) {
            $data['assigned_to'] === 'unassigned'
                ? $query->whereNull('assigned_to')
                : $query->where('assigned_to', (int) $data['assigned_to']);
        }
        if (! empty($data['date_from'])) $query->whereDate('created_at', '>=', $data['date_from']);
        if (! empty($data['date_to'])) $query->whereDate('created_at', '<=', $data['date_to']);
        if (! empty($data['search'])) {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($data['search']));
            $query->where(function ($search) use ($term) {
                foreach (['public_id', 'name', 'email', 'phone', 'subject', 'message'] as $column) {
                    $search->orWhere($column, 'like', '%'.$term.'%');
                }
            });
        }

        [$sortColumn, $sortDirection] = match ($data['sort'] ?? 'received_desc') {
            'received_asc' => ['created_at', 'asc'],
            'activity_desc' => ['last_activity_at', 'desc'],
            'activity_asc' => ['last_activity_at', 'asc'],
            default => ['created_at', 'desc'],
        };
        $query->orderBy($sortColumn, $sortDirection)->orderBy('id', $sortDirection);

        return AdminContactInquiryResource::collection($query->paginate(15)->withQueryString());
    }

    public function show(ContactInquiry $contactInquiry)
    {
        $this->authorize('view', $contactInquiry);
        return new AdminContactInquiryResource($this->detail($contactInquiry));
    }

    public function pendingCount()
    {
        $this->authorize('viewAny', ContactInquiry::class);
        return response()->json(['count' => ContactInquiry::whereNull('archived_at')->whereIn('status', ContactInquiry::COUNTER_STATUSES)->count()]);
    }

    public function assignableAdmins()
    {
        $this->authorize('viewAny', ContactInquiry::class);
        return response()->json(User::where('role', 'admin')->orderBy('name')->get(['id', 'name']));
    }

    public function status(UpdateContactInquiryStatusRequest $request, ContactInquiry $contactInquiry)
    {
        $this->authorize('update', $contactInquiry);
        $this->service->changeStatus($contactInquiry, $request->user(), $request->validated('status'));
        return new AdminContactInquiryResource($this->detail($contactInquiry));
    }

    public function assign(AssignContactInquiryRequest $request, ContactInquiry $contactInquiry)
    {
        $this->authorize('assign', $contactInquiry);
        $id = $request->validated('assigned_to');
        $assignee = $id ? User::where('role', 'admin')->findOrFail($id) : null;
        $this->service->assign($contactInquiry, $request->user(), $assignee);
        return new AdminContactInquiryResource($this->detail($contactInquiry));
    }

    public function note(StoreContactInquiryNoteRequest $request, ContactInquiry $contactInquiry)
    {
        $this->authorize('addNote', $contactInquiry);
        $this->service->addNote($contactInquiry, $request->user(), $request->validated('body'));
        return (new AdminContactInquiryResource($this->detail($contactInquiry)))->response()->setStatusCode(201);
    }

    public function archive(Request $request, ContactInquiry $contactInquiry)
    {
        $this->authorize('archive', $contactInquiry);
        $this->service->archive($contactInquiry, $request->user());
        return new AdminContactInquiryResource($this->detail($contactInquiry));
    }

    public function restore(Request $request, ContactInquiry $contactInquiry)
    {
        $this->authorize('restore', $contactInquiry);
        $this->service->restore($contactInquiry, $request->user());
        return new AdminContactInquiryResource($this->detail($contactInquiry));
    }

    public function email(Request $request, ContactInquiry $contactInquiry)
    {
        $this->authorize('externalAction', $contactInquiry);
        return response()->json(['url' => $this->service->registerExternalAction($contactInquiry, $request->user(), 'email')]);
    }

    public function whatsapp(Request $request, ContactInquiry $contactInquiry)
    {
        $this->authorize('externalAction', $contactInquiry);
        return response()->json(['url' => $this->service->registerExternalAction($contactInquiry, $request->user(), 'whatsapp')]);
    }

    private function detail(ContactInquiry $inquiry): ContactInquiry
    {
        return $inquiry->fresh()->load(['assignee:id,name', 'notes.user:id,name', 'histories.actor:id,name']);
    }
}
