<?php

declare(strict_types=1);

namespace Bileto;

use Bileto\Vendor\chillerlan\QRCode\QRCode;
use Bileto\Vendor\chillerlan\QRCode\QROptions;

defined('ABSPATH') || exit;

/**
 * QR codes generated locally (no external service). The QR holds only the
 * ticket's check-in code; everything else is looked up on the server.
 */
final class Qr
{
    public static function svg(string $code): string
    {
        return (new QRCode(new QROptions([
            'outputType'     => 'svg',
            'outputBase64'   => false,
            'addQuietzone'   => true,
            'svgAddXmlHeader' => false,
            'eccLevel'       => \Bileto\Vendor\chillerlan\QRCode\Common\EccLevel::M,
        ])))->render($code);
    }

    public static function canPng(): bool
    {
        return extension_loaded('gd');
    }

    /** PNG for e-mail clients, which do not show inline SVG. Needs GD (see canPng()). */
    public static function sendPng(string $code): void
    {
        $png = (new QRCode(new QROptions([
            'outputType'   => 'png',
            'outputBase64' => false,
            'scale'        => 8,
            'eccLevel'     => \Bileto\Vendor\chillerlan\QRCode\Common\EccLevel::M,
        ])))->render($code);
        header('Content-Type: image/png');
        echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image body.
    }
}
