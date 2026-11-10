<?php

namespace Omnisong\Policy;

/**
 * A catalogue that says how many HTTP requests it has sent: a call answered
 * from what it already holds (Odesli's links() and release() of the same
 * recording, one answer) or refused before any request (a reference it
 * cannot read) then costs nothing under its usage policy. A catalogue
 * without it is counted one request a call, except for a call it does not
 * support.
 */
interface CountsRequestsInterface
{
    /** The HTTP requests sent since the catalogue was built. */
    public function sentRequests(): int;
}
