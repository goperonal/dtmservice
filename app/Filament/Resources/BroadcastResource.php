<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BroadcastResource\Pages;
use App\Filament\Resources\BroadcastResource\RelationManagers;
use App\Models\Broadcast;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Models\WhatsAppTemplate;
use App\Models\Recipient;
use App\Models\BroadcastMessage;
use App\Models\Campaign;

class BroadcastResource extends Resource
{
    protected static ?string $model = BroadcastMessage::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = 'WhatsApp Service';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('campaign.name')
                    ->label('Campaign Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('template.name')
                    ->label('Template')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('recipient.name')
                    ->label('Recipients'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status'),
                Tables\Columns\TextColumn::make('sent_at')
                    ->dateTime()
                    ->label('Sent At'),
            ])
            ->actions([
                //
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('campaign_id')
                ->label('Campaign')
                ->options(
                    Campaign::query()
                        ->distinct()
                        ->orderBy('name')
                        ->pluck('name', 'id') // key = id, value = name
                        ->filter()
                )            
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
            'index' => Pages\ListBroadcasts::route('/')
        ];
    }
}
