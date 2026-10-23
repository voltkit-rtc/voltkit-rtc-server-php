<?php

declare(strict_types=1);

namespace VoltKit\Rtc;

/** A participant's role in a room, signed into the token the backend mints; every method also takes the plain string. */
enum Role: string
{
    case Host = 'host';
    case Cohost = 'cohost';
    case Moderator = 'moderator';
    case Viewer = 'viewer';
}
