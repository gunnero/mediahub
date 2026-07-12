<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserAvatarService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_PIXELS = 40_000_000;

    /** @var list<int> */
    private const SIZES = [512, 128, 64, 32];

    /**
     * @return array<string, string>
     */
    public function store(User $user, UploadedFile $upload): array
    {
        $contents = file_get_contents($upload->getRealPath());
        $info = is_string($contents) ? @getimagesizefromstring($contents) : false;
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        $width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
        $height = is_array($info) ? (int) ($info[1] ?? 0) : 0;

        if (! is_string($contents) || ! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages(['avatar' => 'The avatar must be a valid JPG, PNG, or WEBP image.']);
        }
        if ($width < 1 || $height < 1 || ($width * $height) > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['avatar' => 'The avatar dimensions are not supported.']);
        }

        $source = @imagecreatefromstring($contents);
        if (! $source) {
            throw ValidationException::withMessages(['avatar' => 'The avatar could not be decoded safely.']);
        }

        $directory = $this->directoryFor($user);
        $baseName = Str::random(40);
        $paths = [];

        try {
            foreach (self::SIZES as $size) {
                $path = $directory.'/'.$baseName.'-'.$size.'.jpg';
                $encoded = $this->squareJpeg($source, $width, $height, $size);
                if (! Storage::disk('public')->put($path, $encoded)) {
                    throw ValidationException::withMessages(['avatar' => 'The avatar could not be stored.']);
                }
                $paths[(string) $size] = $path;
            }
        } catch (\Throwable $error) {
            Storage::disk('public')->delete(array_values($paths));
            throw $error;
        } finally {
            imagedestroy($source);
        }

        $previous = $this->ownedPaths($user);
        $user->forceFill([
            'avatar_path' => Storage::disk('public')->url($paths['512']),
            'avatar_variants' => $paths,
        ])->save();
        Storage::disk('public')->delete($previous);

        return $this->urls($paths);
    }

    public function remove(User $user): void
    {
        $paths = $this->ownedPaths($user);
        $user->forceFill([
            'avatar_path' => null,
            'avatar_variants' => null,
        ])->save();
        Storage::disk('public')->delete($paths);
    }

    /**
     * @param  array<string, string>|null  $paths
     * @return array<string, string>
     */
    public function urls(?array $paths): array
    {
        return collect($paths ?? [])
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->mapWithKeys(fn (string $path, string|int $size): array => [
                (string) $size => Storage::disk('public')->url($path),
            ])
            ->all();
    }

    private function squareJpeg(\GdImage $source, int $width, int $height, int $size): string
    {
        $sourceSize = min($width, $height);
        $sourceX = (int) floor(($width - $sourceSize) / 2);
        $sourceY = (int) floor(($height - $sourceSize) / 2);
        $target = imagecreatetruecolor($size, $size);
        $background = imagecolorallocate($target, 19, 23, 26);
        imagefill($target, 0, 0, $background);
        imagecopyresampled($target, $source, 0, 0, $sourceX, $sourceY, $size, $size, $sourceSize, $sourceSize);

        ob_start();
        imagejpeg($target, null, 88);
        $encoded = ob_get_clean();
        imagedestroy($target);

        if (! is_string($encoded) || $encoded === '') {
            throw ValidationException::withMessages(['avatar' => 'The avatar could not be encoded safely.']);
        }

        return $encoded;
    }

    /** @return list<string> */
    private function ownedPaths(User $user): array
    {
        $prefix = $this->directoryFor($user).'/';

        return collect($user->avatar_variants ?? [])
            ->filter(fn (mixed $path): bool => is_string($path) && str_starts_with($path, $prefix))
            ->values()
            ->all();
    }

    private function directoryFor(User $user): string
    {
        $opaqueUserKey = substr(hash_hmac('sha256', (string) $user->getKey(), (string) config('app.key')), 0, 32);

        return 'avatars/'.$opaqueUserKey;
    }
}
