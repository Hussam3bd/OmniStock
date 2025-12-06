<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects;

class LabelResponse
{
    public function __construct(
        public readonly string $labelContent,
        public readonly string $format, // 'svg', 'pdf', etc.
        public readonly string $trackingNumber,
        public readonly ?string $url = null
    ) {}

    public static function fromSvg(string $svgContent, string $trackingNumber): self
    {
        return new self(
            labelContent: $svgContent,
            format: 'svg',
            trackingNumber: $trackingNumber
        );
    }

    public static function fromPdf(string $pdfContent, string $trackingNumber): self
    {
        return new self(
            labelContent: $pdfContent,
            format: 'pdf',
            trackingNumber: $trackingNumber
        );
    }

    public static function fromUrl(string $url, string $trackingNumber): self
    {
        return new self(
            labelContent: '',
            format: 'url',
            trackingNumber: $trackingNumber,
            url: $url
        );
    }

    public function getContentType(): string
    {
        return match ($this->format) {
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    public function getFileExtension(): string
    {
        return match ($this->format) {
            'svg' => '.svg',
            'pdf' => '.pdf',
            'png' => '.png',
            'jpg', 'jpeg' => '.jpg',
            default => '.bin',
        };
    }

    public function toBase64(): string
    {
        if ($this->format === 'url') {
            return '';
        }

        return base64_encode($this->labelContent);
    }
}
