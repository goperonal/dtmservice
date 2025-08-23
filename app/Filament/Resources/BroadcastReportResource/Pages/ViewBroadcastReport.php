<?php

namespace App\Filament\Resources\BroadcastReportResource\Pages;

use App\Filament\Resources\BroadcastReportResource;
use Filament\Resources\Pages\ViewRecord;

class ViewBroadcastReport extends ViewRecord
{
    protected static string $resource = BroadcastReportResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();
        return "Detail Broadcast #{$record->id}";
    }
}
