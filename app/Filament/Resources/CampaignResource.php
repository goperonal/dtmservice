<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignResource\Pages;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\WhatsAppTemplate;
use App\Models\BroadcastMessage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;
    protected static ?string $navigationIcon = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup = 'WhatsApp Service';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Campaign Name')
                    ->required(),

                Forms\Components\Select::make('whatsapp_template_id')
                    ->label('WhatsApp Template')
                    ->options(WhatsAppTemplate::all()->pluck('name', 'id'))
                    ->required()
                    ->searchable(),

                Forms\Components\Select::make('recipients')
                    ->label('Recipients')
                    ->multiple()
                    ->relationship('recipients', 'name')
                    ->required()
                    ->searchable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('whatsappTemplate.name')->label('Template'),
                Tables\Columns\TextColumn::make('broadcast_messages_count')->label('Recipients'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
            ])
            ->actions([
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
                ->with('whatsappTemplate')
                ->withCount('broadcastMessages');
    }
}
