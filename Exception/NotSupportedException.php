<?php

namespace Omnisong\Exception;

use Omnisense\Contracts\Exception\NotSupportedException as KernelNotSupportedException;
use Omnisong\Model\Reference;

/** The catalogue does not read that kind of reference, or does not do that at all. */
final class NotSupportedException extends KernelNotSupportedException implements OmnisongException
{
    public static function reference(string $catalog, Reference $reference): self
    {
        return new self(\sprintf('The "%s" catalogue cannot look up "%s".', $catalog, (string) $reference));
    }

    public static function operation(string $catalog, string $operation): self
    {
        return new self(\sprintf('The "%s" catalogue does not %s.', $catalog, $operation));
    }
}
