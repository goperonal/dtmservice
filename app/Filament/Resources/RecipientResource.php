<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RecipientResource\Pages;
use App\Filament\Resources\RecipientResource\RelationManagers;
use App\Models\Recipient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RecipientResource extends Resource
{
    protected static ?string $model = Recipient::class;
    protected static ?string $navigationIcon = 'heroicon-o-phone';
    protected static ?string $navigationGroup = 'WhatsApp Service';

    public static function form(Forms\Form $form): Forms\Form
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

                    Forms\Components\Select::make('group')
                    ->label('Group')
                    ->searchable()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('group')
                            ->label('Group Name')
                            ->required(),
                    ])
                    ->createOptionUsing(function (array $data) {
                        return $data['group']; // Kembalikan value yang akan dipakai di field
                    })
                    ->options(fn () => \App\Models\Recipient::query()
                        ->whereNotNull('group')
                        ->distinct()
                        ->pluck('group', 'group')
                        ->filter()
                ),                
                
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3),
            ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('phone'),
                Tables\Columns\TextColumn::make('group'),
                Tables\Columns\TextColumn::make('notes')->limit(50),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('group')
                    ->options(Recipient::query()->distinct()->pluck('group', 'group')->filter()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
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
