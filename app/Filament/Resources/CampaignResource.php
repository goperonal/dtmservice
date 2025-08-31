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

            Forms\Components\Select::make('whatsapp_template_name')
                ->label('WhatsApp Template')
                ->options(
                    WhatsAppTemplate::query()
                        ->where('status', 'APPROVED')
                        ->orderBy('name')
                        ->pluck('name', 'name')
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

            Grid::make(12)
                ->visible(fn (Get $get) => filled($get('whatsapp_template_name')))
                ->schema([

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
                                    Forms\Components\Hidden::make('index'),
                                    Forms\Components\Hidden::make('name'),

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
                Tables\Columns\TextColumn::make('template_display')->label('Template')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('broadcast_messages_count')->label('Recipients'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y H:i', 'Asia/Jakarta')->sortable(),
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
            ->with('whatsappTemplateByName')
            ->withCount('broadcastMessages');
    }

    // ================= Helpers =================

    /** Deteksi NAMED atau POSITIONAL dari snapshot template. */
    public static function detectParameterFormatFromComponents(array $components, ?string $hint = null): string
    {
        $allText = self::collectAllStrings($components);
        $flat    = self::normalizeText(implode("\n", $allText));

        $hasNamed   = (bool) preg_match('/\{\{\s*[A-Za-z_][A-Za-z0-9_]*\s*\}\}/u', $flat);
        $hasNumeric = (bool) preg_match('/\{\{\s*\d+\s*\}\}/u', $flat);

        if ($hasNamed && !$hasNumeric) return 'NAMED';
        if ($hasNumeric && !$hasNamed) return 'POSITIONAL';
        if ($hasNamed) return 'NAMED';
        return strtoupper((string) ($hint ?: 'POSITIONAL'));
    }

    /** Scan template → set variable bindings default (NAMED atau POSITIONAL). */
    protected static function scanTemplateAndSetBindingsByName(Set $set, ?string $templateName, string $from = 'unknown'): void
    {
        $set('variable_bindings', []);
        $set('tpl_components', null);
        $set('debug_body', null);
        $set('debug_components_json', null);

        if (blank($templateName)) return;

        $tpl = WhatsAppTemplate::where('name', $templateName)->first();
        if (!$tpl) return;

        $components = self::getTemplateComponentsArray($tpl);
        $set('tpl_components', $components);

        $sample = is_array($components) ? array_slice($components, 0, 2) : $components;
        $set('debug_components_json', json_encode($sample, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

        $rawBody = self::extractBodyTextFromComponents($components);
        $set('debug_body', $rawBody);

        $bodyText = self::normalizeText($rawBody);
        $detectedFormat = self::detectParameterFormatFromComponents($components, $tpl->parameter_format ?? null);

        if ($detectedFormat === 'NAMED') {
            $names = self::extractNamedPlaceholdersOrderedFromComponents($components);
            if (empty($names)) {
                $names = self::extractNamedPlaceholders($bodyText);
            }

            $new = collect($names)->values()->map(fn ($nm, $i) => [
                '_key'            => (string) Str::uuid(),
                'placeholder'     => '{{' . $nm . '}}',
                'name'            => (string) $nm,
                'index'           => $i + 1,
                'recipient_field' => 'name',
            ])->all();
        } else {
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
                '_key'            => (string) Str::uuid(),
                'placeholder'     => '{{' . $n . '}}',
                'index'           => (int) $n,
                'recipient_field' => 'name',
            ])->values()->all();
        }

        $set('variable_bindings', $new);

        Notification::make()
            ->title('Template discan')
            ->body(count($new) . ' placeholder ditemukan. Format: ' . $detectedFormat)
            ->success()
            ->send();
    }

    /** Ambil list komponen dari berbagai bentuk penyimpanan. */
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

    /** Ambil teks BODY dari komponen. */
    public static function extractBodyTextFromComponents(array $components): string
    {
        foreach ($components as $c) {
            if (is_array($c) && strtoupper((string) ($c['type'] ?? '')) === 'BODY') {
                return (string) ($c['text'] ?? '');
            }
        }
        return '';
    }

    /** Kumpulkan semua string dari node untuk keperluan deteksi. */
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

    /** Normalisasi teks (hapus ZW chars, CRLF → LF, dsb.). */
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

    /** Ekstrak daftar placeholder NAMED dari string. */
    protected static function extractNamedPlaceholders(string $s): array
    {
        preg_match_all('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/u', $s, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    /** Urutan nama placeholder NAMED sesuai kemunculan di BODY. */
    protected static function extractNamedPlaceholdersOrderedFromComponents(array $components): array
    {
        $body = self::extractBodyTextFromComponents($components);
        $body = self::normalizeText($body);
        $names = [];
        if (preg_match_all('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/u', $body, $m)) {
            $names = $m[1] ?? [];
        }
        return array_values(array_unique($names));
    }

    /** Untuk preview di panel kanan (header/body/footer/buttons). */
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
                    // preview pakai URL kalau ada, atau handle
                    $headerImageUrl = data_get($c, 'example.header_url.0') ?: data_get($c, 'example.header_handle.0');
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

    /**
     * Render teks BODY dengan binding ke field recipient.
     * - Mendukung POSITIONAL: {{1}}, {{2}}, ...
     * - Mendukung NAMED: {{first_name}}, {{kode}}, ...
     * - Placeholder yang tidak ada nilainya dibiarkan apa adanya.
     */
    public static function renderBodyWithRecipientBindings(string $bodyText, array $bindings, \App\Models\Recipient $recipient): string
    {
        $bodyText = self::normalizeText($bodyText);

        // Kumpulkan nilai dari bindings
        $valuesByIndex = [];
        $valuesByName  = [];

        foreach ($bindings as $b) {
            $idx   = $b['index'] ?? null;
            $pname = $b['name']  ?? null;
            $field = $b['recipient_field'] ?? null;

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

            if ($idx !== null) {
                $valuesByIndex[(int) $idx] = $val;
            }
            if (!empty($pname)) {
                $valuesByName[(string) $pname] = $val;
            }
        }

        // Ganti NAMED terlebih dulu
        if (!empty($valuesByName)) {
            $bodyText = preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/u', function ($m) use ($valuesByName) {
                $key = (string) ($m[1] ?? '');
                return array_key_exists($key, $valuesByName) ? $valuesByName[$key] : $m[0];
            }, $bodyText);
        }

        // Lalu ganti NUMERIC
        if (!empty($valuesByIndex)) {
            $bodyText = preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/u', function ($m) use ($valuesByIndex) {
                $i = (int) ($m[1] ?? 0);
                return array_key_exists($i, $valuesByIndex) ? $valuesByIndex[$i] : $m[0];
            }, $bodyText);
        }

        return $bodyText;
    }

    /**
     * Build BODY components untuk panggilan Template API (NAMED/POSITIONAL).
     */
    public static function buildWaBodyComponentsFromBindings(
        array $bindings,
        \App\Models\Recipient $recipient,
        string $parameterFormat = 'POSITIONAL'
    ): array
    {
        $isNamed = strtoupper($parameterFormat) === 'NAMED';

        if (! $isNamed) {
            usort($bindings, fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
        }

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

            $entry = ['type' => 'text', 'text' => $val];
            if ($isNamed && !empty($b['name'])) {
                $entry['parameter_name'] = (string) $b['name'];
            }
            $params[] = $entry;
        }

        if (empty($params)) {
            return [];
        }

        return [[
            'type' => 'body',
            'parameters' => $params,
        ]];
    }

    /**
     * HEADER builder pakai LINK (tanpa upload).
     * - Support format: IMAGE | VIDEO | DOCUMENT
     * - Sumber URL: $fallbackUrl → example.header_url.0 → example.header_handle.0
     * - URL relatif ("/storage/...") akan dijadikan absolut via APP_URL.
     */
    public static function buildWaHeaderComponentFromSnapshotUsingLink(
        array $components,
        ?string $fallbackUrl = null
    ): ?array
    {
        $header = null;
        foreach ($components as $c) {
            if (is_array($c) && strtoupper((string) ($c['type'] ?? '')) === 'HEADER') {
                $header = $c;
                break;
            }
        }
        if (!$header) return null;

        $format = strtoupper((string) ($header['format'] ?? 'TEXT'));
        if (!in_array($format, ['IMAGE','VIDEO','DOCUMENT'], true)) {
            return null;
        }

        $url = $fallbackUrl
            ?: (data_get($header, 'example.header_url.0') ?: data_get($header, 'example.header_handle.0'));

        if (! $url) {
            throw new \RuntimeException('Template butuh HEADER media, tapi URL tidak ditemukan.');
        }

        $abs = self::toAbsoluteUrl((string) $url);
        $key = strtolower($format); // image|video|document

        return [[
            'type' => 'header',
            'parameters' => [[
                'type' => $key,
                $key   => ['link' => $abs],
            ]],
        ]];
    }

    /** Ubah path relatif jadi URL absolut berdasarkan APP_URL. */
    public static function toAbsoluteUrl(string $maybeUrl): string
    {
        if (preg_match('#^https?://#i', $maybeUrl)) {
            return $maybeUrl;
        }
        $base = rtrim((string) config('app.url'), '/');
        return $base . '/' . ltrim($maybeUrl, '/');
    }
}
