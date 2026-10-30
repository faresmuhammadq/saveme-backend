<?php

namespace App\Modules\Admin\Http\Presenters;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Draws the authenticator setup link as an inline SVG QR code, rendered on
 * the server so the secret never reaches a third party (spec 018, R-4).
 */
class SetupQrCodePresenter
{
    public function svg(string $link, int $size = 192): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd));

        return $writer->writeString($link);
    }
}
