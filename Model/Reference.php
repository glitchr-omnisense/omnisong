<?php

namespace Omnisong\Model;

use Omnisong\Platform;

/**
 * What a catalogue is asked about: a URL on any platform, an ISRC (a
 * recording), a UPC/EAN (a release), or a platform's own id. One of them
 * is enough; a catalogue that cannot read the kind given says so
 * (NotSupportedException).
 */
final class Reference
{
    public const SONG = 'song';
    public const ALBUM = 'album';

    private function __construct(
        public readonly ?string $url = null,
        public readonly ?string $isrc = null,
        public readonly ?string $upc = null,
        public readonly ?Platform $platform = null,
        public readonly ?string $id = null,
        /** self::SONG or self::ALBUM, when known */
        public readonly ?string $type = null,
        /** ISO 3166-1 alpha-2, for the store the links should point at */
        public readonly ?string $country = null,
    ) {
    }

    public static function url(string $url, ?string $country = null): self
    {
        return new self(url: $url, platform: Platform::fromUrl($url), country: $country);
    }

    public static function isrc(string $isrc, ?string $country = null): self
    {
        return new self(isrc: strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $isrc) ?? ''), type: self::SONG, country: $country);
    }

    public static function upc(string $upc, ?string $country = null): self
    {
        return new self(upc: preg_replace('/\D/', '', $upc) ?? '', type: self::ALBUM, country: $country);
    }

    public static function id(Platform $platform, string $id, string $type = self::ALBUM, ?string $country = null): self
    {
        return new self(platform: $platform, id: $id, type: $type, country: $country);
    }

    /** A loose string: a URL, 12-14 digits (UPC/EAN), 12 alphanumerics (ISRC). */
    public static function parse(string $value, ?string $country = null): self
    {
        $value = trim($value);
        if (preg_match('#^https?://#i', $value)) {
            return self::url($value, $country);
        }
        if (preg_match('/^\d{12,14}$/', $value)) {
            return self::upc($value, $country);
        }
        if (preg_match('/^[A-Za-z]{2}[A-Za-z0-9]{3}\d{7}$/', str_replace('-', '', $value))) {
            return self::isrc($value, $country);
        }

        throw new \Omnisong\Exception\InvalidReferenceException(\sprintf('"%s" is neither a URL, a UPC/EAN nor an ISRC.', $value));
    }

    public function withCountry(?string $country): self
    {
        return new self($this->url, $this->isrc, $this->upc, $this->platform, $this->id, $this->type, $country);
    }

    public function __toString(): string
    {
        return $this->url ?? $this->isrc ?? $this->upc ?? ($this->platform ? $this->platform->value.':'.$this->id : '');
    }
}
