<?php

namespace App\Enums;

enum MediaEventSource: string
{
    case Manual = 'manual';
    case Player = 'player';
    case Import = 'import';
    case Provider = 'provider';
    case Metadata = 'metadata';
    case System = 'system';
}
