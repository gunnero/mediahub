<?php

namespace App\Enums;

enum ProviderSyncStatus: string
{
    case NeverSynced = 'never_synced';
    case Syncing = 'syncing';
    case Completed = 'completed';
    case CompletedWithWarnings = 'completed_with_warnings';
    case Failed = 'failed';

    public static function normalize(?string $status): string
    {
        return match ($status) {
            'idle', null, '' => self::NeverSynced->value,
            'ready' => self::Completed->value,
            default => $status,
        };
    }
}
