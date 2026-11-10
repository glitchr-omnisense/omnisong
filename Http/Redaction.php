<?php

namespace Omnisong\Http;

use Omnisense\Contracts\Http\Redaction as KernelRedaction;

/**
 * What a failed call may carry of its credentials, taken out before the
 * failure leaves the package: an HTTP client's exception quotes the URL
 * called (a key in its query, a password in its user part), and every
 * exception's trace keeps the arguments of the calls under it (the request
 * options, headers and query with the key) unless PHP runs with
 * zend.exception_ignore_args.
 *
 * copy() gives the previous exception to wrap instead of the client's own:
 * the same class and code, its message with the key's parameters, the user
 * part and every secret value replaced by ***, its trace without arguments,
 * its own previous copied the same way. Properties of the client's class
 * beyond those of \Exception are not copied (a Symfony HttpException's
 * response, a PSR-18 exception's request: their URL holds the key).
 * withoutArguments() takes the arguments out of the trace of the exception
 * thrown in its place.
 *
 * The kernel's (omnisense/contracts), under omnisong's name.
 */
final class Redaction extends KernelRedaction
{
}
