<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\User;
use App\Support\AuditEventContract;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class BusinessProfileService
{
    private const LOGO_DIRECTORY = 'business/logos';

    public function __construct(private AuditService $audit) {}

    public function current(): ?BusinessProfile
    {
        return BusinessProfile::query()->where('singleton_key', BusinessProfile::SINGLETON_KEY)->first();
    }

    public function save(array $input, User $actor): BusinessProfile
    {
        $upload = $input['logo'] ?? null;
        $removeLogo = (bool) ($input['remove_logo'] ?? false);
        unset($input['logo'], $input['remove_logo']);
        $newPath = $upload instanceof UploadedFile ? $this->storeLogo($upload) : null;

        try {
            return $this->persist($input, $actor, $newPath, $removeLogo);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception) || ! $this->current()) {
                if ($newPath) {
                    $this->deleteIfUnreferenced($newPath);
                }
                throw $exception;
            }

            // A concurrent first save won the singleton unique key; retry as an update.
            try {
                return $this->persist($input, $actor, $newPath, $removeLogo);
            } catch (Throwable $retryException) {
                if ($newPath) {
                    $this->deleteIfUnreferenced($newPath);
                }
                throw $retryException;
            }
        } catch (Throwable $exception) {
            if ($newPath) {
                $this->deleteIfUnreferenced($newPath);
            }
            throw $exception;
        }
    }

    private function persist(array $input, User $actor, ?string $newPath, bool $removeLogo): BusinessProfile
    {
        $model = new BusinessProfile;
        $connection = DB::connection($model->getConnectionName());
        $oldPath = null;

        $profile = $connection->transaction(function () use ($input, $actor, $newPath, $removeLogo, &$oldPath): BusinessProfile {
            // Insert-ignore on the unique singleton key serializes concurrent first saves
            // without relying on a missing-row gap lock; then lock the canonical row.
            $wasNew = BusinessProfile::query()->insertOrIgnore([
                'singleton_key' => BusinessProfile::SINGLETON_KEY,
                'country_code' => 'PE',
                'created_at' => now(),
                'updated_at' => now(),
            ]) > 0;
            $profile = BusinessProfile::query()->where('singleton_key', BusinessProfile::SINGLETON_KEY)->lockForUpdate()->firstOrFail();
            $before = $profile->only(array_keys($input));

            $profile->fill($input);
            if ($newPath !== null) {
                $oldPath = $profile->logo_path;
                $profile->logo_path = $newPath;
            } elseif ($removeLogo && $profile->logo_path !== null) {
                $oldPath = $profile->logo_path;
                $profile->logo_path = null;
            }
            $profile->save();

            $changedFields = [];
            if ($wasNew) {
                $changedFields[] = 'country_code';
            }
            foreach (array_keys($input) as $field) {
                if (($before[$field] ?? null) !== $profile->{$field}) {
                    $changedFields[] = $field;
                }
            }
            if (($newPath !== null || $removeLogo) && ($wasNew || $oldPath !== null)) {
                $changedFields[] = 'logo';
            }
            $changedFields = array_values(array_unique($changedFields));
            if ($changedFields !== []) {
                sort($changedFields);
                $this->audit->recordUser(
                    action: AuditEventContract::BUSINESS_PROFILE_UPDATED,
                    actor: $actor,
                    result: AuditEventContract::RESULT_SUCCEEDED,
                    auditableType: 'business_profile',
                    auditableId: BusinessProfile::SINGLETON_KEY,
                    metadata: ['changed_fields' => implode(',', $changedFields)],
                );
            }

            return $profile;
        });

        if ($oldPath !== null) {
            $this->afterCommit(fn () => $this->deleteIfUnreferenced($oldPath));
        }

        return $profile->fresh();
    }

    private function storeLogo(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $details = $path ? @getimagesize($path) : false;
        $mime = is_array($details) ? ($details['mime'] ?? null) : null;
        $width = (int) ($details[0] ?? 0);
        $height = (int) ($details[1] ?? 0);
        if (! $file->isValid() || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $file->getMimeType() !== $mime || $width < 1 || $height < 1
            || $width > (int) config('brand_images.max_width', 4000)
            || $height > (int) config('brand_images.max_height', 2500)
            || ($width * $height) > ((int) config('brand_images.max_megapixels', 10) * 1_000_000)) {
            throw new InvalidArgumentException('El logo debe ser una imagen JPEG, PNG o WebP válida y respetar los límites de tamaño y dimensiones.');
        }

        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        };
        if (! $source || ! function_exists('imagecreatetruecolor')) {
            if ($source) {
                imagedestroy($source);
            }
            throw new InvalidArgumentException('No se pudo procesar el logo con las capacidades disponibles.');
        }

        $target = null;
        try {
            $scale = min(
                1,
                (int) config('brand_images.output_width', 2000) / $width,
                (int) config('brand_images.output_height', 1250) / $height,
            );
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            if (in_array($mime, ['image/png', 'image/webp'], true)) {
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
            } else {
                imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
            }
            if (! imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
                throw new InvalidArgumentException('No se pudo procesar el logo.');
            }

            if (function_exists('imagewebp')) {
                ob_start();
                $encoded = imagewebp($target, null, (int) config('brand_images.webp_quality', 86));
                $bytes = ob_get_clean();
                $extension = 'webp';
            } else {
                ob_start();
                $extension = $mime === 'image/png' ? 'png' : 'jpg';
                $encoded = $extension === 'png' ? imagepng($target, null, 6) : imagejpeg($target, null, 88);
                $bytes = ob_get_clean();
            }
            if (! $encoded || ! is_string($bytes) || $bytes === '') {
                throw new InvalidArgumentException('No se pudo procesar el logo.');
            }

            $storedPath = self::LOGO_DIRECTORY.'/'.Str::uuid().'.'.$extension;
            try {
                $stored = Storage::disk('public')->put($storedPath, $bytes);
            } catch (Throwable) {
                $this->deleteIfUnreferenced($storedPath);
                throw new \RuntimeException('No se pudo guardar el logo.');
            }
            if (! $stored) {
                $this->deleteIfUnreferenced($storedPath);
                throw new \RuntimeException('No se pudo guardar el logo.');
            }

            return $storedPath;
        } finally {
            imagedestroy($source);
            if ($target) {
                imagedestroy($target);
            }
        }
    }

    private function deleteIfUnreferenced(?string $path): void
    {
        if (! $path || ! preg_match('#\Abusiness/logos/[a-f0-9-]+\.(?:webp|png|jpg)\z#iD', $path)) {
            return;
        }
        try {
            if (! BusinessProfile::query()->where('logo_path', $path)->exists()) {
                Storage::disk('public')->delete($path);
            }
        } catch (Throwable) {
            // Leaving an orphan is safer than deleting a file that may still be referenced.
        }
    }

    private function afterCommit(callable $callback): void
    {
        $connection = DB::connection((new BusinessProfile)->getConnectionName());
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($callback);
        } else {
            $callback();
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '19'], true)
            && (str_contains($message, 'business_profiles_singleton_key_unique')
                || str_contains($message, 'business_profiles.singleton_key'));
    }
}
