<?php

namespace App\Services;

use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores profile photos as 400×400 WebP files on the public disk.
 *
 * Every upload is decoded and re-encoded with GD: that crops it square,
 * applies the camera's rotation, strips all metadata (including GPS
 * location) and guarantees the stored file is a plain image, whatever was
 * uploaded.
 */
class AvatarService
{
    public const SIZE = 400;

    private const DIRECTORY = 'avatars';

    public function replace(User $user, UploadedFile $file): void
    {
        $path = self::DIRECTORY.'/'.Str::ulid()->toBase32().'.webp';

        Storage::disk('public')->put($path, $this->encode($file));

        $this->deleteFile($user->avatar_path);
        $user->forceFill(['avatar_path' => $path])->save();
    }

    public function remove(User $user): void
    {
        $this->deleteFile($user->avatar_path);
        $user->forceFill(['avatar_path' => null])->save();
    }

    private function encode(UploadedFile $file): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if (! $source instanceof GdImage) {
            throw new RuntimeException('The uploaded file is not a readable image.');
        }

        $source = $this->applyOrientation($source, $file);

        // Centre-crop to a square, then scale.
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $avatar = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($avatar, false);
        imagesavealpha($avatar, true);
        imagecopyresampled(
            $avatar, $source,
            0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2),
            self::SIZE, self::SIZE, $side, $side,
        );

        ob_start();
        imagewebp($avatar, null, 85);

        return (string) ob_get_clean();
    }

    /**
     * Phone cameras store rotation as EXIF data; GD ignores it, so apply it here.
     */
    private function applyOrientation(GdImage $image, UploadedFile $file): GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            return $image;
        }

        $orientation = @exif_read_data($file->getRealPath())['Orientation'] ?? 1;

        return match ((int) $orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    private function deleteFile(?string $path): void
    {
        if ($path !== null && str_starts_with($path, self::DIRECTORY.'/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
