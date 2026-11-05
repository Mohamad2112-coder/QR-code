<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Common\Version;
use chillerlan\QRCode\Output\QRMarkupSVG;

class QRCodeService
{
    /**
     * Build the full public customer tracking URL for a given order token.
     */
    public static function getTrackingUrl(string $token): string
    {
        $baseUrl = rtrim((string)Config::get('app.url', 'http://localhost:8000'), '/');
        return "{$baseUrl}/track/{$token}";
    }

    /**
     * Generate an inline Data URI SVG QR Code string for embedding in HTML or printing.
     */
    public static function generateSvgDataUri(string $url): string
    {
        $options = new QROptions([
            'version'             => Version::AUTO,
            'outputType'          => QRMarkupSVG::class,
            'eccLevel'            => EccLevel::M,
            'imageBase64'         => true,
            'addQuietzone'        => true,
            'quietzoneSize'       => 2,
            'drawLightModules'    => false,
            'svgUseFileTag'       => false,
        ]);

        $qrcode = new QRCode($options);
        return $qrcode->render($url);
    }

    /**
     * Generate raw SVG markup for direct inline rendering.
     */
    public static function generateSvgMarkup(string $url): string
    {
        $options = new QROptions([
            'version'             => Version::AUTO,
            'outputType'          => QRMarkupSVG::class,
            'eccLevel'            => EccLevel::M,
            'imageBase64'         => false,
            'addQuietzone'        => true,
            'quietzoneSize'       => 2,
            'drawLightModules'    => false,
        ]);

        $qrcode = new QRCode($options);
        return $qrcode->render($url);
    }
}
