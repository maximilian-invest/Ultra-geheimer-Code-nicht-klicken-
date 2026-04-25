<?php

namespace App\Support;

use App\Models\PropertyImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Erzeugt JPEG-optimierte Kopien von PropertyImages fuer das Exposé.
 *
 * Hintergrund: Original-Uploads sind oft 1500–3000 px breite PNGs mit
 * 1,5–3 MB pro Bild. Bei einem Exposé mit 8 Bildern landet die PDF
 * dadurch bei 13–25 MB — viel zu groß zum Versand per Mail. Diese Klasse
 * generiert on-the-fly verkleinerte JPEGs und cached sie auf Disk.
 *
 * Cache-Key: property_image.id + Zielbreite. Wird die Property-Image
 * geloescht/ersetzt, bekommt sie eine neue ID und der Cache-Eintrag
 * wird damit automatisch obsolet (alte Eintraege bleiben harmlos liegen
 * bis ein Cron sie aufraeumt).
 */
class ExposeImage
{
    public const SIZE_COVER  = 1920; // Vollflaechen-Hintergruende (Cover, Intro)
    public const SIZE_LARGE  = 1280; // Standard fuer Detail-/Haus-/Lage-Bilder
    public const SIZE_MEDIUM = 900;  // Mehrbild-Layouts (Impressionen L4, LM)

    public const QUALITY = 82;

    /**
     * Gibt die URL der optimierten JPEG-Version zurueck. Generiert die
     * Datei beim ersten Aufruf falls nicht vorhanden. Fallback auf das
     * Original, wenn die Generierung fehlschlaegt.
     */
    public static function url(?PropertyImage $img, int $maxWidth = self::SIZE_LARGE): ?string
    {
        if (!$img || empty($img->path)) return null;

        $cachePath = "expose-cache/{$img->id}-{$maxWidth}.jpg";
        $disk = Storage::disk('public');

        if (!$disk->exists($cachePath)) {
            $ok = self::generate($disk->path($img->path), $disk->path($cachePath), $maxWidth);
            if (!$ok) {
                // Fallback auf Original — besser ein zu großer PDF als
                // ein PDF ohne Bilder.
                return asset('storage/' . $img->path);
            }
        }

        return asset('storage/' . $cachePath);
    }

    /**
     * Erzeugt eine JPEG-Datei aus der Source. Gibt true zurueck wenn
     * erfolgreich, false sonst. Nicht-bekannte Dateitypen werden uebersprungen.
     */
    private static function generate(string $srcAbs, string $outAbs, int $maxWidth): bool
    {
        if (!is_file($srcAbs)) return false;

        $dir = dirname($outAbs);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            Log::warning('ExposeImage: cannot create cache dir', ['dir' => $dir]);
            return false;
        }

        $ext = strtolower(pathinfo($srcAbs, PATHINFO_EXTENSION));
        $src = match ($ext) {
            'png'         => @imagecreatefrompng($srcAbs),
            'jpg', 'jpeg' => @imagecreatefromjpeg($srcAbs),
            'gif'         => @imagecreatefromgif($srcAbs),
            'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcAbs) : null,
            default       => null,
        };
        if (!$src) {
            Log::warning('ExposeImage: unsupported or unreadable image', ['path' => $srcAbs, 'ext' => $ext]);
            return false;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);

        if ($sw <= $maxWidth) {
            // Bereits klein genug — nur Format-Konversion ohne Resize.
            $tw = $sw;
            $th = $sh;
        } else {
            $tw = $maxWidth;
            $th = (int) round($sh * ($maxWidth / $sw));
        }

        $dst = imagecreatetruecolor($tw, $th);
        // PNG-Transparenz → weiss (JPEG kennt keinen Alpha-Kanal).
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);

        $ok = @imagejpeg($dst, $outAbs, self::QUALITY);

        imagedestroy($src);
        imagedestroy($dst);

        if (!$ok) {
            Log::warning('ExposeImage: imagejpeg failed', ['out' => $outAbs]);
            return false;
        }
        return true;
    }
}
