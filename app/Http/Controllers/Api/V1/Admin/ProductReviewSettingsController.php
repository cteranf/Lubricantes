<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductReviewSettingsRequest;
use App\Models\ProductReviewSetting;
use App\Services\AuditService;
use App\Support\AuditEventContract;
use Illuminate\Support\Facades\DB;

class ProductReviewSettingsController extends Controller
{
    public function show()
    {
        return response()->json(['data' => ProductReviewSetting::query()->findOrFail(1)]);
    }

    public function update(UpdateProductReviewSettingsRequest $request, AuditService $audit)
    {
        $values = $request->validated();

        return DB::transaction(function () use ($request, $audit, $values) {
            $setting = ProductReviewSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $old = $setting->only(array_keys($values));
            $setting->fill($values)->save();
            $changed = array_values(array_filter(array_keys($values), fn ($key) => (bool) $old[$key] !== (bool) $setting->{$key}));
            if ($changed !== []) {
                $audit->recordUser(
                    action: AuditEventContract::PRODUCT_REVIEW_SETTINGS_UPDATED,
                    actor: $request->user(),
                    result: 'succeeded',
                    auditableType: 'product_review_setting',
                    auditableId: '1',
                    metadata: ['changed_fields' => implode(',', $changed)],
                    request: $request,
                );
            }

            return response()->json(['data' => $setting->fresh()]);
        });
    }
}
