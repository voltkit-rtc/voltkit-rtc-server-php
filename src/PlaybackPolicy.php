<?php

declare(strict_types=1);

namespace VoltKit\Rtc;

/** Who may watch a broadcast stream: anyone with the URL, or only a viewer holding a playback token. */
enum PlaybackPolicy: string
{
    case Public = 'public';
    case Token = 'token';
}
