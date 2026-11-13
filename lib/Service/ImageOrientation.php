<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;

/** Bounded EXIF orientation reader for already validated JPEG bytes. */
final class ImageOrientation
{
    public static function fromJpeg(string $bytes): int
    {
        $length = strlen($bytes);
        if ($length < 4 || substr($bytes, 0, 2) !== "\xff\xd8") {
            return 1;
        }
        $at = 2;
        while ($at + 4 <= $length && ord($bytes[$at]) === 0xff) {
            while ($at < $length && ord($bytes[$at]) === 0xff) {
                $at++;
            }
            if ($at >= $length) {
                break;
            }
            $marker = ord($bytes[$at++]);
            if ($marker === 0xda || $marker === 0xd9) {
                break;
            }
            if ($marker === 0x01 || ($marker >= 0xd0 && $marker <= 0xd7)) {
                continue;
            }
            if ($at + 2 > $length) {
                break;
            }
            $size = (ord($bytes[$at]) << 8) | ord($bytes[$at + 1]);
            if ($size < 2 || $at + $size > $length) {
                break;
            }
            if ($marker === 0xe1 && $size >= 16 && substr($bytes, $at + 2, 6) === "Exif\0\0") {
                $value = self::tiff(substr($bytes, $at + 8, $size - 8));
                if ($value !== null) {
                    return $value;
                }
            }
            $at += $size;
        }
        return 1;
    }

    private static function tiff(string $data): ?int
    {
        $length = strlen($data);
        if ($length < 8) {
            return null;
        }
        $order = substr($data, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return null;
        }
        $u16 = static function (int $at) use ($data, $length, $order): ?int {
            if ($at < 0 || $at + 2 > $length) {
                return null;
            }
            return $order === 'II'
                ? ord($data[$at]) | (ord($data[$at + 1]) << 8)
                : (ord($data[$at]) << 8) | ord($data[$at + 1]);
        };
        $u32 = static function (int $at) use ($data, $length, $order): ?int {
            if ($at < 0 || $at + 4 > $length) {
                return null;
            }
            return $order === 'II'
                ? ord($data[$at]) |
                        (ord($data[$at + 1]) << 8) |
                        (ord($data[$at + 2]) << 16) |
                        (ord($data[$at + 3]) << 24)
                : (ord($data[$at]) << 24) |
                        (ord($data[$at + 1]) << 16) |
                        (ord($data[$at + 2]) << 8) |
                        ord($data[$at + 3]);
        };
        if ($u16(2) !== 42) {
            return null;
        }
        $ifd = $u32(4);
        if ($ifd === null || $ifd < 8 || $ifd + 2 > $length) {
            return null;
        }
        $count = $u16($ifd);
        if ($count === null || $count > 4096 || $ifd + 2 + $count * 12 > $length) {
            return null;
        }
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($u16($entry) === 0x0112 && $u16($entry + 2) === 3 && $u32($entry + 4) === 1) {
                $value = $u16($entry + 8);
                return $value !== null && $value >= 1 && $value <= 8 ? $value : null;
            }
        }
        return null;
    }

    public static function orient(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }
        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagedestroy($image);
        if (!$rotated) {
            throw new Problem(
                'preview_unavailable',
                'Preview unavailable. You can still download the original.',
                422,
            );
        }
        return $rotated;
    }

    /** Convert a legacy raw-pixel crop into the displayed, oriented coordinate space. */
    public static function crop(array $crop, int $orientation): array
    {
        $x = (float) $crop['x'];
        $y = (float) $crop['y'];
        $w = (float) $crop['width'];
        $h = (float) $crop['height'];
        return match ($orientation) {
            2 => ['x' => 1 - $x - $w, 'y' => $y, 'width' => $w, 'height' => $h],
            3 => ['x' => 1 - $x - $w, 'y' => 1 - $y - $h, 'width' => $w, 'height' => $h],
            4 => ['x' => $x, 'y' => 1 - $y - $h, 'width' => $w, 'height' => $h],
            5 => ['x' => $y, 'y' => $x, 'width' => $h, 'height' => $w],
            6 => ['x' => 1 - $y - $h, 'y' => $x, 'width' => $h, 'height' => $w],
            7 => ['x' => 1 - $y - $h, 'y' => 1 - $x - $w, 'width' => $h, 'height' => $w],
            8 => ['x' => $y, 'y' => 1 - $x - $w, 'width' => $h, 'height' => $w],
            default => ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h],
        };
    }
}
