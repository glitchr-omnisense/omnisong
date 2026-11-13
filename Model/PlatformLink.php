<?php

namespace Omnisong\Model;

use Omnisong\Platform;

/** One place the recording is: the platform, the URL, the platform's own id when known. */
final class PlatformLink
{
    public function __construct(
        public readonly Platform $platform,
        public readonly string $url,
        public readonly ?string $id = null,
        /** For LABEL and SHOP: the name to show ("Label Exemple", "Presto Music") */
        public readonly ?string $title = null,
        public readonly ?string $country = null,
    ) {
    }

    public function title(): string
    {
        return $this->title ?? $this->platform->label();
    }
}
