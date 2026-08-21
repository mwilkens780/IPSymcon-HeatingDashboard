<?php

declare(strict_types=1);

class HeatingDashboard extends IPSModule
{
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

        // Defaults pre-filled with this installation's actual VitoConnect
        // object IDs so the module works out of the box.
        $this->RegisterPropertyInteger('var_outside_temp', 15558);
        $this->RegisterPropertyInteger('var_dhw_temp',      22752);
        $this->RegisterPropertyInteger('var_hk1_supply',    22838);
        $this->RegisterPropertyInteger('var_hk2_supply',    20540);
        $this->RegisterPropertyInteger('var_burner_active', 26460);
        $this->RegisterPropertyInteger('var_burner_hours',  26950);

        $this->RegisterPropertyInteger('var_hk1_normal_temp',  27514);
        $this->RegisterPropertyInteger('var_hk1_reduced_temp', 45743);
        $this->RegisterPropertyInteger('var_hk1_mode',         59120);

        $this->RegisterPropertyInteger('var_hk2_normal_temp',  31544);
        $this->RegisterPropertyInteger('var_hk2_reduced_temp', 27674);
        $this->RegisterPropertyInteger('var_hk2_mode',         29671);

        $this->RegisterPropertyInteger('var_dhw_target_temp', 42574);

        $this->RegisterPropertyInteger('var_gas_heating_day',   22721);
        $this->RegisterPropertyInteger('var_gas_heating_week',  44274);
        $this->RegisterPropertyInteger('var_gas_heating_month', 55779);
        $this->RegisterPropertyInteger('var_gas_heating_year',  33776);

        $this->RegisterPropertyInteger('var_gas_dhw_day',   26190);
        $this->RegisterPropertyInteger('var_gas_dhw_week',  20056);
        $this->RegisterPropertyInteger('var_gas_dhw_month', 47496);
        $this->RegisterPropertyInteger('var_gas_dhw_year',  49979);

        $this->RegisterPropertyInteger('var_gas_total_day',   33436);
        $this->RegisterPropertyInteger('var_gas_total_week',  15240);
        $this->RegisterPropertyInteger('var_gas_total_month', 48368);
        $this->RegisterPropertyInteger('var_gas_total_year',  53846);

        $this->RegisterPropertyInteger('var_power_total_day',   47704);
        $this->RegisterPropertyInteger('var_power_total_week',  34473);
        $this->RegisterPropertyInteger('var_power_total_month', 26539);
        $this->RegisterPropertyInteger('var_power_total_year',  57870);

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
            $data = $this->collectData();
            $this->pushValue('__all__', $data);
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
                    $this->pushValue($Ident, (float) $Value); // optimistic UI update
                }
            } elseif (isset($modeTargets[$Ident])) {
                $targetID = $this->ReadPropertyInteger($modeTargets[$Ident]);
                if ($targetID > 0) {
                    RequestAction($targetID, (string) $Value);
                    $this->pushValue($Ident, (string) $Value);
                }
            } else {
                $this->LogMessage("HeatingDashboard RequestAction: unknown ident {$Ident}", KL_WARNING);
            }
        } catch (\Throwable $e) {
            $this->LogMessage('HeatingDashboard RequestAction ' . $Ident . ': ' . $e->getMessage(), KL_ERROR);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function pushValue(string $key, $value): void
    {
        $this->UpdateVisualizationValue(json_encode(['key' => $key, 'value' => $value]));
    }

    private function readVar(string $prop)
    {
        $id = $this->ReadPropertyInteger($prop);
        if ($id <= 0 || !@IPS_VariableExists($id)) {
            return null;
        }
        return GetValue($id);
    }

    /**
     * VitoConnect creates a sibling string variable "Heizkreis N (Name)"
     * (ident heating_circuits_{N}_name) next to every circuit's own data —
     * the name the user set in the Viessmann app. We don't ask for a separate
     * config property for it: we take whichever circuit N the already-
     * configured mode variable belongs to (its ident is
     * heating_circuits_{N}_operating_modes_active) and look up that sibling
     * directly under the same VitoConnect instance.
     */
    private function circuitName(string $modeProp): ?string
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
        $nameId = @IPS_GetObjectIDByIdent($m[1] . '_name', $parentId);
        if (!$nameId || !@IPS_VariableExists($nameId)) {
            return null;
        }
        $val = GetValue($nameId);
        return is_string($val) && $val !== '' ? $val : null;
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
        $outside = $this->readVar('var_outside_temp');
        $dhw     = $this->readVar('var_dhw_temp');
        $hk1Sup  = $this->readVar('var_hk1_supply');
        $hk2Sup  = $this->readVar('var_hk2_supply');

        return [
            'outsideTemp'   => $outside !== null ? (float) $outside : null,
            'dhwTemp'       => $dhw !== null ? (float) $dhw : null,
            'hk1Supply'     => $hk1Sup !== null ? (float) $hk1Sup : null,
            'hk2Supply'     => $hk2Sup !== null ? (float) $hk2Sup : null,
            'burnerActive'  => (bool) $this->readVar('var_burner_active'),
            'burnerHours'   => $this->readVar('var_burner_hours'),

            'hk1_normal'  => $this->readVar('var_hk1_normal_temp'),
            'hk1_reduced' => $this->readVar('var_hk1_reduced_temp'),
            'hk1_mode'    => $this->readVar('var_hk1_mode'),
            'hk1_name'    => $this->circuitName('var_hk1_mode'),
            'hk2_normal'  => $this->readVar('var_hk2_normal_temp'),
            'hk2_reduced' => $this->readVar('var_hk2_reduced_temp'),
            'hk2_mode'    => $this->readVar('var_hk2_mode'),
            'hk2_name'    => $this->circuitName('var_hk2_mode'),
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

    // ─── Rendering ──────────────────────────────────────────────────────────────

    private function renderStatTile(string $id, string $label, string $value): string
    {
        return "<div class='cur-tile'><span class='cur-label'>{$label}</span><span id=\"{$id}\" class='cur-value'>{$value}</span></div>";
    }

    private function renderMiniChart(string $id, string $label, array $series): string
    {
        $chart    = $this->chartData($series);
        $maxStr   = $chart['max'] !== null ? $this->fmtNum($chart['max'], 1) . '°' : '';
        $minStr   = $chart['min'] !== null ? $this->fmtNum($chart['min'], 1) . '°' : '';
        return "<div class=\"chart-wrap\"><div class=\"chart-label\">{$label}</div>"
            . '<div class="chart-inner">'
            . "<svg id=\"{$id}_svg\" viewBox=\"0 0 300 60\" preserveAspectRatio=\"none\">"
            . "<polyline id=\"{$id}_poly\" points=\"{$chart['points']}\" fill=\"none\" stroke=\"#7ec8f0\" stroke-width=\"2\"/>"
            . '</svg>'
            . "<span id=\"{$id}_max\" class=\"chart-max\">{$maxStr}</span>"
            . "<span id=\"{$id}_min\" class=\"chart-min\">{$minStr}</span>"
            . '</div></div>';
    }

    private function renderModeButtons(string $ident, ?string $current): string
    {
        $html = "<div class=\"mode-row\" data-ident=\"{$ident}\">";
        foreach (self::MODE_LABELS as $val => $label) {
            $activeCls = $current === $val ? ' mode-active' : '';
            $valEsc    = htmlspecialchars($val, ENT_QUOTES);
            $labelEsc  = htmlspecialchars($label, ENT_QUOTES);
            $html .= "<button type=\"button\" class=\"mode-btn{$activeCls}\" data-value=\"{$valEsc}\" "
                . "onclick=\"requestAction('{$ident}', '{$valEsc}')\">{$labelEsc}</button>";
        }
        return $html . '</div>';
    }

    private function renderDial(string $ident, string $label, ?float $value): string
    {
        [$min, $max, $step] = self::DIAL_RANGES[$ident];
        $val    = $value ?? $min;
        [$tx, $ty] = $this->dialThumbPos($val, $min, $max);
        $valStr = $value !== null ? $this->fmtNum($value, 1) . '°' : '–';
        $labelEsc = htmlspecialchars($label, ENT_QUOTES);

        return "<div class=\"dial\" data-ident=\"{$ident}\" data-min=\"{$min}\" data-max=\"{$max}\" data-step=\"{$step}\" data-value=\"{$val}\">"
            . '<svg class="dial-svg" viewBox="0 0 120 120">'
            . '<path class="dial-track" d="M 28.2 91.8 A 45 45 0 1 1 91.8 91.8" fill="none" stroke="#1e2d40" stroke-width="8" stroke-linecap="round"/>'
            . "<circle class=\"dial-thumb\" cx=\"{$tx}\" cy=\"{$ty}\" r=\"7\" fill=\"#7ec8f0\"/>"
            . '</svg>'
            . "<div class=\"dial-value\">{$valStr}</div>"
            . "<div class=\"dial-label\">{$labelEsc}</div>"
            . '</div>';
    }

    private function renderBarChart(string $label, string $unit, array $vals): string
    {
        $day = $vals['day'] !== null ? (float) $vals['day'] : null;
        $week = $vals['week'] !== null ? (float) $vals['week'] : null;
        $month = $vals['month'] !== null ? (float) $vals['month'] : null;
        $year = $vals['year'] !== null ? (float) $vals['year'] : null;

        $localMax = max(array_filter([$day, $week, $month], static fn ($v) => $v !== null)) ?: 1.0;

        $prevMap = ['Tag' => $vals['prevDay'] ?? null, 'Woche' => $vals['prevWeek'] ?? null, 'Monat' => $vals['prevMonth'] ?? null];

        $bars = '';
        foreach (['Tag' => $day, 'Woche' => $week, 'Monat' => $month] as $xlabel => $v) {
            $h       = $v !== null ? max(2, (int) round(($v / $localMax) * 60)) : 2;
            $vStr    = $v !== null ? $this->fmtNum($v, $v < 10 ? 1 : 0) : '–';
            $prevVal = $prevMap[$xlabel] !== null ? (float) $prevMap[$xlabel] : null;
            $prevStr = $prevVal !== null ? '(' . $this->fmtNum($prevVal, $prevVal < 10 ? 1 : 0) . ')' : '';
            $bars .= "<div class='bar-col'><div class='bar-value'>{$vStr}</div><div class='bar-prev'>{$prevStr}</div>"
                . "<div class='bar-track'><div class='bar-fill-v' style='height:{$h}px'></div></div>"
                . "<div class='bar-xlabel'>{$xlabel}</div></div>";
        }

        $prevYear    = $vals['prevYear'] ?? null;
        $prevYearStr = $prevYear !== null ? ' (' . $this->fmtNum((float) $prevYear) . ' ' . $unit . ')' : '';
        $yearStr     = $year !== null ? $this->fmtNum($year) . ' ' . $unit : '–';
        $labelEsc    = htmlspecialchars($label, ENT_QUOTES);
        return "<div class=\"chart-card\"><div class=\"chart-card-label\">{$labelEsc}</div>"
            . "<div class=\"bar-row\">{$bars}</div>"
            . "<div class=\"chart-card-year\">Jahr: {$yearStr}{$prevYearStr}</div></div>";
    }

    private function buildDashboardHTML(): string
    {
        $d = $this->collectData();

        $outsideStr = $d['outsideTemp'] !== null ? $this->fmtNum($d['outsideTemp'], 1) . '°C' : '–';
        $dhwStr     = $d['dhwTemp'] !== null ? $this->fmtNum($d['dhwTemp'], 1) . '°C' : '–';
        $hk1Str     = $d['hk1Supply'] !== null ? $this->fmtNum($d['hk1Supply'], 1) . '°C' : '–';
        $hk2Str     = $d['hk2Supply'] !== null ? $this->fmtNum($d['hk2Supply'], 1) . '°C' : '–';
        $hoursStr   = $d['burnerHours'] !== null ? $this->fmtNum($d['burnerHours']) . ' h' : '–';

        $burnerCls  = $d['burnerActive'] ? 'badge-warn' : 'badge-off';
        $burnerText = $d['burnerActive'] ? '🔥 Brenner an' : '🔥 Brenner aus';

        $hk1Title = htmlspecialchars(is_string($d['hk1_name']) && $d['hk1_name'] !== '' ? $d['hk1_name'] : 'Heizkreis 1', ENT_QUOTES);
        $hk2Title = htmlspecialchars(is_string($d['hk2_name']) && $d['hk2_name'] !== '' ? $d['hk2_name'] : 'Heizkreis 2', ENT_QUOTES);

        $statTiles = $this->renderStatTile('cur_outside', 'Außen', $outsideStr)
            . $this->renderStatTile('cur_dhw', 'Warmwasser', $dhwStr)
            . $this->renderStatTile('cur_hk1', 'HK1 Vorlauf', $hk1Str)
            . $this->renderStatTile('cur_hk2', 'HK2 Vorlauf', $hk2Str)
            . $this->renderStatTile('cur_hours', 'Brennerstunden', $hoursStr);

        $charts = $this->renderMiniChart('chart_outside', 'Außentemperatur 6h', $this->historySeries('outside'))
            . $this->renderMiniChart('chart_hk1', 'HK1 Vorlauf 6h', $this->historySeries('hk1'))
            . $this->renderMiniChart('chart_hk2', 'HK2 Vorlauf 6h', $this->historySeries('hk2'))
            . $this->renderMiniChart('chart_dhw', 'Warmwasser 6h', $this->historySeries('dhw'));

        $hk1Modes = $this->renderModeButtons('hk1_mode', $d['hk1_mode']);
        $hk2Modes = $this->renderModeButtons('hk2_mode', $d['hk2_mode']);

        $hk1Dials = $this->renderDial('hk1_normal', 'HK1 Normal', $d['hk1_normal'] !== null ? (float) $d['hk1_normal'] : null)
            . $this->renderDial('hk1_reduced', 'HK1 Reduziert', $d['hk1_reduced'] !== null ? (float) $d['hk1_reduced'] : null);
        $hk2Dials = $this->renderDial('hk2_normal', 'HK2 Normal', $d['hk2_normal'] !== null ? (float) $d['hk2_normal'] : null)
            . $this->renderDial('hk2_reduced', 'HK2 Reduziert', $d['hk2_reduced'] !== null ? (float) $d['hk2_reduced'] : null);
        $dhwDial  = $this->renderDial('dhw_target', 'Warmwasser', $d['dhw_target'] !== null ? (float) $d['dhw_target'] : null);

        $consumption = $this->renderBarChart('Gas Heizung', 'm³', $d['gasHeating'])
            . $this->renderBarChart('Gas Warmwasser', 'm³', $d['gasDhw'])
            . $this->renderBarChart('Gas Gesamt', 'm³', $d['gasTotal'])
            . $this->renderBarChart('Strom Gesamt', 'kWh', $d['powerTotal']);

        $updatedEsc = htmlspecialchars($d['updated'], ENT_QUOTES);
        $initJson   = json_encode($d);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
html{height:100%}
*{box-sizing:border-box;margin:0;padding:0}
body{overflow-y:auto;overflow-x:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;background:#0d1b2a;color:#d0e8ff;display:flex;flex-direction:column;padding:10px;gap:10px}
.header{display:flex;justify-content:space-between;align-items:center;gap:6px;font-size:14px;font-weight:600;border-bottom:1px solid #1e3a5f;padding-bottom:6px;flex:none}
.updated{font-size:10px;color:#3a5a7a;font-weight:400}
.badge{padding:3px 8px;border-radius:12px;font-size:12px;border:1px solid transparent;white-space:nowrap}
.badge-off{background:#1a2535;border-color:#2a3a50;color:#4a6a8a}
.badge-warn{background:#4a2010;border-color:#8a4020;color:#f08060}
.section-label{font-size:11px;font-weight:700;color:#8aa8c8;text-transform:uppercase;letter-spacing:.04em;flex:none;border-bottom:1px solid #1e3a5f;padding-bottom:3px}
.current-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;flex:none}
.cur-tile{display:flex;flex-direction:column;gap:1px;background:#131f33;border-radius:8px;padding:6px 8px}
.cur-label{font-size:10px;color:#4a6a8a;text-transform:uppercase;letter-spacing:.03em}
.cur-value{font-size:15px;font-weight:700;color:#d0e8ff}
.charts-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;flex:none}
.chart-wrap{flex:none;display:flex;flex-direction:column;gap:2px}
.chart-label{font-size:9px;color:#4a6a8a;text-transform:uppercase;letter-spacing:.02em}
.chart-inner{position:relative}
.chart-inner svg{width:100%;height:40px;background:#131f33;border-radius:6px;display:block}
.chart-max,.chart-min{position:absolute;right:3px;font-size:8px;color:#7ec8f0;background:rgba(13,27,42,.7);padding:0 3px;border-radius:3px}
.chart-max{top:2px}
.chart-min{bottom:2px}
.hk-block{display:flex;flex-direction:column;gap:6px;flex:none;background:#0f1c30;border-radius:10px;padding:8px}
.hk-title{font-size:12px;font-weight:700;color:#d0e8ff}
.mode-row{display:flex;gap:4px;flex-wrap:wrap}
.mode-btn{flex:1;min-width:60px;background:#1a2535;border:1px solid #2a3a50;color:#8aa8c8;border-radius:6px;font-size:10px;padding:5px 2px;cursor:pointer}
.mode-btn.mode-active{background:#1e4a6e;border-color:#3a8abf;color:#7ec8f0;font-weight:700}
.dial-row{display:flex;gap:12px;justify-content:center}
.dial{display:flex;flex-direction:column;align-items:center;gap:2px;width:100px;touch-action:none}
.dial-svg{width:80px;height:80px;cursor:pointer}
.dial-value{font-size:15px;font-weight:700;margin-top:-48px;pointer-events:none}
.dial-label{font-size:10px;color:#8aa8c8;margin-top:30px}
.chart-card{background:#131f33;border-radius:8px;padding:6px 8px;display:flex;flex-direction:column;gap:4px}
.chart-card-label{font-size:11px;font-weight:700;color:#8aa8c8}
.bar-row{display:flex;justify-content:space-around;align-items:flex-end;height:80px}
.bar-col{display:flex;flex-direction:column;align-items:center;gap:2px}
.bar-value{font-size:10px;font-weight:700;color:#d0e8ff}
.bar-prev{font-size:8px;font-weight:400;color:#4a6a8a;min-height:10px}
.bar-track{width:22px;height:60px;display:flex;align-items:flex-end}
.bar-fill-v{width:100%;background:linear-gradient(180deg,#7ec8f0,#3a8abf);border-radius:3px 3px 0 0;transition:height .4s}
.bar-xlabel{font-size:9px;color:#4a6a8a}
.chart-card-year{font-size:10px;color:#4a6a8a;text-align:right}
.consumption-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;flex:none}
</style>
</head>
<body>
<div class="header">
  <span>🔥 Heizung <span id="updated" class="updated">Stand {$updatedEsc}</span></span>
  <span id="burner_badge" class="badge {$burnerCls}">{$burnerText}</span>
</div>

<div class="hk-block">
  <div id="hk1_title" class="hk-title">{$hk1Title}</div>
  {$hk1Modes}
  <div class="dial-row">{$hk1Dials}</div>
</div>

<div class="current-grid">{$statTiles}</div>

<div class="charts-grid">{$charts}</div>

<div class="hk-block">
  <div id="hk2_title" class="hk-title">{$hk2Title}</div>
  {$hk2Modes}
  <div class="dial-row">{$hk2Dials}</div>
</div>

<div class="hk-block">
  <div class="hk-title">Warmwasser</div>
  <div class="dial-row">{$dhwDial}</div>
</div>

<div class="section-label">Verbrauch</div>
<div class="consumption-grid">{$consumption}</div>

<script>
// WebFront injects its own body{margin-top:...;margin-bottom:...} (reserved
// space for the tile's title/expand-icon overlay). Measure it and size body
// to exactly fill what's left instead of guessing a fixed pixel value.
(function() {
  var cs = getComputedStyle(document.body);
  var vExtra = (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);
  document.body.style.height = 'calc(100% - ' + vExtra + 'px)';
})();

var state = {$initJson};
var dialRoots = {};

function setText(id, text) {
  var el = document.getElementById(id);
  if (el) el.textContent = text;
}

function renderMiniChart(prefix, series) {
  var poly = document.getElementById(prefix + '_poly');
  if (!poly || !series || series.length < 2) return;
  var spanSec = 6 * 3600;
  var startTs = Math.floor(Date.now() / 1000) - spanSec;
  var vals = series.map(function(p) { return p[1]; });
  var realMin = Math.min.apply(null, vals), realMax = Math.max.apply(null, vals);
  var min = realMin, max = realMax;
  if (max - min < 1.0) { var mid = (max + min) / 2; min = mid - 0.5; max = mid + 0.5; }
  var pad = (max - min) * 0.15; min -= pad; max += pad;
  var w = 300, h = 60;
  var pts = series.map(function(p) {
    var x = Math.max(0, Math.min(w, (p[0] - startTs) / spanSec * w));
    var y = h - ((p[1] - min) / (max - min)) * h;
    return x.toFixed(1) + ',' + y.toFixed(1);
  }).join(' ');
  poly.setAttribute('points', pts);
  setText(prefix + '_max', realMax.toFixed(1).replace('.', ',') + '°');
  setText(prefix + '_min', realMin.toFixed(1).replace('.', ',') + '°');
}

function valueToThumb(value, min, max) {
  var frac = max > min ? Math.max(0, Math.min(1, (value - min) / (max - min))) : 0;
  var angleDeg = -135 + frac * 270;
  var rad = angleDeg * Math.PI / 180;
  return { x: 60 + 45 * Math.sin(rad), y: 60 - 45 * Math.cos(rad) };
}

function initDial(root) {
  var svg = root.querySelector('.dial-svg');
  var thumb = root.querySelector('.dial-thumb');
  var valueEl = root.querySelector('.dial-value');
  var min = parseFloat(root.dataset.min);
  var max = parseFloat(root.dataset.max);
  var step = parseFloat(root.dataset.step);
  var ident = root.dataset.ident;
  var dragging = false;
  var currentValue = parseFloat(root.dataset.value);

  function svgPoint(clientX, clientY) {
    var pt = svg.createSVGPoint();
    pt.x = clientX; pt.y = clientY;
    var ctm = svg.getScreenCTM();
    if (!ctm) return { x: 60, y: 60 };
    return pt.matrixTransform(ctm.inverse());
  }

  function updateVisual(value) {
    var pos = valueToThumb(value, min, max);
    thumb.setAttribute('cx', pos.x.toFixed(1));
    thumb.setAttribute('cy', pos.y.toFixed(1));
    if (valueEl) valueEl.textContent = value.toFixed(1).replace('.', ',') + '°';
  }

  function setFromClient(clientX, clientY) {
    var p = svgPoint(clientX, clientY);
    var dx = p.x - 60, dy = p.y - 60;
    var angleDeg = Math.atan2(dx, -dy) * 180 / Math.PI;
    angleDeg = Math.max(-135, Math.min(135, angleDeg));
    var frac = (angleDeg + 135) / 270;
    var value = min + frac * (max - min);
    value = Math.round(value / step) * step;
    currentValue = value;
    updateVisual(value);
  }

  svg.addEventListener('pointerdown', function(e) {
    dragging = true;
    svg.setPointerCapture(e.pointerId);
    setFromClient(e.clientX, e.clientY);
  });
  svg.addEventListener('pointermove', function(e) {
    if (dragging) setFromClient(e.clientX, e.clientY);
  });
  function endDrag() {
    if (!dragging) return;
    dragging = false;
    requestAction(ident, currentValue);
  }
  svg.addEventListener('pointerup', endDrag);
  svg.addEventListener('pointercancel', endDrag);

  root._updateVisual = updateVisual;
  dialRoots[ident] = root;
}
document.querySelectorAll('.dial').forEach(initDial);

function updateModeButtons(ident, value) {
  var row = document.querySelector('.mode-row[data-ident="' + ident + '"]');
  if (!row) return;
  row.querySelectorAll('.mode-btn').forEach(function(btn) {
    btn.classList.toggle('mode-active', btn.dataset.value === value);
  });
}

window.handleMessage = function(raw) {
  var msg = JSON.parse(raw);
  var key = msg.key, val = msg.value;

  if (key === '__all__') {
    state = val;
    setText('updated', 'Stand ' + val.updated);
    setText('cur_outside', val.outsideTemp == null ? '–' : val.outsideTemp.toFixed(1).replace('.', ',') + '°C');
    setText('cur_dhw', val.dhwTemp == null ? '–' : val.dhwTemp.toFixed(1).replace('.', ',') + '°C');
    setText('cur_hk1', val.hk1Supply == null ? '–' : val.hk1Supply.toFixed(1).replace('.', ',') + '°C');
    setText('cur_hk2', val.hk2Supply == null ? '–' : val.hk2Supply.toFixed(1).replace('.', ',') + '°C');
    setText('cur_hours', val.burnerHours == null ? '–' : Math.round(val.burnerHours) + ' h');

    var badge = document.getElementById('burner_badge');
    if (badge) {
      badge.className = 'badge ' + (val.burnerActive ? 'badge-warn' : 'badge-off');
      badge.textContent = val.burnerActive ? '🔥 Brenner an' : '🔥 Brenner aus';
    }

    setText('hk1_title', val.hk1_name ? val.hk1_name : 'Heizkreis 1');
    setText('hk2_title', val.hk2_name ? val.hk2_name : 'Heizkreis 2');

    renderMiniChart('chart_outside', val.histOutside);
    renderMiniChart('chart_hk1', val.histHk1);
    renderMiniChart('chart_hk2', val.histHk2);
    renderMiniChart('chart_dhw', val.histDhw);

    updateModeButtons('hk1_mode', val.hk1_mode);
    updateModeButtons('hk2_mode', val.hk2_mode);

    ['hk1_normal', 'hk1_reduced', 'hk2_normal', 'hk2_reduced', 'dhw_target'].forEach(function(id) {
      if (val[id] != null && dialRoots[id]) dialRoots[id]._updateVisual(val[id]);
    });
  } else if (dialRoots[key]) {
    dialRoots[key]._updateVisual(val);
  } else if (key === 'hk1_mode' || key === 'hk2_mode') {
    updateModeButtons(key, val);
  }
};
</script>
</body>
</html>
HTML;
    }
}
