<?php

namespace App\Filament\Resources\RecipientResource\Pages;

use App\Filament\Resources\RecipientResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions; // <-- pakai Page Actions

class ListRecipients extends ListRecords
{
    protected static string $resource = RecipientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('info')    // <-- BUKAN Tables\Actions\Action
                ->label(function () {
                    // total sesuai filter/search aktif
                    $total  = $this->getFilteredTableQuery()->count();

                    // state pagination tabel
                    $perPage = $this->getTableRecordsPerPage();
                    $page    = max(1, $this->getTablePage());

                    // hitung range tampilan saat ini
                    $from = $total ? (($page - 1) * $perPage) + 1 : 0;
                    $to   = min($page * $perPage, $total);

                    return "Recipient {$from}-{$to} dari {$total}";
                })
                ->disabled()
                ->color('gray')
                ->extraAttributes(['class' => 'cursor-default']),

            Actions\CreateAction::make(),
        ];
    }
}
