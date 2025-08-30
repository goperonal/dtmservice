<?php

namespace App\Filament\Resources\RecipientResource\Pages;

use App\Filament\Resources\RecipientResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;
use Filament\Tables\Table;

class ListRecipients extends ListRecords
{
    protected static string $resource = RecipientResource::class;

    // HARUS public, bukan protected
    public function table(Table $table): Table
    {
        // Pertahankan konfigurasi table dari Resource, hanya ubah pagination options
        return parent::table($table)
            ->paginated([10, 25, 50, 100])   // tanpa 'all'
            ->defaultPaginationPageOption(25); // opsional
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('info')
                ->label(function () {
                    $total   = $this->getFilteredTableQuery()->count();
                    $perPage = $this->getTableRecordsPerPage();
                    $page    = max(1, $this->getTablePage());

                    if ($perPage === 'all' || $perPage === null) {
                        $from = $total ? 1 : 0;
                        $to   = $total;
                    } else {
                        $perPage = (int) $perPage;
                        $from = $total ? (($page - 1) * $perPage) + 1 : 0;
                        $to   = min($page * $perPage, $total);
                    }

                    return "Recipient {$from}-{$to} dari {$total}";
                })
                ->disabled()
                ->color('gray')
                ->extraAttributes(['class' => 'cursor-default']),

            Actions\CreateAction::make(),
        ];
    }
}
