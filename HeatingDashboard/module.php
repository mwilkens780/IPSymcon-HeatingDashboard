<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TileKit.php';

class HeatingDashboard extends IPSModule
{
    use \Fuchsbau\TileKit\HeatingDashboard\TileKit;

    // VitoConnect's own VVC.Mode enum (see de.paresy.viessmann module) — hardcoded
    // here since we don't call the Viessmann API ourselves, just read/write the
    // existing VitoConnect-owned variables.
    private const MODE_LABELS = [
        'standby'       => 'Aus',
        'dhw'           => 'Nur WW',
        'dhwAndHeating' => 'Heizen+WW',
        'forcedReduced' => 'Dauernd red.',
        'forcedNormal'  => 'Dauernd Tag',
    ];

    private const DIAL_RANGES = [
        'hk1_normal'  => [10.0, 28.0, 0.5],
        'hk1_reduced' => [10.0, 25.0, 0.5],
        'hk2_normal'  => [10.0, 28.0, 0.5],
        'hk2_reduced' => [10.0, 25.0, 0.5],
        'dhw_target'  => [10.0, 60.0, 1.0],
    ];

    private const HISTORY_KEEP_SEC = 12 * 3600; // internal cache window
    private const CHART_SPAN_SEC   = 6 * 3600;  // displayed window

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('update_interval', 300);

        // Keine installationsspezifischen Vorbelegungen: alle VitoConnect-
        // Variablen werden im Konfigurationsformular ausgewaehlt (0 = nicht zugeordnet).
        $this->RegisterPropertyInteger('var_outside_temp', 0);
        $this->RegisterPropertyInteger('var_dhw_temp',      0);
        $this->RegisterPropertyInteger('var_hk1_supply',    0);
        $this->RegisterPropertyInteger('var_hk2_supply',    0);
        $this->RegisterPropertyInteger('var_burner_active', 0);
        $this->RegisterPropertyInteger('var_burner_hours',  0);
        // Boiler-level status flags, same class of data as burner_active above
        // (single global value, not reachable from any other configured
        // variable's ident/parent) — need their own reference, unlike the
        // per-circuit name/curve data below which is derived, not configured.
        $this->RegisterPropertyInteger('var_primary_pump',     0);
        $this->RegisterPropertyInteger('var_circulation_pump', 0);

        $this->RegisterPropertyInteger('var_hk1_normal_temp',  0);
        $this->RegisterPropertyInteger('var_hk1_reduced_temp', 0);
        $this->RegisterPropertyInteger('var_hk1_mode',         0);

        $this->RegisterPropertyInteger('var_hk2_normal_temp',  0);
        $this->RegisterPropertyInteger('var_hk2_reduced_temp', 0);
        $this->RegisterPropertyInteger('var_hk2_mode',         0);

        $this->RegisterPropertyInteger('var_dhw_target_temp', 0);

        $this->RegisterPropertyInteger('var_gas_heating_day',   0);
        $this->RegisterPropertyInteger('var_gas_heating_week',  0);
        $this->RegisterPropertyInteger('var_gas_heating_month', 0);
        $this->RegisterPropertyInteger('var_gas_heating_year',  0);

        $this->RegisterPropertyInteger('var_gas_dhw_day',   0);
        $this->RegisterPropertyInteger('var_gas_dhw_week',  0);
        $this->RegisterPropertyInteger('var_gas_dhw_month', 0);
        $this->RegisterPropertyInteger('var_gas_dhw_year',  0);

        $this->RegisterPropertyInteger('var_gas_total_day',   0);
        $this->RegisterPropertyInteger('var_gas_total_week',  0);
        $this->RegisterPropertyInteger('var_gas_total_month', 0);
        $this->RegisterPropertyInteger('var_gas_total_year',  0);

        $this->RegisterPropertyInteger('var_power_total_day',   0);
        $this->RegisterPropertyInteger('var_power_total_week',  0);
        $this->RegisterPropertyInteger('var_power_total_month', 0);
        $this->RegisterPropertyInteger('var_power_total_year',  0);

        // VitoConnect's temperature sensors aren't archived (checked), so this
        // module keeps its own rolling sample cache instead of relying on
        // Archive Control / AC_GetLoggedValues().
        $this->RegisterAttributeString('temp_history', '[]');

        // VitoConnect's own module collapses the Viessmann API's day/week/month
        // arrays down to a single "current period" value (see its ParseData(),
        // which keeps only $property->value[0]) — the previous period is not
        // exposed anywhere. We reconstruct it ourselves by watching for the
        // counter to drop (period rollover) and remembering the last value seen.
        $this->RegisterAttributeString('period_prev', '{}');

        $this->RegisterTimer('UpdateTimer', 0, 'HTD_Refresh($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyInteger('var_outside_temp') === 0) {
            $this->SetStatus(201);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('update_interval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
        $this->SetStatus(102);

        $this->Refresh();
    }

    // ─── HTML-SDK: dashboard tile ──────────────────────────────────────────────

    public function GetVisualizationTile(): string
    {
        return $this->buildDashboardHTML();
    }

    // ─── Public update ────────────────────────────────────────────────────────

    public function Refresh(): void
    {
        try {
            $this->sampleHistory();
            $this->trackPeriods();
            $this->tkPush('__all__', $this->ops($this->view($this->collectData())));
            $this->SetStatus(102);
        } catch (\Throwable $e) {
            $this->LogMessage('HeatingDashboard Refresh: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    // ─── IPS action handler ─────────────────────────────────────────────────────

    /**
     * This module owns none of the underlying variables — VitoConnect does.
     * Writes are forwarded via the global RequestAction($VariableID, $Value),
     * which IPS routes to VitoConnect's own RequestAction() exactly as if the
     * user had actioned the variable directly (same OAuth session, same
     * Viessmann API calls) — no need to duplicate any of that here.
     */
    public function RequestAction($Ident, $Value): void
    {
        $dialTargets = [
            'hk1_normal'  => 'var_hk1_normal_temp',
            'hk1_reduced' => 'var_hk1_reduced_temp',
            'hk2_normal'  => 'var_hk2_normal_temp',
            'hk2_reduced' => 'var_hk2_reduced_temp',
            'dhw_target'  => 'var_dhw_target_temp',
        ];
        $modeTargets = [
            'hk1_mode' => 'var_hk1_mode',
            'hk2_mode' => 'var_hk2_mode',
        ];

        try {
            if (isset($dialTargets[$Ident])) {
                $targetID = $this->ReadPropertyInteger($dialTargets[$Ident]);
                if ($targetID > 0) {
                    RequestAction($targetID, (float) $Value);
                    $this->tkPush($Ident, (float) $Value); // optimistic UI update
                }
            } elseif (isset($modeTargets[$Ident])) {
                $targetID = $this->ReadPropertyInteger($modeTargets[$Ident]);
                if ($targetID > 0) {
                    RequestAction($targetID, (string) $Value);
                    $this->tkPush($Ident, (string) $Value);
                }
            } else {
                $this->LogMessage("HeatingDashboard RequestAction: unknown ident {$Ident}", KL_WARNING);
            }
        } catch (\Throwable $e) {
            $this->LogMessage('HeatingDashboard RequestAction ' . $Ident . ': ' . $e->getMessage(), KL_ERROR);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function readVar(string $prop)
    {
        $id = $this->ReadPropertyInteger($prop);
        if ($id <= 0 || !@IPS_VariableExists($id)) {
            return null;
        }
        return GetValue($id);
    }

    /**
     * VitoConnect creates several sibling variables next to each circuit's own
     * data, all sharing the ident prefix heating_circuits_{N}_ — the name
     * ("Heizkreis N (Name)"), the Heizkennlinie ("Niveau"/"Neigung"), etc. We
     * don't ask for separate config properties for these: we take whichever
     * circuit N the already-configured mode variable belongs to (its own
     * ident is heating_circuits_{N}_operating_modes_active) and resolve
     * siblings directly under the same VitoConnect instance from there.
     */
    private function circuitContext(string $modeProp): ?array
    {
        $modeId = $this->ReadPropertyInteger($modeProp);
        if ($modeId <= 0 || !@IPS_VariableExists($modeId)) {
            return null;
        }
        $ident = @IPS_GetObject($modeId)['ObjectIdent'] ?? '';
        if (!preg_match('/^(heating_circuits_\d+)_/', $ident, $m)) {
            return null;
        }
        $parentId = @IPS_GetParent($modeId);
        if ($parentId <= 0) {
            return null;
        }
        return ['prefix' => $m[1], 'parentId' => $parentId];
    }

    /** Resolves a sibling variable's value by ident suffix within a circuitContext(). */
    private function circuitSibling(array $ctx, string $identSuffix)
    {
        $id = @IPS_GetObjectIDByIdent($ctx['prefix'] . $identSuffix, $ctx['parentId']);
        if (!$id || !@IPS_VariableExists($id)) {
            return null;
        }
        return GetValue($id);
    }

    private function circuitName(string $modeProp): ?string
    {
        $ctx = $this->circuitContext($modeProp);
        if ($ctx === null) {
            return null;
        }
        $val = $this->circuitSibling($ctx, '_name');
        return is_string($val) && $val !== '' ? $val : null;
    }

    /** Heizkennlinie: Niveau (shift) und Neigung (slope), sibling idents heating_circuits_{N}_heating_curve_{shift|slope}. */
    private function circuitCurve(string $modeProp): array
    {
        $ctx = $this->circuitContext($modeProp);
        if ($ctx === null) {
            return ['shift' => null, 'slope' => null];
        }
        $shift = $this->circuitSibling($ctx, '_heating_curve_shift');
        $slope = $this->circuitSibling($ctx, '_heating_curve_slope');
        return [
            'shift' => $shift !== null ? (float) $shift : null,
            'slope' => $slope !== null ? (float) $slope : null,
        ];
    }

    private function fmtNum($v, int $decimals = 0): string
    {
        if ($v === null) {
            return '–';
        }
        return number_format((float) $v, $decimals, ',', '.');
    }

    /** Appends the current temperature readings to the rolling cache and trims old entries. */
    private function sampleHistory(): void
    {
        $hist = json_decode($this->ReadAttributeString('temp_history'), true);
        if (!is_array($hist)) {
            $hist = [];
        }

        $hist[] = [
            'ts'      => time(),
            'outside' => $this->readVar('var_outside_temp'),
            'hk1'     => $this->readVar('var_hk1_supply'),
            'hk2'     => $this->readVar('var_hk2_supply'),
            'dhw'     => $this->readVar('var_dhw_temp'),
        ];

        $cutoff = time() - self::HISTORY_KEEP_SEC;
        $hist   = array_values(array_filter($hist, static fn ($h) => $h['ts'] >= $cutoff));

        $this->WriteAttributeString('temp_history', json_encode($hist));
    }

    /**
     * Detects period rollovers (day/week/month/year counters resetting to a
     * lower value) across all four consumption groups and remembers the last
     * value seen before each rollover as "previous period". Must only be
     * called from Refresh() (timer-driven) — it mutates state, unlike
     * collectData()/prevPeriod(). The year rollover obviously only fires once
     * a year, so "previous year" stays empty until the first Dec 31 -> Jan 1
     * transition after install.
     */
    private function trackPeriods(): void
    {
        $groups = [
            'gasHeating' => ['var_gas_heating_day', 'var_gas_heating_week', 'var_gas_heating_month', 'var_gas_heating_year'],
            'gasDhw'     => ['var_gas_dhw_day', 'var_gas_dhw_week', 'var_gas_dhw_month', 'var_gas_dhw_year'],
            'gasTotal'   => ['var_gas_total_day', 'var_gas_total_week', 'var_gas_total_month', 'var_gas_total_year'],
            'powerTotal' => ['var_power_total_day', 'var_power_total_week', 'var_power_total_month', 'var_power_total_year'],
        ];

        $store = json_decode($this->ReadAttributeString('period_prev'), true);
        if (!is_array($store)) {
            $store = [];
        }

        foreach ($groups as $groupKey => [$dayProp, $weekProp, $monthProp, $yearProp]) {
            foreach (['day' => $dayProp, 'week' => $weekProp, 'month' => $monthProp, 'year' => $yearProp] as $periodKey => $prop) {
                $value = $this->readVar($prop);
                if ($value === null) {
                    continue;
                }
                $value = (float) $value;
                $key   = $groupKey . '_' . $periodKey;
                $entry = $store[$key] ?? ['last' => null, 'prev' => null];
                if ($entry['last'] !== null && $value < $entry['last'] - 0.001) {
                    $entry['prev'] = $entry['last']; // counter dropped -> period rolled over
                }
                $entry['last'] = $value;
                $store[$key]   = $entry;
            }
        }

        $this->WriteAttributeString('period_prev', json_encode($store));
    }

    /** Read-only lookup of a previously-tracked period value (see trackPeriods()). */
    private function prevPeriod(string $groupKey, string $periodKey): ?float
    {
        $store = json_decode($this->ReadAttributeString('period_prev'), true);
        if (!is_array($store)) {
            return null;
        }
        $key = $groupKey . '_' . $periodKey;
        return isset($store[$key]['prev']) ? (float) $store[$key]['prev'] : null;
    }

    /** Extracts one series as [[ts, value], ...] from the rolling cache, dropping nulls, limited to CHART_SPAN_SEC. */
    private function historySeries(string $key): array
    {
        $hist  = json_decode($this->ReadAttributeString('temp_history'), true);
        $cutoff = time() - self::CHART_SPAN_SEC;
        $out   = [];
        if (is_array($hist)) {
            foreach ($hist as $row) {
                if (($row['ts'] ?? 0) < $cutoff || !isset($row[$key]) || $row[$key] === null) {
                    continue;
                }
                $out[] = [(int) $row['ts'], (float) $row[$key]];
            }
        }
        return $out;
    }

    /** SVG polyline points + real min/max for a series, scaled into a 300x60 box. */
    private function chartData(array $series, float $viewW = 300.0, float $viewH = 60.0): array
    {
        if (count($series) < 2) {
            return ['points' => '', 'min' => null, 'max' => null];
        }
        $startTs = time() - self::CHART_SPAN_SEC;
        $values  = array_column($series, 1);
        $realMin = min($values);
        $realMax = max($values);
        $scaleMin = $realMin;
        $scaleMax = $realMax;
        if ($scaleMax - $scaleMin < 1.0) {
            $mid      = ($scaleMax + $scaleMin) / 2;
            $scaleMin = $mid - 0.5;
            $scaleMax = $mid + 0.5;
        }
        $pad = ($scaleMax - $scaleMin) * 0.15;
        $scaleMin -= $pad;
        $scaleMax += $pad;

        $pts = [];
        foreach ($series as [$ts, $val]) {
            $x = max(0, min($viewW, ($ts - $startTs) / self::CHART_SPAN_SEC * $viewW));
            $y = $viewH - (($val - $scaleMin) / ($scaleMax - $scaleMin)) * $viewH;
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        return ['points' => implode(' ', $pts), 'min' => $realMin, 'max' => $realMax];
    }

    /** Thumb (x,y) on the 120x120/r45/center60,60 dial arc for a value in [min,max]. */
    private function dialThumbPos(float $value, float $min, float $max): array
    {
        $frac     = $max > $min ? max(0, min(1, ($value - $min) / ($max - $min))) : 0;
        $angleDeg = -135 + $frac * 270;
        $rad      = deg2rad($angleDeg);
        return [round(60 + 45 * sin($rad), 1), round(60 - 45 * cos($rad), 1)];
    }

    private function collectData(): array
    {
        $outside   = $this->readVar('var_outside_temp');
        $dhw       = $this->readVar('var_dhw_temp');
        $hk1Sup    = $this->readVar('var_hk1_supply');
        $hk2Sup    = $this->readVar('var_hk2_supply');
        $primPump  = $this->readVar('var_primary_pump');
        $circPump  = $this->readVar('var_circulation_pump');
        $hk1Curve  = $this->circuitCurve('var_hk1_mode');
        $hk2Curve  = $this->circuitCurve('var_hk2_mode');

        return [
            'outsideTemp'   => $outside !== null ? (float) $outside : null,
            'dhwTemp'       => $dhw !== null ? (float) $dhw : null,
            'hk1Supply'     => $hk1Sup !== null ? (float) $hk1Sup : null,
            'hk2Supply'     => $hk2Sup !== null ? (float) $hk2Sup : null,
            'burnerActive'  => (bool) $this->readVar('var_burner_active'),
            'burnerHours'   => $this->readVar('var_burner_hours'),
            'primaryPumpActive'     => $primPump !== null ? (bool) $primPump : null,
            'circulationPumpActive' => $circPump !== null ? (bool) $circPump : null,

            'hk1_normal'  => $this->readVar('var_hk1_normal_temp'),
            'hk1_reduced' => $this->readVar('var_hk1_reduced_temp'),
            'hk1_mode'    => $this->readVar('var_hk1_mode'),
            'hk1_name'    => $this->circuitName('var_hk1_mode'),
            'hk1_shift'   => $hk1Curve['shift'],
            'hk1_slope'   => $hk1Curve['slope'],
            'hk2_normal'  => $this->readVar('var_hk2_normal_temp'),
            'hk2_reduced' => $this->readVar('var_hk2_reduced_temp'),
            'hk2_mode'    => $this->readVar('var_hk2_mode'),
            'hk2_name'    => $this->circuitName('var_hk2_mode'),
            'hk2_shift'   => $hk2Curve['shift'],
            'hk2_slope'   => $hk2Curve['slope'],
            'dhw_target'  => $this->readVar('var_dhw_target_temp'),

            'gasHeating' => [
                'day' => $this->readVar('var_gas_heating_day'), 'week' => $this->readVar('var_gas_heating_week'),
                'month' => $this->readVar('var_gas_heating_month'), 'year' => $this->readVar('var_gas_heating_year'),
                'prevDay' => $this->prevPeriod('gasHeating', 'day'), 'prevWeek' => $this->prevPeriod('gasHeating', 'week'),
                'prevMonth' => $this->prevPeriod('gasHeating', 'month'), 'prevYear' => $this->prevPeriod('gasHeating', 'year'),
            ],
            'gasDhw' => [
                'day' => $this->readVar('var_gas_dhw_day'), 'week' => $this->readVar('var_gas_dhw_week'),
                'month' => $this->readVar('var_gas_dhw_month'), 'year' => $this->readVar('var_gas_dhw_year'),
                'prevDay' => $this->prevPeriod('gasDhw', 'day'), 'prevWeek' => $this->prevPeriod('gasDhw', 'week'),
                'prevMonth' => $this->prevPeriod('gasDhw', 'month'), 'prevYear' => $this->prevPeriod('gasDhw', 'year'),
            ],
            'gasTotal' => [
                'day' => $this->readVar('var_gas_total_day'), 'week' => $this->readVar('var_gas_total_week'),
                'month' => $this->readVar('var_gas_total_month'), 'year' => $this->readVar('var_gas_total_year'),
                'prevDay' => $this->prevPeriod('gasTotal', 'day'), 'prevWeek' => $this->prevPeriod('gasTotal', 'week'),
                'prevMonth' => $this->prevPeriod('gasTotal', 'month'), 'prevYear' => $this->prevPeriod('gasTotal', 'year'),
            ],
            'powerTotal' => [
                'day' => $this->readVar('var_power_total_day'), 'week' => $this->readVar('var_power_total_week'),
                'month' => $this->readVar('var_power_total_month'), 'year' => $this->readVar('var_power_total_year'),
                'prevDay' => $this->prevPeriod('powerTotal', 'day'), 'prevWeek' => $this->prevPeriod('powerTotal', 'week'),
                'prevMonth' => $this->prevPeriod('powerTotal', 'month'), 'prevYear' => $this->prevPeriod('powerTotal', 'year'),
            ],

            'histOutside' => $this->historySeries('outside'),
            'histHk1'     => $this->historySeries('hk1'),
            'histHk2'     => $this->historySeries('hk2'),
            'histDhw'     => $this->historySeries('dhw'),

            'updated' => date('d.m. H:i'),
        ];
    }

    // ─── Rendering (TileKit, siehe docs/ui-kachel.md im Workspace) ─────────────
    //
    // view() → Erstrender und Live-Push (ops) aus derselben Quelle; Diagramme
    // werden nur noch in PHP gerendert (frueher doppelt in PHP und JS).

    private function deg($v): string
    {
        return $v === null ? '–' : $this->fmtNum((float) $v, 1) . ' °C';
    }

    private function view(array $d): array
    {
        $onOff = static fn (?bool $on, string $onIcon, string $offIcon, string $onState = 'ok') => $on === null ? null
            : ['text' => $on ? 'An' : 'Aus', 'icon' => $on ? $onIcon : $offIcon, 'state' => $on ? $onState : ''];
        $circuit = function (string $k) use ($d): array {
            $name = is_string($d[$k . '_name']) && $d[$k . '_name'] !== '' ? $d[$k . '_name'] : 'Heizkreis ' . substr($k, 2);
            return ['name' => $name, 'mode' => $d[$k . '_mode'] !== null ? (string) $d[$k . '_mode'] : null,
                'normal' => $d[$k . '_normal'] !== null ? (float) $d[$k . '_normal'] : null,
                'reduced' => $d[$k . '_reduced'] !== null ? (float) $d[$k . '_reduced'] : null,
                'shift' => $d[$k . '_shift'] !== null ? $this->fmtNum($d[$k . '_shift'], 1) : '–',
                'slope' => $d[$k . '_slope'] !== null ? $this->fmtNum($d[$k . '_slope'], 1) : '–'];
        };
        return [
            'updated' => $d['updated'],
            'burner'  => ['text' => $d['burnerActive'] ? 'Brennt' : 'Aus', 'icon' => $d['burnerActive'] ? '🔥' : '💤', 'state' => $d['burnerActive'] ? 'warn' : ''],
            'primPump' => $onOff($d['primaryPumpActive'], '💧', '⏸'),
            'circPump' => $onOff($d['circulationPumpActive'], '🔄', '⏸'),
            'hours'   => $d['burnerHours'] !== null ? $this->fmtNum($d['burnerHours']) . ' h' : '–',
            'temps'   => [
                'outside' => ['Außen', $this->deg($d['outsideTemp']), $this->chartHtml($d['histOutside'])],
                'dhw'     => ['Warmwasser', $this->deg($d['dhwTemp']), $this->chartHtml($d['histDhw'])],
                'hk1'     => ['Vorlauf HK1', $this->deg($d['hk1Supply']), $this->chartHtml($d['histHk1'])],
                'hk2'     => ['Vorlauf HK2', $this->deg($d['hk2Supply']), $this->chartHtml($d['histHk2'])],
            ],
            'hk1'     => $circuit('hk1'),
            'hk2'     => $circuit('hk2'),
            'dhwTarget' => $d['dhw_target'] !== null ? (float) $d['dhw_target'] : null,
            'usage'   => [
                'gasHeating' => ['Gas Heizung', $this->barsHtml($d['gasHeating'], 'm³')],
                'gasDhw'     => ['Gas Warmwasser', $this->barsHtml($d['gasDhw'], 'm³')],
                'gasTotal'   => ['Gas gesamt', $this->barsHtml($d['gasTotal'], 'm³')],
                'powerTotal' => ['Strom gesamt', $this->barsHtml($d['powerTotal'], 'kWh')],
            ],
        ];
    }

    private function ops(array $v): array
    {
        $ops = [];
        foreach (['burner', 'primPump', 'circPump'] as $k) {
            if ($v[$k] !== null) {
                array_push($ops, ['t', $k . '_val', $v[$k]['text']], ['t', $k . '_icon', $v[$k]['icon']], ['s', $k, $v[$k]['state']]);
            }
        }
        $ops[] = ['t', 'hours_val', $v['hours']];
        foreach ($v['temps'] as $k => [, $text, $chart]) {
            array_push($ops, ['t', $k . '_val', $text], ['h', $k . '_chart', $chart]);
        }
        foreach (['hk1', 'hk2'] as $k) {
            $c = $v[$k];
            array_push($ops, ['t', $k . '_title', $c['name']], ['t', $k . '_shift_val', $c['shift']], ['t', $k . '_slope_val', $c['slope']]);
            if ($c['mode'] !== null) {
                $ops[] = ['g', $k . '_mode', $c['mode']];
            }
            foreach (['normal', 'reduced'] as $dk) {
                if ($c[$dk] !== null) {
                    $ops[] = ['d', $k . '_' . $dk, $c[$dk]];
                }
            }
        }
        if ($v['dhwTarget'] !== null) {
            $ops[] = ['d', 'dhw_target', $v['dhwTarget']];
        }
        foreach ($v['usage'] as $k => [, $html]) {
            $ops[] = ['h', $k . '_bars', $html];
        }
        return ['updated' => $v['updated'], 'ops' => $ops];
    }

    /** 6h-Verlauf aus dem eigenen Sample-Cache (VitoConnect archiviert nicht). */
    private function chartHtml(array $series): string
    {
        $c = $this->chartData($series);
        if ($c['points'] === '') {
            return '<span class="tk-hint">Verlauf baut sich auf</span>';
        }
        return "<svg viewBox=\"0 0 300 60\" preserveAspectRatio=\"none\"><polyline points=\"{$c['points']}\" fill=\"none\" stroke=\"#7ec8f0\" stroke-width=\"2\" vector-effect=\"non-scaling-stroke\"/></svg>"
            . '<span class="ch-max">' . $this->fmtNum($c['max'], 1) . '°</span><span class="ch-min">' . $this->fmtNum($c['min'], 1) . '°</span>';
    }

    /** Tag/Woche/Monat als Balken (Vorperiode in Klammern), darunter das Jahr. */
    private function barsHtml(array $vals, string $unit): string
    {
        $f    = fn ($v) => $v === null ? '–' : $this->fmtNum((float) $v, (float) $v < 10 ? 1 : 0);
        $nums = array_filter([$vals['day'], $vals['week'], $vals['month']], static fn ($v) => $v !== null);
        $max  = count($nums) > 0 ? max(max(array_map('floatval', $nums)), 0.001) : 1.0;
        $html = '<div class="bars">';
        foreach (['Tag' => ['day', 'prevDay'], 'Woche' => ['week', 'prevWeek'], 'Monat' => ['month', 'prevMonth']] as $label => [$k, $pk]) {
            $v     = $vals[$k];
            $h     = $v !== null ? max(2, (int) round((float) $v / $max * 56)) : 2;
            $prev  = ($vals[$pk] ?? null) !== null ? '(' . $f($vals[$pk]) . ')' : '';
            $html .= '<div class="bar"><span class="bar-v">' . $f($v) . "</span><span class=\"tk-sub\">{$prev}</span>"
                . "<div class=\"bar-track\"><div class=\"bar-fill\" style=\"height:{$h}px\"></div></div><span class=\"tk-sub\">{$label}</span></div>";
        }
        $prevYear = ($vals['prevYear'] ?? null) !== null ? ' (' . $this->fmtNum((float) $vals['prevYear']) . " {$unit})" : '';
        $year     = $vals['year'] !== null ? $this->fmtNum((float) $vals['year']) . " {$unit}" : '–';
        return $html . "</div><span class=\"tk-sub year\">Jahr: {$year}{$prevYear}</span>";
    }

    private function dial(string $ident, string $label, ?float $value): string
    {
        [$min, $max, $step] = self::DIAL_RANGES[$ident];
        $val       = $value ?? $min;
        [$tx, $ty] = $this->dialThumbPos($val, $min, $max);
        $valStr    = $value !== null ? $this->fmtNum($value, 1) . '°' : '–';
        return "<div class=\"dial\" data-ident=\"{$ident}\" data-min=\"{$min}\" data-max=\"{$max}\" data-step=\"{$step}\" data-value=\"{$val}\">"
            . '<svg class="dial-svg" viewBox="0 0 120 120"><path d="M 28.2 91.8 A 45 45 0 1 1 91.8 91.8" fill="none" stroke="#1e2d40" stroke-width="8" stroke-linecap="round"/>'
            . "<circle class=\"dial-thumb\" cx=\"{$tx}\" cy=\"{$ty}\" r=\"7\" fill=\"#7ec8f0\"/></svg>"
            . "<div class=\"dial-center\"><span class=\"dial-value\">{$valStr}</span><span class=\"tk-sub\">" . self::tkEsc($label) . '</span></div></div>';
    }

    private function circuitTiles(string $k, array $c): string
    {
        $modes = "<div class=\"tk-seg\" id=\"{$k}_mode_seg\">";
        foreach (self::MODE_LABELS as $val => $label) {
            $on     = $c['mode'] === $val ? ' class="tk-on"' : '';
            $modes .= "<button type=\"button\"{$on} data-value=\"{$val}\" onclick=\"pick('{$k}_mode', this)\">" . self::tkEsc($label) . '</button>';
        }
        $modes .= '</div>';
        return "<div class=\"tk-group\" id=\"{$k}_title\">" . self::tkEsc($c['name']) . '</div>'
            . "<div class=\"tk-tile tk-span2 hk\"><span class=\"tk-name\">Betriebsart</span>{$modes}</div>"
            . '<div class="tk-tile tk-span2 dials">' . $this->dial($k . '_normal', 'Normal', $c['normal']) . $this->dial($k . '_reduced', 'Reduziert', $c['reduced']) . '</div>'
            . self::tkValueTile($k . '_shift', 'Heizkurve Niveau', $c['shift'])
            . self::tkValueTile($k . '_slope', 'Heizkurve Neigung', $c['slope']);
    }

    private function buildDashboardHTML(): string
    {
        $v    = $this->view($this->collectData());
        $body = self::tkGroup('Kessel')
            . self::tkStatusTile('burner', 'Brenner', $v['burner']['text'], $v['burner']['icon'], $v['burner']['state'])
            . ($v['primPump'] !== null ? self::tkStatusTile('primPump', 'Primärpumpe', $v['primPump']['text'], $v['primPump']['icon'], $v['primPump']['state']) : '')
            . ($v['circPump'] !== null ? self::tkStatusTile('circPump', 'Zirkulationspumpe', $v['circPump']['text'], $v['circPump']['icon'], $v['circPump']['state']) : '')
            . self::tkValueTile('hours', 'Brennerstunden', $v['hours'])
            . self::tkGroup('Temperaturen (6 h)');
        foreach ($v['temps'] as $k => [$name, $text, $chart]) {
            $body .= "<div class=\"tk-tile tk-span2 chart-tile\" id=\"{$k}_card\"><span class=\"tk-name\">" . self::tkEsc($name) . '</span>'
                . "<span id=\"{$k}_val\" class=\"tk-value\">" . self::tkEsc($text) . "</span><div class=\"chart\" id=\"{$k}_chart\">{$chart}</div></div>";
        }
        $body .= $this->circuitTiles('hk1', $v['hk1']) . $this->circuitTiles('hk2', $v['hk2'])
            . self::tkGroup('Warmwasser')
            . '<div class="tk-tile dials">' . $this->dial('dhw_target', 'Soll', $v['dhwTarget']) . '</div>'
            . self::tkGroup('Verbrauch');
        foreach ($v['usage'] as $k => [$name, $html]) {
            $body .= '<div class="tk-tile tk-span2 bars-tile"><span class="tk-name">' . self::tkEsc($name) . "</span><div id=\"{$k}_bars\">{$html}</div></div>";
        }

        $css = <<<'CSS'
.chart-tile,.hk,.bars-tile{justify-content:flex-start}
.chart{position:relative;margin-top:2px;min-height:40px}
.chart svg{width:100%;height:40px;display:block}
.ch-max,.ch-min{position:absolute;right:2px;font-size:var(--tk-fs-meta);color:var(--tk-accent);background:rgba(13,27,42,.75);padding:0 3px;border-radius:3px}
.ch-max{top:0}.ch-min{bottom:0}
.dials{flex-direction:row;justify-content:space-around;align-items:center}
.dial{position:relative;width:96px;height:96px;flex:none;touch-action:none}
.dial-svg{width:96px;height:96px;display:block;cursor:pointer}
.dial-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none}
.dial-value{font-size:16px;font-weight:700}
.bars{display:flex;justify-content:space-around;align-items:flex-end;margin-top:4px}
.bar{display:flex;flex-direction:column;align-items:center;gap:1px}
.bar-v{font-size:var(--tk-fs-label);font-weight:700}
.bar-track{width:22px;height:56px;display:flex;align-items:flex-end}
.bar-fill{width:100%;background:linear-gradient(180deg,#7ec8f0,#3a8abf);border-radius:3px 3px 0 0;transition:height .4s}
.year{text-align:right}
CSS;
        $js = <<<'JS'
function pick(ident, btn) {
  Array.prototype.forEach.call(btn.parentElement.children, function (b) { b.classList.toggle('tk-on', b === btn); });
  requestAction(ident, btn.getAttribute('data-value'));
}
function seg(ident, value) {
  var g = tk.el(ident + '_seg');
  if (g) Array.prototype.forEach.call(g.children, function (b) { b.classList.toggle('tk-on', b.getAttribute('data-value') === String(value)); });
}
// Soll-Drehregler: Ziehen setzt, Loslassen schreibt
var dials = {};
document.querySelectorAll('.dial').forEach(function (root) {
  var svg = root.querySelector('.dial-svg'), thumb = root.querySelector('.dial-thumb'), val = root.querySelector('.dial-value');
  var min = +root.dataset.min, max = +root.dataset.max, step = +root.dataset.step, cur = +root.dataset.value, drag = false, ident = root.dataset.ident;
  function show(v) {
    var f = max > min ? Math.max(0, Math.min(1, (v - min) / (max - min))) : 0, r = (-135 + f * 270) * Math.PI / 180;
    thumb.setAttribute('cx', (60 + 45 * Math.sin(r)).toFixed(1)); thumb.setAttribute('cy', (60 - 45 * Math.cos(r)).toFixed(1));
    val.textContent = v.toFixed(1).replace('.', ',') + '°';
  }
  function at(e) {
    var pt = svg.createSVGPoint(); pt.x = e.clientX; pt.y = e.clientY; var m = svg.getScreenCTM(); if (!m) return;
    var p = pt.matrixTransform(m.inverse()), a = Math.max(-135, Math.min(135, Math.atan2(p.x - 60, 60 - p.y) * 180 / Math.PI));
    cur = Math.round((min + (a + 135) / 270 * (max - min)) / step) * step; show(cur);
  }
  svg.addEventListener('pointerdown', function (e) { drag = true; svg.setPointerCapture(e.pointerId); at(e); });
  svg.addEventListener('pointermove', function (e) { if (drag) at(e); });
  function end() { if (drag) { drag = false; requestAction(ident, cur); } }
  svg.addEventListener('pointerup', end); svg.addEventListener('pointercancel', end);
  dials[ident] = { show: show, busy: function () { return drag; } };
  tk.on(ident, function (v) { if (!drag) show(+v); }); // optimistische Bestaetigung aus RequestAction
});
['hk1_mode', 'hk2_mode'].forEach(function (id) { tk.on(id, function (v) { seg(id, v); }); });

var STATES = ['ok', 'warn', 'alarm'], lastHtml = {};
tk.on('__all__', function (p) {
  p.ops.forEach(function (o) {
    var k = o[0], id = o[1], v = o[2];
    if (k === 't') tk.setText(id, v);
    else if (k === 'h') { if (lastHtml[id] !== v) { lastHtml[id] = v; tk.setHtml(id, v); } }
    else if (k === 'g') seg(id, v);
    else if (k === 'd') { var d = dials[id]; if (d && !d.busy()) d.show(+v); }
    else if (k === 's') {
      var card = tk.el(id + '_card'), st = tk.el(id + '_status');
      if (card) { card.classList.toggle('tk-is-alarm', v === 'alarm'); card.classList.toggle('tk-is-warn', v === 'warn'); }
      if (st) STATES.forEach(function (s) { st.classList.toggle('tk-' + s, s === v); });
    }
  });
});
JS;
        return $this->tkPage('Heizung', '🔥', $v['updated'], "<div class=\"tk-grid\">{$body}</div>", $css, $js, 'null');
    }
}
