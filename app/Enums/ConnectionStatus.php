<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case Active = 'active';

    /** Access was revoked or expired; the host must reconnect. */
    case Revoked = 'revoked';
}
