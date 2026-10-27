<?php

namespace App\Http\Controllers;

use App\Models\BrandingSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class BrandingLogoController extends Controller
{
    public function __invoke(string $variant = 'light'): Response
    {
        abort_unless(Schema::hasTable('branding_settings'), 404);

        $path = BrandingSetting::current()->logoPath($variant);
        abort_unless($path !== null, 404);

        $disk = Storage::disk(BrandingSetting::LOGO_DISK);

        // The sandboxing CSP neutralises scripts embedded in an uploaded SVG if the file is opened directly.
        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
