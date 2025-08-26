<?php

namespace App\Filament\Resources\WhatsappInboxResource\Pages;

use App\Filament\Resources\WhatsappInboxResource;
use Filament\Resources\Pages\ListRecords;

class ListThreads extends ListRecords
{
    protected static string $resource = WhatsappInboxResource::class;
    protected function getHeaderWidgets(): array { return []; }
}
