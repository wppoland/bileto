<?php

declare(strict_types=1);

namespace Bileto;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

defined('ABSPATH') || exit;

/**
 * QR codes generated locally (no external service). The code holds only the
 * ticket token; everything else is looked up on the server at check-in.
 */
final class Qr
{
    public static function svg(string $token): string
    {
        return (new QRCode(new QROptions([
            'outputType'     => 'svg',
            'outputBase64'   => false,
            'addQuietzone'   => true,
            'svgAddXmlHeader' => false,
            'eccLevel'       => \chillerlan\QRCode\Common\EccLevel::M,
        ])))->render($token);
    }

    /** PNG for e-mail clients, which do not show inline SVG. Falls back to SVG without GD. */
    public static function sendPng(string $token): void
    {
        if (! extension_loaded('gd')) {
            header('Content-Type: image/svg+xml');
            echo self::svg($token); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image body.

            return;
        }
        $png = (new QRCode(new QROptions([
            'outputType'   => 'png',
            'outputBase64' => false,
            'scale'        => 8,
            'eccLevel'     => \chillerlan\QRCode\Common\EccLevel::M,
        ])))->render($token);
        header('Content-Type: image/png');
        echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image body.
    }
}
