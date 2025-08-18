<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RecipientResource\Pages;
use App\Models\Recipient;
use App\Models\Group;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Maatwebsite\Excel\Facades\Excel;
use App\Services\RecipientsImport;
use Filament\Tables\Filters\SelectFilter;

class RecipientResource extends Resource
{
    protected static ?string $model = Recipient::class;
    protected static ?string $navigationIcon = 'heroicon-o-phone';
    protected static ?string $navigationGroup = 'WhatsApp Service';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(100),

                Forms\Components\TextInput::make('phone')
                    ->required()
                    ->label('Phone Number (e.g. 6281234567890)')
                    ->maxLength(15)
                    ->unique(ignoreRecord: true)
                    ->regex('/^628[0-9]{7,12}$/')
                    ->helperText('Gunakan format 628xxxxxxxxx'),

                // relasi ke banyak group
                Forms\Components\Select::make('groups')
                    ->label('Groups')
                    ->multiple()
                    ->relationship('groups', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')
                            ->label('Group Name')
                            ->required(),
                    ]),

                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('phone')->sortable()->searchable(),

                Tables\Columns\TextColumn::make('groups.name')
                    ->label('Groups')
                    ->badge()
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('notes')
                    ->searchable()
                    ->limit(50),
            ])
            ->filters([
                SelectFilter::make('groups')
                    ->label('Group')
                    ->relationship('groups', 'name'),
            ])
            ->headerActions([
                ExportAction::make()
                    ->icon('heroicon-o-document-arrow-up'),
                Action::make('import')
                    ->label('Import')
                    ->icon('heroicon-o-document-arrow-down')
                    ->form([
                        FileUpload::make('file')
                            ->label('Pilih File Excel')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                            ])
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $path = storage_path('app/public/' . $data['file']);
                        Excel::import(new RecipientsImport, $path);

                        \Filament\Notifications\Notification::make()
                            ->title('Import berhasil')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
                ExportBulkAction::make()
                    ->icon('heroicon-o-document-arrow-up'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecipients::route('/'),
            'create' => Pages\CreateRecipient::route('/create'),
            'edit' => Pages\EditRecipient::route('/{record}/edit'),
        ];
    }
}
