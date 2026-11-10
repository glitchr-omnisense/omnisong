<?php

namespace Omnisong\Exception;

use Omnisense\Contracts\Exception\InvalidConfigException as KernelInvalidConfigException;

/** A catalogue misconfigured: a key missing, an unknown factory (a \LogicException, the kernel's InvalidConfigException). */
final class InvalidConfigException extends KernelInvalidConfigException implements OmnisongException
{
}
