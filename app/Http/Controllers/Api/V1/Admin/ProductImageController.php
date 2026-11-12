<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\InvalidProductImage;
use App\Exceptions\ProductImageOriginalUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductImageProcessingService;
use App\Services\ProductImageProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProductImageController extends Controller
{
    public function store(Request $request, Product $product, ProductImageProcessingService $images, ProductImageProcessor $processor)
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:6'],
            'images.*' => ['required', 'file', 'max:10240', function ($attribute, $value, $fail) use ($processor) {
                if (! $value instanceof UploadedFile) {
                    return;
                }
                try {
                    $processor->validateUpload($value);
                } catch (InvalidProductImage) {
                    $fail('La imagen debe ser JPEG, PNG o WebP válida y cumplir los límites permitidos.');
                }
            }],
        ]);
        $created = $images->attachUploads($product, $data['images']);

        return response()->json(['images' => $created], 202);
    }

    public function replace(Request $request, Product $product, ProductImage $image, ProductImageProcessingService $images, ProductImageProcessor $processor)
    {
        $file = $request->validate(['image' => ['required', 'file', 'max:10240', function ($attribute, $value, $fail) use ($processor) {
            if (! $value instanceof UploadedFile) {
                return;
            }
            try {
                $processor->validateUpload($value);
            } catch (InvalidProductImage) {
                $fail('La imagen debe ser JPEG, PNG o WebP válida y cumplir los límites permitidos.');
            }
        }]])['image'];
        $result = $images->replace($product, $this->ownedImage($product, $image), $file);

        return response()->json(['status' => $result['status']], 202);
    }

    public function regenerate(Product $product, ProductImage $image, ProductImageProcessingService $images)
    {
        try {
            $result = $images->requestRegeneration($product, $this->ownedImage($product, $image));
        } catch (ProductImageOriginalUnavailable) {
            Log::warning('product_image.original_unavailable', ['product_id' => (int) $product->id]);

            return response()->json(['message' => 'No fue posible regenerar los formatos de esta imagen.', 'status' => 'failed'], 422);
        }

        return response()->json(['status' => $result['status'], 'message' => $result['status'] === 'pending' ? 'Imagen enviada para procesamiento.' : 'Imagen en proceso.'], 202);
    }

    public function primary(Product $product, ProductImage $image, ProductImageProcessingService $images)
    {
        $images->setPrimary($product, $this->ownedImage($product, $image));

        return response()->json(['message' => 'Imagen principal actualizada.']);
    }

    public function reorder(Request $request, Product $product, ProductImageProcessingService $images)
    {
        $ids = $request->validate(['image_ids' => ['required', 'array', 'max:6'], 'image_ids.*' => ['required', 'integer', 'distinct', 'min:1']])['image_ids'];
        $images->reorder($product, $ids);

        return response()->json(['message' => 'Orden de imágenes actualizado.']);
    }

    public function destroy(Product $product, ProductImage $image, ProductImageProcessingService $images)
    {
        $images->delete($product, $this->ownedImage($product, $image));

        return response()->json(['message' => 'Imagen eliminada.']);
    }

    public function status(Product $product, ProductImage $image, ProductImageProcessingService $images)
    {
        return response()->json($images->status($this->ownedImage($product, $image)));
    }

    public function regeneratePrimary(Product $product, ProductImageProcessingService $images)
    {
        $image = $product->images()->where('is_primary', true)->first();
        if (! $image) {
            throw ValidationException::withMessages(['image' => 'El producto no tiene una imagen principal.']);
        }

        return $this->regenerate($product, $image, $images);
    }

    public function primaryStatus(Product $product, ProductImageProcessingService $images)
    {
        $image = $product->images()->where('is_primary', true)->first();
        if (! $image) {
            return response()->json(['status' => 'ready', 'can_regenerate' => false, 'image_url' => $product->image_url, 'image_variants' => [], 'image_dimensions' => null]);
        }

        $status = $images->status($image);

        return response()->json([
            'status' => $status['status'], 'can_regenerate' => $status['can_regenerate'],
            'image_url' => $status['url'], 'image_variants' => $status['variants'],
            'image_dimensions' => $image->publicDimensions(),
        ]);
    }

    private function ownedImage(Product $product, ProductImage $image): ProductImage
    {
        return ProductImage::query()->where('product_id', $product->id)->whereKey($image->id)->firstOrFail();
    }
}
