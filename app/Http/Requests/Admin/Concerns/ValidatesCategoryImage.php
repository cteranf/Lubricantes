<?php

namespace App\Http\Requests\Admin\Concerns;

use Illuminate\Http\UploadedFile;

trait ValidatesCategoryImage
{
    protected function categoryImageRules(): array
    {
        return [
            'sometimes',
            'nullable',
            'file',
            'mimetypes:image/jpeg,image/png,image/webp',
            'max:'.(int) config('category_images.max_kilobytes', 2048),
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! ($value instanceof UploadedFile)) {
                    return;
                }

                if (! $value->isValid()) {
                    $fail('No se pudo leer la imagen seleccionada.');

                    return;
                }

                $details = @getimagesize($value->getRealPath());
                $mime = is_array($details) ? ($details['mime'] ?? null) : null;
                $width = is_array($details) ? (int) ($details[0] ?? 0) : 0;
                $height = is_array($details) ? (int) ($details[1] ?? 0) : 0;
                $maxMegapixels = (int) config('category_images.max_megapixels', 10);

                if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
                    || $value->getMimeType() !== $mime
                    || $width < (int) config('category_images.min_width', 640)
                    || $height < (int) config('category_images.min_height', 400)
                    || $width > (int) config('category_images.max_width', 4000)
                    || $height > (int) config('category_images.max_height', 2500)
                    || ($width * $height) > ($maxMegapixels * 1_000_000)) {
                    $fail('La imagen debe ser JPEG, PNG o WebP y cumplir las dimensiones permitidas.');
                }
            },
        ];
    }
}
