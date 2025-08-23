<?php

namespace App\Filament\Resources\BroadcastReportResource\Pages;

use App\Filament\Resources\BroadcastReportResource;
use Filament\Resources\Pages\ListRecords;

class ListBroadcastReports extends ListRecords
{
    protected static string $resource = BroadcastReportResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\BroadcastKpis::class,
            \App\Filament\Widgets\BroadcastDailyChart::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return []; // Actions khusus list (jika perlu)
    }

    public function getTitle(): string
    {
        return 'Broadcast Reports';
    }
}
