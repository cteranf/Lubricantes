<?php

namespace App\Services;

use App\Models\ProductReviewSetting;

class ProductReviewSettingService
{
    public function current(): ProductReviewSetting
    {
        return ProductReviewSetting::query()->findOrFail(1);
    }
}
