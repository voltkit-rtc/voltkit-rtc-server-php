<?php

declare(strict_types=1);

namespace VoltKit\Rtc;

/** What a stream token is for: watching (the default) or publishing. */
enum StreamAction: string
{
    case Play = 'play';
    case Publish = 'publish';
}
