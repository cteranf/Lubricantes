<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\InventoryReceiptConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PreviewInventoryReceiptImportRequest;
use App\Http\Requests\Admin\StoreInventoryReceiptRequest;
use App\Http\Requests\Admin\UpdateInventoryReceiptRequest;
use App\Http\Resources\AdminInventoryReceiptResource;
use App\Models\InventoryReceipt;
use App\Models\Product;
use App\Services\InventoryReceiptImportService;
use App\Services\InventoryReceiptService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InventoryReceiptController extends Controller
{
    public function __construct(private InventoryReceiptService $receipts, private InventoryReceiptImportService $imports) {}

    public function importTemplate()
    {
        return response("\xEF\xBB\xBFsku,cantidad\r\n", Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename=plantilla-recepcion-inventario.csv',
        ]);
    }

    public function importPreview(PreviewInventoryReceiptImportRequest $request)
    {
        try {
            return response()->json($this->imports->preview(
                $request->file('file'),
                (int) $request->validated('warehouse_id'),
                $request->validated('existing_lines')
            ));
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'can_import' => false,
                'rows' => [],
                'global_errors' => [$exception->getMessage()],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function resolveProductCode(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
        ]);
        $code = trim($data['code']);
        $productId = null;

        if (preg_match('/\\ALS1:PRODUCT:([1-9][0-9]{0,9})\\z/D', $code, $matches) === 1) {
            $productId = (int) $matches[1];
        } elseif (preg_match('/\\A[A-Za-z0-9_-]{1,64}\\z/D', $code) === 1) {
            $product = Product::query()
                ->with('images')
                ->where('is_active', true)
                ->where('sku', strtoupper($code))
                ->first(['id', 'name', 'sku']);

            if ($product && ! hash_equals((string) $product->sku, strtoupper($code))) {
                $product = null;
            }

            return $product
                ? response()->json(['data' => ['id' => (int) $product->id, 'name' => $product->name, 'sku' => $product->sku, 'image_url' => $product->image_url]])
                : response()->json(['message' => 'No se encontró un producto activo con ese código.'], Response::HTTP_NOT_FOUND);
        } else {
            return response()->json(['message' => 'El código no tiene un formato válido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $product = Product::query()->with('images')->where('is_active', true)->find($productId, ['id', 'name', 'sku']);

        return $product
            ? response()->json(['data' => ['id' => (int) $product->id, 'name' => $product->name, 'sku' => $product->sku, 'image_url' => $product->image_url]])
            : response()->json(['message' => 'No se encontró un producto activo con ese código.'], Response::HTTP_NOT_FOUND);
    }

    public function index(Request $request)
    {
        $query = InventoryReceipt::query()->with(['warehouse:id,code,name', 'lines.product:id,name,sku'])->latest('id');
        if ($request->filled('status') && in_array($request->input('status'), InventoryReceipt::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return AdminInventoryReceiptResource::collection($query->paginate(20));
    }

    public function store(StoreInventoryReceiptRequest $request)
    {
        try {
            $receipt = $this->receipts->createDraft($request->validated(), $request->user());

            return (new AdminInventoryReceiptResource($receipt))->response()->setStatusCode(Response::HTTP_CREATED);
        } catch (InventoryReceiptConflictException $exception) {
            return $this->conflictResponse($exception);
        }
    }

    public function show(InventoryReceipt $receipt)
    {
        return new AdminInventoryReceiptResource($this->receipts->loadReceipt($receipt->id));
    }

    public function update(UpdateInventoryReceiptRequest $request, InventoryReceipt $receipt)
    {
        try {
            return new AdminInventoryReceiptResource($this->receipts->updateDraft($receipt->id, $request->validated(), $request->user()));
        } catch (InventoryReceiptConflictException $exception) {
            return $this->conflictResponse($exception);
        }
    }

    public function confirm(Request $request, InventoryReceipt $receipt)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        try {
            return new AdminInventoryReceiptResource($this->receipts->confirm($receipt->id, (int) $data['version'], $request->user()));
        } catch (InventoryReceiptConflictException $exception) {
            return $this->conflictResponse($exception);
        }
    }

    public function cancel(Request $request, InventoryReceipt $receipt)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        try {
            return new AdminInventoryReceiptResource($this->receipts->cancel($receipt->id, (int) $data['version']));
        } catch (InventoryReceiptConflictException $exception) {
            return $this->conflictResponse($exception);
        }
    }

    private function conflictResponse(InventoryReceiptConflictException $exception)
    {
        $status = in_array($exception->getCode(), [Response::HTTP_UNPROCESSABLE_ENTITY, Response::HTTP_CONFLICT], true)
            ? $exception->getCode()
            : Response::HTTP_CONFLICT;

        return response()->json(['message' => $exception->getMessage()], $status);
    }
}
