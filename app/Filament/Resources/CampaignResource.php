<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignResource\Pages;
use App\Filament\Resources\BroadcastResource;
use App\Models\Campaign;
use App\Models\Group;
use App\Models\Recipient;
use App\Models\WhatsAppTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Actions\Action as FieldAction;
use Filament\Forms\Components\View as ViewComponent;
use Illuminate\Support\Facades\View as ViewFacade;
use Filament\Forms\Components\Grid;

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;
    protected static ?string $navigationIcon = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup = 'WhatsApp Services';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Campaign Name')
                ->required(),

            // === PILIH TEMPLATE (pakai NAME) → SCAN BODY + RESET MAPPING ===
            Forms\Components\Select::make('whatsapp_template_name')
                ->label('WhatsApp Template')
                ->options(
                    WhatsAppTemplate::query()
                        ->where('status', 'APPROVED')
                        ->orderBy('name')
                        ->pluck('name', 'name') // key & value = name
                )
                ->required()
                ->searchable()
                ->live()
                ->afterStateHydrated(function (Set $set, $state) {
                    if ($state) self::scanTemplateAndSetBindingsByName($set, (string) $state, 'hydrated');
                })
                ->afterStateUpdated(function (Set $set, $state) {
                    self::scanTemplateAndSetBindingsByName($set, (string) $state, 'updated');
                })
                ->suffixAction(
                    FieldAction::make('scanTemplate')
                        ->icon('heroicon-m-magnifying-glass')
                        ->tooltip('Scan placeholders pada template')
                        ->action(function (Get $get, Set $set) {
                            $name = (string) $get('whatsapp_template_name');
                            self::scanTemplateAndSetBindingsByName($set, $name, 'manual');
                        })
                ),

            // === Mode kirim ===
            Forms\Components\Select::make('send_mode')
                ->label('Send Mode')
                ->options(['single' => 'Single Recipient', 'group' => 'Recipient Group'])
                ->default('single')
                ->live()
                ->required()
                ->dehydrated(true),

            Forms\Components\Select::make('recipient_id')
                ->label('Recipient')
                ->options(Recipient::query()->orderBy('name')->pluck('name', 'id'))
                ->multiple()
                ->searchable()
                ->visible(fn (Get $get) => $get('send_mode') === 'single')
                ->dehydrated(fn (Get $get) => $get('send_mode') === 'single')
                ->required()
                ->live(),

            Forms\Components\Select::make('group_id')
                ->label('Group')
                ->options(Group::query()->orderBy('name')->pluck('name', 'id'))
                ->multiple()
                ->searchable()
                ->visible(fn (Get $get) => $get('send_mode') === 'group')
                ->dehydrated(fn (Get $get) => $get('send_mode') === 'group')
                ->live(),

            // === MAPPER & PREVIEW berdampingan 50:50 ===
            Grid::make(12)
                ->visible(fn (Get $get) => filled($get('whatsapp_template_name')))
                ->schema([

                    // KIRI: Template Variables (50%)
                    Forms\Components\Fieldset::make('Template Variables')
                        ->columnSpan(['default' => 12, 'md' => 6])
                        ->schema([
                            Forms\Components\Hidden::make('tpl_components')->dehydrated(false),
                            Forms\Components\Hidden::make('debug_body')->dehydrated(false),
                            Forms\Components\Hidden::make('debug_components_json')->dehydrated(false),

                            Forms\Components\Repeater::make('variable_bindings')
                                ->label('Map Variables to Recipient Fields')
                                ->default([])
                                ->columns(12)
                                ->dehydrated()
                                ->live()
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->schema([
                                    Forms\Components\TextInput::make('placeholder')
                                        ->label('Placeholder')
                                        ->readOnly()
                                        ->columnSpan(6),
                                    Forms\Components\Select::make('recipient_field')
                                        ->label('Recipient Field')
                                        ->options([
                                            'name'  => 'Name',
                                            'group' => 'Group',
                                            'phone' => 'Phone',
                                            'notes' => 'Notes',
                                        ])
                                        ->required()
                                        ->searchable()
                                        ->live()
                                        ->columnSpan(6),
                                ])
                                ->columnSpan(12),
                        ]),

                    // KANAN: Preview (50%)
                    ViewComponent::make('filament.whatsapp-template-preview')
                        ->columnSpan(['default' => 12, 'md' => 6])
                        ->reactive()
                        ->viewData(function (Get $get) {
                            $components = $get('tpl_components');

                            if (!is_array($components)) {
                                $tpl = WhatsAppTemplate::where('name', (string) $get('whatsapp_template_name'))->first();
                                $components = self::getTemplateComponentsArray($tpl);
                            }

                            [$headerType, $headerText, $headerImageUrl, $bodyText, $footerText, $tplButtons]
                                = self::extractPartsForPreview($components);

                            $headerText = self::normalizeText((string) $headerText);
                            $bodyText   = self::normalizeText((string) $bodyText);
                            $footerText = self::normalizeText((string) $footerText);

                            if ($headerType === null && trim($bodyText) === '' && trim((string) $footerText) === '') {
                                $bodyText = '⚠️ BODY kosong / komponen belum terbaca.';
                            }

                            return [
                                'phoneFrame'     => false,
                                'headerType'     => $headerType,
                                'headerText'     => $headerText,
                                'headerImageUrl' => $headerImageUrl,
                                'bodyText'       => $bodyText,
                                'footerText'     => $footerText,
                                'tplButtons'     => $tplButtons,
                            ];
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                // tampilkan nama template dari accessor snapshot/relasi-by-name
                Tables\Columns\TextColumn::make('template_display')
                    ->label('Template')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('broadcast_messages_count')
                    ->label('Recipients'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d M Y H:i', 'Asia/Jakarta')
                    ->sortable(),
            ])
            ->recordUrl(fn ($record) => BroadcastResource::getUrl('index', [
                'tableFilters[campaign_id][value]' => $record->id
            ]))
            ->filters([])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array { return []; }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            // kalau mau tetap eager load relasi by name:
            ->with('whatsappTemplateByName')
            ->withCount('broadcastMessages');
    }

    // ================= Helpers (BY NAME) =================

    /** Scan template by NAME → set ulang mappings dan simpan komponen + body ke state (untuk preview) */
    protected static function scanTemplateAndSetBindingsByName(Set $set, ?string $templateName, string $from = 'unknown'): void
    {
        // reset supaya repeater & preview benar2 fresh
        $set('variable_bindings', []);
        $set('tpl_components', null);
        $set('debug_body', null);
        $set('debug_components_json', null);

        if (blank($templateName)) return;

        $tpl = WhatsAppTemplate::where('name', $templateName)->first();
        if (!$tpl) return;

        $components = self::getTemplateComponentsArray($tpl);
        $set('tpl_components', $components); // penting untuk preview

        // simpan sample komponen ke debug
        $sample = is_array($components) ? array_slice($components, 0, 2) : $components;
        $set('debug_components_json', json_encode($sample, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

        // BODY.text → cari {{n}}, fallback scan semua komponen
        $rawBody = self::extractBodyTextFromComponents($components);
        $set('debug_body', $rawBody); // untuk memastikan body memang terbaca

        $bodyText = self::normalizeText($rawBody);
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/u', $bodyText, $m);
        $nums = array_values(array_unique($m[1] ?? []));

        if (empty($nums)) {
            $allText = self::collectAllStrings($components);
            $flat    = self::normalizeText(implode("\n", $allText));
            preg_match_all('/\{\{\s*(\d+)\s*\}\}/u', $flat, $m2);
            $nums    = array_values(array_unique($m2[1] ?? []));
        }

        sort($nums, SORT_NUMERIC);
        $new = collect($nums)->map(fn ($n) => [
            '_key'            => (string) Str::uuid(), // paksa re-render repeater
            'placeholder'     => '{{' . $n . '}}',
            'index'           => (int) $n,
            'recipient_field' => 'name',
        ])->values()->all();

        $set('variable_bindings', $new);

        Notification::make()
            ->title('Template discan')
            ->body(count($new) . ' placeholder ditemukan.')
            ->success()
            ->send();
    }

    /** Ambil array komponen dari kolom template (body/components/component/content/template/payload/data …). Juga cari secara rekursif. */
    public static function getTemplateComponentsArray(?WhatsAppTemplate $tpl): array
    {
        if (!$tpl) return [];

        $candidates = [
            $tpl->body        ?? null,
            $tpl->components  ?? null,
            $tpl->component   ?? null,
            $tpl->content     ?? null,
            $tpl->template    ?? null,
            $tpl->payload     ?? null,
            $tpl->data        ?? null,
        ];

        foreach ($candidates as $cand) {
            $arr = self::normalizeToArray($cand);
            if (empty($arr)) continue;

            // associative dengan key 'components' / 'component'
            if (is_array($arr) && self::isAssoc($arr)) {
                if (isset($arr['components']) && is_array($arr['components'])) {
                    $list = self::findComponentsRecursively($arr['components']);
                    if (!empty($list)) return $list;
                }
                if (isset($arr['component']) && is_array($arr['component'])) {
                    $list = self::findComponentsRecursively($arr['component']);
                    if (!empty($list)) return $list;
                }
            }

            // jika sudah berupa list komponen
            $list = self::findComponentsRecursively($arr);
            if (!empty($list)) return $list;
        }

        return [];
    }

    protected static function normalizeToArray($cand): array
    {
        if (is_null($cand)) return [];
        if (is_array($cand)) return $cand;
        if ($cand instanceof \Illuminate\Support\Collection) return $cand->toArray();
        if (is_object($cand)) return json_decode(json_encode($cand), true) ?? [];
        if (is_string($cand)) {
            $d = json_decode($cand, true);
            if (is_array($d)) return $d;
        }
        return [];
    }

    protected static function isAssoc(array $a): bool
    {
        if ($a === []) return false;
        return array_keys($a) !== range(0, count($a) - 1);
    }

    protected static function findComponentsRecursively($node): array
    {
        if (is_array($node)) {
            if (self::looksLikeComponentList($node)) {
                return $node;
            }
            foreach ($node as $v) {
                if (is_array($v)) {
                    $found = self::findComponentsRecursively($v);
                    if (!empty($found)) return $found;
                }
            }
        }
        return [];
    }

    protected static function looksLikeComponentList(array $arr): bool
    {
        if ($arr === []) return false;
        $isList = array_keys($arr) === range(0, count($arr) - 1);
        if (!$isList) return false;

        foreach ($arr as $it) {
            if (is_array($it) && array_key_exists('type', $it)) {
                return true;
            }
        }
        return false;
    }

    public static function extractBodyTextFromComponents(array $components): string
    {
        foreach ($components as $c) {
            if (is_array($c) && strtoupper((string) ($c['type'] ?? '')) === 'BODY') {
                return (string) ($c['text'] ?? '');
            }
        }
        return '';
    }

    protected static function collectAllStrings(array $components): array
    {
        $out = [];
        $walker = function ($node) use (&$out, &$walker) {
            if (is_string($node)) { $out[] = $node; return; }
            if (is_array($node))  { foreach ($node as $v) $walker($v); }
        };
        $walker($components);
        return $out;
    }

    protected static function normalizeText(string $s): string
    {
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s);
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $map = [
            '｛' => '{', '｝' => '}', '﹛' => '{', '﹜' => '}',
            '０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4',
            '５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9',
        ];
        return strtr($s, $map);
    }

    protected static function extractNumericPlaceholders(string $s): array
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/u', $s, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    protected static function extractPartsForPreview(array $components): array
    {
        $headerType = null; $headerText = null; $headerImageUrl = null;
        $bodyText = ''; $footerText = null; $tplButtons = [];

        foreach ($components as $c) {
            if (!is_array($c)) continue;

            $type = strtoupper((string) ($c['type'] ?? ''));

            if ($type === 'HEADER') {
                $format = strtoupper((string) ($c['format'] ?? ''));
                if ($format === 'TEXT') {
                    $headerType = 'text';
                    $headerText = (string) ($c['text'] ?? '');
                } elseif ($format === 'IMAGE') {
                    $headerType = 'image';
                    $headerImageUrl = data_get($c, 'example.header_handle.0');
                }
            }

            if ($type === 'BODY') {
                $bodyText = (string) ($c['text'] ?? '');
            }

            if ($type === 'FOOTER') {
                $footerText = (string) ($c['text'] ?? '');
            }

            if ($type === 'BUTTONS') {
                $tplButtons = (array) ($c['buttons'] ?? []);
            }
        }

        return [$headerType, $headerText, $headerImageUrl, $bodyText, $footerText, $tplButtons];
    }

    /** Render text fallback dengan binding numerik ke field recipient */
    public static function renderBodyWithRecipientBindings(string $bodyText, array $bindings, \App\Models\Recipient $recipient): string
    {
        $bodyText = self::normalizeText($bodyText);

        $values = [];
        foreach ($bindings as $b) {
            $idx   = $b['index'] ?? null;
            $field = $b['recipient_field'] ?? null;
            if ($idx === null) continue;

            $val = '';
            if ($field) {
                if ($field === 'group') {
                    $val = $recipient->relationLoaded('groups')
                        ? $recipient->groups->pluck('name')->sort()->values()->join(', ')
                        : $recipient->groups()->pluck('name')->sort()->values()->join(', ');
                } else {
                    $val = (string) (data_get($recipient, $field) ?? '');
                }
            }

            $values[(int) $idx] = $val;
        }

        if (empty($values)) return $bodyText;

        return preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/u', function ($m) use ($values) {
            $i = (int) ($m[1] ?? 0);
            return array_key_exists($i, $values) ? $values[$i] : $m[0];
        }, $bodyText);
    }

    public static function defaultBindingsFromComponents(array $components): array
    {
        $bodyText = self::normalizeText(self::extractBodyTextFromComponents($components));
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/u', $bodyText, $m);
        $nums = array_values(array_unique($m[1] ?? []));

        if (empty($nums)) {
            $allText = self::collectAllStrings($components);
            $flat    = self::normalizeText(implode("\n", $allText));
            preg_match_all('/\{\{\s*(\d+)\s*\}\}/u', $flat, $m2);
            $nums    = array_values(array_unique($m2[1] ?? []));
        }

        sort($nums, SORT_NUMERIC);

        return collect($nums)->map(fn ($n) => [
            'placeholder'     => '{{' . $n . '}}',
            'index'           => (int) $n,
            'recipient_field' => 'name',
        ])->values()->all();
    }

    public static function buildWaBodyComponentsFromBindings(array $bindings, \App\Models\Recipient $recipient): array
    {
        usort($bindings, fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        $params = [];
        foreach ($bindings as $b) {
            $field = $b['recipient_field'] ?? null;
            $val = '';

            if ($field) {
                if ($field === 'group') {
                    $val = $recipient->relationLoaded('groups')
                        ? $recipient->groups->pluck('name')->sort()->values()->join(', ')
                        : ($recipient->groups()->pluck('name')->sort()->values()->join(', '));
                } else {
                    $val = (string) (data_get($recipient, $field) ?? '');
                }
            }

            $params[] = ['type' => 'text', 'text' => $val];
        }

        return [[
            'type' => 'body',
            'parameters' => $params,
        ]];
    }
}
