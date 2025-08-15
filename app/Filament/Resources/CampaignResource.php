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
use App\Filament\Resources\BroadcastResource;
use Filament\Forms\Get;

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;
    protected static ?string $navigationIcon = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup = 'WhatsApp Service';

    protected static ?int $navigationSort = 3;

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

                    Forms\Components\Select::make('send_mode')
                    ->label('Send Mode')
                    ->options([
                        'single' => 'Single Recipient',
                        'group'  => 'Recipient Group',
                    ])
                    ->default('single') // pastikan ada nilai awal
                    ->reactive()
                    ->required()
                    ->dehydrated(true), // biar ikut masuk ke state
                
                Forms\Components\Select::make('recipient_id')
                    ->label('Recipient')
                    ->options(Recipient::query()->orderBy('name')->pluck('name', 'id'))
                    ->multiple()
                    ->searchable()
                    ->visible(fn (Get $get) => $get('send_mode') === 'single')
                    ->dehydrated(fn (Get $get) => $get('send_mode') === 'single'),
                
                Forms\Components\Select::make('group')
                    ->label('Group')
                    ->options(
                        Recipient::query()
                            ->whereNotNull('group')->where('group', '!=', '')
                            ->distinct()->orderBy('group')
                            ->pluck('group', 'group')
                    )
                    ->searchable()
                    ->visible(fn (Get $get) => $get('send_mode') === 'group')
                    ->dehydrated(fn (Get $get) => $get('send_mode') === 'group'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('whatsappTemplate.name')->label('Template'),
                Tables\Columns\TextColumn::make('broadcast_messages_count')->label('Recipients'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordUrl(function ($record) {
                return BroadcastResource::getUrl('index', [
                    'tableFilters[campaign_id][value]' => $record->id
                ]);
            })
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
