<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WhatsAppTemplateResource\Pages;
use App\Models\WhatsAppTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\BadgeColumn;
use App\Services\WhatsAppTemplateService;

class WhatsAppTemplateResource extends Resource
{
    protected static ?string $model = WhatsAppTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'WhatsApp Service';

    protected static ?string $navigationLabel = 'WhatsApp Templates';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('category')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('status')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('languages')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('category'),
                // Ganti TextColumn jadi BadgeColumn
                BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => 'APPROVED',
                        'warning' => 'PENDING',
                        'danger'  => 'REJECTED',
                    ]),
                Tables\Columns\TextColumn::make('languages'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('Preview Template')
                    ->modalContent(fn ($record) => view('preview-template', [
                        'components' => $record->components,
                    ]))
                    ->button(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('Sync Now')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('success')
                    ->action(function () {
                        try {
                            $count = app(WhatsAppTemplateService::class)->sync();
                            \Filament\Notifications\Notification::make()
                                ->title("Synced $count templates")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            \Log::error($e);
                            \Filament\Notifications\Notification::make()
                                ->title('Sync failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),                    
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWhatsAppTemplates::route('/'),
            'create' => Pages\CreateWhatsAppTemplate::route('/create'),
            'edit' => Pages\EditWhatsAppTemplate::route('/{record}/edit'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'WhatsApp Template'; // singular
    }

    public static function getPluralModelLabel(): string
    {
        return 'WhatsApp Templates'; // plural
    }
}
