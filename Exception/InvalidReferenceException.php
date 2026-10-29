<?php

namespace Omnisong\Exception;

/** A value Reference::parse() cannot read: neither a URL, a UPC/EAN nor an ISRC. */
final class InvalidReferenceException extends \InvalidArgumentException implements OmnisongException
{
}
