<?php

namespace App\Interfaces\Telegram\Processing;

enum ClaimState
{
    case Claimed;
    case Processed;
    case Busy;
}
