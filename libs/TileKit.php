<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
//  TileKit – gemeinsame Kachel-Basis aller Fuchsbau-IPS-Dashboards.
//
//  MASTER-KOPIE: ~/src/ips/shared/TileKit.php (Repo IPSymcon-Workspace).
//  In den Modul-Repos liegt unter libs/TileKit.php eine per
//  scripts/sync-tilekit.sh erzeugte Kopie – dort NICHT von Hand aendern.
//  Der Namespace wird beim Sync pro Repo ersetzt, damit zwei Bibliotheken
//  mit unterschiedlichem TileKit-Stand in IPS nicht kollidieren.
//
//  Vorgaben: docs/ui-kachel.md im Workspace.
// ─────────────────────────────────────────────────────────────────────────────

namespace Fuchsbau\TileKit\HeatingDashboard;

trait TileKit
{
    // ─── Seite ──────────────────────────────────────────────────────────────

    /**
     * Komplettes Kachel-Dokument. $body ist der Inhalt unter der Kopfzeile,
     * $css/$js modulspezifische Ergaenzungen. Das JS des Moduls registriert
     * seine Live-Updates mit tk.on('<key>', fn).
     */
    protected function tkPage(string $title, string $icon, string $updated, string $body, string $css = '', string $js = '', string $initJson = 'null'): string
    {
        $titleEsc   = htmlspecialchars($title, ENT_QUOTES);
        $updatedEsc = htmlspecialchars($updated, ENT_QUOTES);
        $stand      = htmlspecialchars($this->Translate('Stand'), ENT_QUOTES);
        $baseCss    = self::tkCss();
        $baseJs     = self::tkJs();
        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
{$baseCss}
{$css}
</style>
</head>
<body>
<div class="tk-header">
  <span>{$icon} {$titleEsc}</span>
  <span class="tk-updated">{$stand} <span id="tk_updated">{$updatedEsc}</span></span>
</div>
{$body}
<script>
{$baseJs}
var state = {$initJson};
{$js}
</script>
</body>
</html>
HTML;
    }

    /** Design-Tokens und Basis-Komponenten. Feste Schriftgroessen – nie mit der Kachelgroesse skalieren. */
    protected static function tkCss(): string
    {
        return <<<'CSS'
:root{
  --tk-bg:#0d1b2a;--tk-surface:#131f33;--tk-surface-2:#1a2535;--tk-on:#12405a;--tk-press:#17547a;
  --tk-border:#1e3a5f;--tk-border-on:#2a7aa0;
  --tk-text:#d0e8ff;--tk-muted:#8aa8c8;--tk-dim:#4a6a8a;--tk-faint:#3a5a7a;--tk-accent:#7ec8f0;--tk-accent-2:#9fc8e8;
  --tk-ok:#7ee89a;--tk-warn:#f0c060;--tk-alarm:#f08060;--tk-alarm-bg:#5a1010;--tk-alarm-border:#a03838;
  --tk-radius:10px;--tk-gap:7px;
  --tk-fs-meta:10px;--tk-fs-label:11px;--tk-fs-body:13px;--tk-fs-text:14px;--tk-fs-value:19px;--tk-fs-icon:22px;
}
html{height:100%}
*{box-sizing:border-box;margin:0;padding:0}
body{overflow-y:auto;overflow-x:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:var(--tk-fs-body);background:var(--tk-bg);color:var(--tk-text);display:flex;flex-direction:column;padding:8px;gap:8px;-webkit-text-size-adjust:100%}
button,input,select{font:inherit;color:inherit}
.tk-header{display:flex;justify-content:space-between;align-items:center;gap:6px;font-size:var(--tk-fs-body);font-weight:600;border-bottom:1px solid var(--tk-border);padding-bottom:5px;flex:none}
.tk-updated{font-size:var(--tk-fs-meta);color:var(--tk-faint);font-weight:400;white-space:nowrap}
/* Raster: Spaltenzahl ergibt sich aus der Breite (Handy 2, Tablet quer 6-8). Nie feste 2/3-Spalten-Raster. */
.tk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:var(--tk-gap);align-items:stretch}
.tk-grid.tk-wide{grid-template-columns:repeat(auto-fill,minmax(260px,1fr))}
.tk-grid.tk-xwide{grid-template-columns:repeat(auto-fill,minmax(320px,1fr))}
.tk-span2{grid-column:span 2}
.tk-full{grid-column:1 / -1}
.tk-group{grid-column:1 / -1;font-size:var(--tk-fs-label);font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--tk-accent);border-bottom:1px solid var(--tk-border);padding:4px 2px 3px;margin-top:4px}
.tk-group:first-child{margin-top:0}
/* Kachel */
.tk-tile{background:var(--tk-surface);border:1px solid transparent;border-radius:var(--tk-radius);padding:9px 12px;display:flex;flex-direction:column;justify-content:center;gap:3px;min-height:58px;min-width:0}
.tk-name{font-size:var(--tk-fs-label);color:var(--tk-muted);line-height:1.25;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow-wrap:break-word;hyphens:auto;-webkit-hyphens:auto}
.tk-value{font-size:var(--tk-fs-value);font-weight:600;color:var(--tk-text);line-height:1.15;overflow-wrap:break-word;hyphens:auto;-webkit-hyphens:auto}
.tk-unit{font-size:var(--tk-fs-body);font-weight:500;color:var(--tk-muted);margin-left:2px}
.tk-status{font-size:var(--tk-fs-body);font-weight:600;color:var(--tk-muted);overflow-wrap:break-word;hyphens:auto;-webkit-hyphens:auto;line-height:1.25}
.tk-text{font-size:var(--tk-fs-text);color:var(--tk-text);overflow-wrap:break-word;hyphens:auto;-webkit-hyphens:auto}
.tk-sub{font-size:var(--tk-fs-meta);color:var(--tk-dim);overflow-wrap:anywhere}
.tk-row{display:flex;align-items:center;justify-content:space-between;gap:8px}
.tk-topic{margin-right:4px;opacity:.85;filter:grayscale(.15)}
.tk-topic:empty{display:none}
.tk-chart svg{width:100%;height:24px;display:block}
.tk-chart polyline{fill:none;stroke:var(--tk-accent);stroke-width:2;vector-effect:non-scaling-stroke}
/* Zustandsfarben fuer Status-Text */
.tk-ok{color:var(--tk-ok) !important}.tk-warn{color:var(--tk-warn) !important}.tk-alarm{color:var(--tk-alarm) !important}.tk-accent{color:var(--tk-accent) !important}
/* Alarm-Kachel: ganze Flaeche rot, keine Pille im Inneren */
.tk-tile.tk-is-alarm{background:var(--tk-alarm-bg);border-color:var(--tk-alarm-border)}
.tk-tile.tk-is-alarm .tk-status{color:#ffd0c8}
.tk-tile.tk-is-warn{border-color:#8a6a20}
/* Klickbare Kachel: die ganze Flaeche ist der Button */
.tk-click{cursor:pointer;-webkit-tap-highlight-color:transparent;user-select:none;-webkit-user-select:none;transition:background-color .15s,border-color .15s,transform .08s;border-color:var(--tk-border)}
.tk-click:active{transform:scale(.97)}
.tk-click .tk-icon{font-size:15px;color:var(--tk-dim);flex:none;transition:color .15s}
.tk-click.tk-on{background:var(--tk-on);border-color:var(--tk-border-on)}
.tk-click.tk-on .tk-icon{color:var(--tk-accent)}
.tk-click.tk-on .tk-status{color:var(--tk-text)}
.tk-click .tk-row{margin-top:auto}
.tk-click.tk-busy{opacity:.6}
/* Alarm schlaegt An-Zustand (z. B. aktive Sirene), Alarmtext immer hell auf Rot */
.tk-tile.tk-is-alarm,.tk-click.tk-on.tk-is-alarm{background:var(--tk-alarm-bg);border-color:var(--tk-alarm-border)}
.tk-tile.tk-is-alarm .tk-status,.tk-tile.tk-is-alarm .tk-status.tk-alarm,.tk-tile.tk-is-alarm .tk-icon{color:#ffd0c8 !important}
.tk-action{align-items:center;justify-content:center;text-align:center;gap:6px;background:var(--tk-on);border-color:var(--tk-border-on)}
.tk-action .tk-icon{font-size:var(--tk-fs-icon);color:var(--tk-accent)}
.tk-action .tk-name{color:var(--tk-accent-2)}
.tk-action:active{background:var(--tk-press)}
/* Segment-Auswahl (Modus, Betriebsart …) als Reihe flacher Buttons */
.tk-seg{display:flex;gap:4px;flex-wrap:wrap}
.tk-seg button{flex:1 1 auto;min-width:0;background:var(--tk-surface-2);border:1px solid var(--tk-border);border-radius:7px;padding:6px 8px;font-size:var(--tk-fs-label);font-weight:600;color:var(--tk-muted);cursor:pointer;-webkit-tap-highlight-color:transparent}
.tk-seg button.tk-on{background:var(--tk-on);border-color:var(--tk-border-on);color:var(--tk-text)}
/* Regler (Dimmer, Lautstaerke, Solltemperatur) */
.tk-range{-webkit-appearance:none;appearance:none;width:100%;height:22px;background:transparent;cursor:pointer;touch-action:pan-y}
.tk-range::-webkit-slider-runnable-track{height:6px;border-radius:3px;background:var(--tk-border)}
.tk-range::-moz-range-track{height:6px;border-radius:3px;background:var(--tk-border)}
.tk-on .tk-range::-webkit-slider-runnable-track{background:rgba(13,27,42,.7)}
.tk-on .tk-range::-moz-range-track{background:rgba(13,27,42,.7)}
.tk-range::-webkit-slider-thumb{-webkit-appearance:none;width:18px;height:18px;margin-top:-6px;border-radius:50%;background:var(--tk-accent);border:2px solid var(--tk-bg)}
.tk-range::-moz-range-thumb{width:16px;height:16px;border-radius:50%;background:var(--tk-accent);border:2px solid var(--tk-bg)}
select.tk-select{width:100%;background:var(--tk-surface-2);border:1px solid var(--tk-border);border-radius:7px;padding:8px;font-size:var(--tk-fs-label);color:var(--tk-text)}
.tk-step{display:flex;align-items:center;gap:6px}
.tk-step button{width:30px;height:30px;flex:none;border-radius:8px;background:var(--tk-surface-2);border:1px solid var(--tk-border);font-size:16px;color:var(--tk-accent);cursor:pointer}
.tk-step button:active{background:var(--tk-press)}
/* Fremd-HTML (Unwetter-Tabelle, Netzwerkliste …) im isolierten iframe */
.tk-html{grid-column:1 / -1;background:var(--tk-surface);border-radius:var(--tk-radius);padding:8px;display:flex;flex-direction:column;gap:4px}
.tk-html iframe{width:100%;border:0;min-height:100px;border-radius:6px;background:var(--tk-bg)}
.tk-empty{color:var(--tk-dim);font-size:var(--tk-fs-label);padding:6px 2px}
.tk-hint{font-size:var(--tk-fs-meta);color:var(--tk-dim)}
CSS;
    }

    /** Laufzeit-JS: Live-Updates (handleMessage), optimistisches Schalten, Hilfen. requestAction() stellt IPS bereit. */
    protected static function tkJs(): string
    {
        return <<<'JS'
var tk = (function () {
  var handlers = {};
  function el(id) { return document.getElementById(id); }
  function setText(id, text) { var e = el(id); if (e && e.textContent !== String(text)) e.textContent = text; }
  function setHtml(id, html) { var e = el(id); if (e) e.innerHTML = html; }
  function setOn(id, on) { var e = el(id); if (e) e.classList.toggle('tk-on', !!on); }
  function setClass(id, cls, on) { var e = el(id); if (e) e.classList.toggle(cls, !!on); }
  function setColor(id, color) { var e = el(id); if (e) e.style.color = color || ''; }
  // Ganze Kachel als Schalter: sofort umschalten (Haptik), IPS-Push korrigiert ggf.
  function toggle(ident) {
    var card = el(ident + '_card');
    var next = card ? !card.classList.contains('tk-on') : true;
    if (card) card.classList.toggle('tk-on', next);
    requestAction(ident + '_toggle', next);
    return next;
  }
  function action(ident, value) {
    var card = el(ident + '_card');
    if (card) { card.classList.add('tk-busy'); setTimeout(function () { card.classList.remove('tk-busy'); }, 600); }
    requestAction(ident, value === undefined ? 1 : value);
  }
  function on(key, fn) { handlers[key] = fn; }
  window.handleMessage = function (raw) {
    var msg;
    try { msg = typeof raw === 'string' ? JSON.parse(raw) : raw; } catch (e) { return; }
    if (!msg || !msg.key) return;
    if (msg.updated) setText('tk_updated', msg.updated);
    var fn = handlers[msg.key];
    if (fn) fn(msg.value);
  };
  return { el: el, setText: setText, setHtml: setHtml, setOn: setOn, setClass: setClass, setColor: setColor, toggle: toggle, action: action, on: on };
})();
JS;
    }

    // ─── Bausteine (Server-seitiges Rendering) ──────────────────────────────

    protected static function tkEsc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES);
    }

    protected static function tkGroup(string $name): string
    {
        return '<div class="tk-group">' . self::tkEsc($name) . '</div>';
    }

    /** Messwert mit optionaler 6h-Sparkline (Punkte aus tkSparkline()). */
    protected static function tkValueTile(string $ident, string $name, string $text, string $chart = '', string $extraClass = ''): string
    {
        $chartHtml = $chart !== ''
            ? "<div class=\"tk-chart\"><svg viewBox=\"0 0 300 60\" preserveAspectRatio=\"none\"><polyline id=\"{$ident}_poly\" points=\"{$chart}\"/></svg></div>"
            : '';
        return "<div class=\"tk-tile {$extraClass}\" id=\"{$ident}_card\"><div class=\"tk-name\">" . self::tkEsc($name) . "</div>"
            . "<div id=\"{$ident}_val\" class=\"tk-value\">" . self::tkEsc($text) . "</div>{$chartHtml}</div>";
    }

    /**
     * Nicht schaltbarer Zustand (Ja/Nein, Text). $state: '' | 'ok' | 'warn' | 'alarm'
     * – 'alarm' faerbt die ganze Kachel, sonst nur den Status-Text.
     */
    protected static function tkStatusTile(string $ident, string $name, string $text, string $icon = '', string $state = '', ?string $color = null, string $sub = '', string $extraClass = ''): string
    {
        $cls   = ($state === 'alarm' ? ' tk-is-alarm' : ($state === 'warn' ? ' tk-is-warn' : '')) . ($extraClass !== '' ? ' ' . $extraClass : '');
        $tcls  = $state !== '' ? ' tk-' . $state : '';
        $style = $color !== null && $color !== '' ? " style=\"color:{$color}\"" : '';
        $subHtml = $sub !== '' ? "<span id=\"{$ident}_sub\" class=\"tk-sub\">" . self::tkEsc($sub) . '</span>' : '';
        return "<div class=\"tk-tile{$cls}\" id=\"{$ident}_card\"><span class=\"tk-name\">" . self::tkEsc($name) . '</span>'
            . "<span id=\"{$ident}_status\" class=\"tk-status{$tcls}\"{$style}><span id=\"{$ident}_icon\" class=\"tk-topic\">" . self::tkEsc($icon) . '</span>'
            . "<span id=\"{$ident}_val\">" . self::tkEsc($text) . "</span></span>{$subHtml}</div>";
    }

    /** Schalter: die ganze Kachel ist der Button. Klick ruft RequestAction('<ident>_toggle', bool). */
    protected static function tkToggleTile(string $ident, string $name, bool $on, string $text, string $icon = '⏻', string $extraClass = ''): string
    {
        $onCls = $on ? ' tk-on' : '';
        return "<div class=\"tk-tile tk-click{$onCls} {$extraClass}\" id=\"{$ident}_card\" role=\"button\" onclick=\"tk.toggle('{$ident}')\">"
            . '<span class="tk-name">' . self::tkEsc($name) . '</span>'
            . "<span class=\"tk-row\"><span id=\"{$ident}_val\" class=\"tk-status\">" . self::tkEsc($text) . '</span>'
            . "<span id=\"{$ident}_icon\" class=\"tk-icon\">" . self::tkEsc($icon) . '</span></span></div>';
    }

    /** Ausloeser (Skript, Snapshot …). Klick ruft RequestAction($actionIdent, 1). */
    protected static function tkActionTile(string $ident, string $actionIdent, string $name, string $icon = '▶', string $extraClass = ''): string
    {
        return "<div class=\"tk-tile tk-click tk-action {$extraClass}\" id=\"{$ident}_card\" role=\"button\" onclick=\"tk.action('{$actionIdent}')\">"
            . '<span class="tk-icon">' . self::tkEsc($icon) . '</span><span class="tk-name">' . self::tkEsc($name) . '</span></div>';
    }

    /** Fremd-HTML isoliert per iframe srcdoc, mit Dark-Theme-Overlay. */
    protected static function tkHtmlTile(string $ident, string $name, string $rawHtml, int $minHeight = 100): string
    {
        $src = self::tkEsc(self::tkDarkWrap($rawHtml));
        return "<div class=\"tk-html\" id=\"{$ident}_card\"><div class=\"tk-name\">" . self::tkEsc($name) . "</div>"
            . "<iframe id=\"{$ident}_frame\" style=\"min-height:{$minHeight}px\" srcdoc=\"{$src}\"></iframe></div>";
    }

    protected static function tkDarkWrap(string $rawHtml): string
    {
        return '<style>html,body{background:#0d1b2a !important;color:#d0e8ff !important;margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:12px}'
            . 'a{color:#7ec8f0 !important} table{color:#d0e8ff !important;width:100%;border-collapse:collapse} td,th{padding:2px 4px}</style>' . $rawHtml;
    }

    // ─── Daten-Hilfen ───────────────────────────────────────────────────────

    /** Sendet einen Teilstand an die Kachel (JS: tk.on(key, fn)). Payload immer als JSON-String – IPS 9 akzeptiert kein Array. */
    protected function tkPush(string $key, $value): void
    {
        $this->UpdateVisualizationValue(json_encode(['key' => $key, 'value' => $value, 'updated' => date('d.m. H:i')]));
    }

    /** Link-Objekte transparent bis zur Variable aufloesen (IPS_GetVariable greift nicht durch Links). */
    protected static function tkResolveVariable(int $id): int
    {
        for ($i = 0; $i < 5 && $id > 0 && @IPS_LinkExists($id); $i++) {
            $id = (int) IPS_GetLink($id)['TargetID'];
        }
        return $id > 0 && @IPS_VariableExists($id) ? $id : 0;
    }

    /** Text wie in den Symcon-Auto-Kacheln (Profil), Fallback Rohwert. */
    protected static function tkFormatted(int $varId, string $fallback = '–'): string
    {
        if ($varId <= 0 || !@IPS_VariableExists($varId)) {
            return $fallback;
        }
        $formatted = @GetValueFormatted($varId);
        if (is_string($formatted) && $formatted !== '') {
            return $formatted;
        }
        $raw = @GetValue($varId);
        if (is_bool($raw)) {
            return $raw ? 'Ja' : 'Nein';
        }
        return $raw === null || $raw === false ? $fallback : (string) $raw;
    }

    /** Farbe der aktuellen Profil-Assoziation (#RRGGBB) oder null. */
    protected static function tkAssocColor(int $varId): ?string
    {
        $var = @IPS_GetVariable($varId);
        if ($var === false) {
            return null;
        }
        $profileName = $var['VariableCustomProfile'] !== '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];
        if ($profileName === '' || !@IPS_VariableProfileExists($profileName)) {
            return null;
        }
        $value = GetValue($varId);
        foreach (IPS_GetVariableProfile($profileName)['Associations'] as $assoc) {
            if ((string) $assoc['Value'] === (string) $value && (int) $assoc['Color'] >= 0) {
                return sprintf('#%06X', (int) $assoc['Color']);
            }
        }
        return null;
    }

    /**
     * Dezentes Themen-Piktogramm nach Stichwort im Namen, passend zum Zustand.
     * Gemeinsame Liste aller Dashboards – neue Themen HIER ergaenzen (Master-Kopie).
     */
    protected static function tkTopicIcon(string $name, ?bool $value): string
    {
        if ($value === null) {
            return '';
        }
        $n = mb_strtolower($name);
        $map = [
            // [Stichworte, Icon bei true, Icon bei false, Ausschluesse]
            [['sonnenschein', 'sonne'], '☀️', '☁️', []],
            [['niederschlag', 'regen'], '🌧️', '⛅', ['wasser']],
            [['fenster', 'öffnung', 'oeffnung', 'tür', 'tuer'], '🔓', '🔒', []],
            [['rauch', 'feuer', 'brand'], '🔥', '✅', []],
            [['wasser'], '💧', '✅', []],
            [['batterie', 'akku'], '🪫', '🔋', []],
            [['alarm', 'warnung'], '🚨', '🔕', []],
            [['anwesen', 'präsenz', 'praesenz', 'zuhause', 'bewegung'], '🏠', '🚶', []],
            [['lüften', 'lueften', 'lüftung'], '🌬️', '✅', []],
            [['pollen'], '🌼', '✅', []],
            [['licht', 'lampe'], '💡', '💡', []],
            [['online', 'erreichbar', 'verbunden'], '🟢', '🔴', []],
        ];
        foreach ($map as [$words, $onIcon, $offIcon, $exclude]) {
            foreach ($exclude as $x) {
                if (str_contains($n, $x)) {
                    continue 2;
                }
            }
            foreach ($words as $w) {
                if (str_contains($n, $w)) {
                    return $value ? $onIcon : $offIcon;
                }
            }
        }
        return '';
    }

    /** Polyline-Punkte (300x60) aus [[ts, value], …] aeltester zuerst; '' bei < 2 Punkten. */
    protected static function tkSparkline(array $history, int $spanSec = 6 * 3600): string
    {
        if (count($history) < 2) {
            return '';
        }
        $start  = time() - $spanSec;
        $values = array_column($history, 1);
        $min    = min($values);
        $max    = max($values);
        if ($max - $min < 0.01) {
            $mid = ($max + $min) / 2;
            $min = $mid - 0.5;
            $max = $mid + 0.5;
        }
        $pad  = ($max - $min) * 0.15;
        $min -= $pad;
        $max += $pad;
        $pts  = [];
        foreach ($history as [$ts, $val]) {
            $x     = max(0, min(300, ($ts - $start) / $spanSec * 300));
            $y     = 60 - (($val - $min) / ($max - $min)) * 60;
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        return implode(' ', $pts);
    }

    /** Archivwerte der letzten $spanSec Sekunden als [[ts, value], …], aeltester zuerst. */
    protected static function tkHistory(int $archiveId, int $varId, int $spanSec = 6 * 3600): array
    {
        if ($archiveId <= 0 || $varId <= 0 || !function_exists('AC_GetLoggedValues')) {
            return [];
        }
        $now = time();
        try {
            $rows = @AC_GetLoggedValues($archiveId, $varId, $now - $spanSec, $now, 0);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }
        $points = [];
        foreach (array_reverse($rows) as $row) {
            $points[] = [(int) $row['TimeStamp'], (float) $row['Value']];
        }
        return $points;
    }
}
