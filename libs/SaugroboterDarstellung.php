<?php

declare(strict_types=1);

/*
 * Saugroboter – Variablen-Darstellungen (Symcon ab 8.1) statt eigener Profile.
 * Copyright (c) 2026 Armin Frohwerk · SPDX-License-Identifier: MIT
 *
 * In verschachtelten Strukturen (OPTIONS, INTERVALS) sind laut Symcon keine Felder optional –
 * deshalb werden hier immer alle gesetzt. Fehlt bei der Wertanzeige die Farbe einer Option,
 * zeigt die Symcon-App „Invalid Configuration“.
 */
trait SaugroboterDarstellung
{
    /** Wertanzeige mit Einheit, Nachkommastellen und optionalen Intervallen. */
    private function PresentValue(string $icon, string $suffix = '', ?int $digits = null, array $intervals = [], bool $multiline = false): array
    {
        $p = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => $icon];
        if ($suffix !== '') {
            $p['SUFFIX'] = $suffix;
        }
        if ($digits !== null) {
            $p['DIGITS'] = $digits;
        }
        if ($multiline) {
            $p['MULTILINE'] = true;
        }
        if (count($intervals) > 0) {
            $p['INTERVALS_ACTIVE'] = true;
            $p['INTERVALS'] = (string) json_encode($intervals, JSON_UNESCAPED_UNICODE);
        }
        return $p;
    }

    /** Wertanzeige für Zahlencodes: jeder Code als Text (Intervall mit Min = Max). [Wert => Text] */
    private function PresentStates(string $icon, array $states): array
    {
        $intervals = [];
        foreach ($states as $value => $caption) {
            $intervals[] = $this->Interval((float) $value, (float) $value, (string) $caption);
        }
        return $this->PresentValue($icon, '', null, $intervals);
    }

    /** Wertanzeige für Ja/Nein mit eigenen Texten, Symbolen und Farben. */
    private function PresentBool(string $icon, string $off, int $colorOff, string $on, int $colorOn): array
    {
        $option = static function (bool $value, string $caption, int $color): array {
            return ['Value' => $value, 'Caption' => $caption, 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => $color];
        };
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => $icon,
            'OPTIONS'      => (string) json_encode([$option(false, $off, $colorOff), $option(true, $on, $colorOn)], JSON_UNESCAPED_UNICODE)
        ];
    }

    /** Schalter für bedienbare Ja/Nein-Variablen. */
    private function PresentSwitch(string $icon): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON' => $icon, 'USAGE_TYPE' => 0];
    }

    /** Aufzählung für bedienbare Variablen (nur zusammen mit EnableAction). [Wert => Text] */
    private function PresentEnumeration(string $icon, array $options): array
    {
        $list = [];
        foreach ($options as $value => $caption) {
            $list[] = ['Value' => (int) $value, 'Caption' => (string) $caption, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => $icon,
            'LAYOUT'       => 1,
            'DISPLAY'      => 0,
            'OPTIONS'      => (string) json_encode($list, JSON_UNESCAPED_UNICODE)
        ];
    }

    /** Ein vollständig belegtes Intervall der Wertanzeige, das einen Code als Text zeigt. */
    private function Interval(float $min, float $max, string $constant): array
    {
        return [
            'IntervalMinValue' => $min,
            'IntervalMaxValue' => $max,
            'ConstantActive'   => $constant !== '',
            'ConstantValue'    => $constant,
            'ConversionFactor' => 1,
            'PrefixActive'     => false,
            'PrefixValue'      => '',
            'SuffixActive'     => false,
            'SuffixValue'      => '',
            'DigitsActive'     => false,
            'DigitsValue'      => 0,
            'IconActive'       => false,
            'IconValue'        => '',
            'ColorActive'      => false,
            'Color'            => 0
        ];
    }

    /**
     * Profile früherer Versionen (SAUG.*) löschen, sobald keine Variable sie mehr nutzt.
     * Ein Profil, das noch irgendwo verwendet wird – z. B. von Hand zugewiesen –, bleibt erhalten.
     */
    private function RemoveUnusedProfiles(): void
    {
        $legacy = [];
        foreach (IPS_GetVariableProfileList() as $name) {
            if (strpos($name, 'SAUG.') === 0) {
                $legacy[] = $name;
            }
        }
        if (count($legacy) === 0) {
            return;
        }
        $used = [];
        foreach (IPS_GetVariableList() as $id) {
            $v = IPS_GetVariable($id);
            $used[(string) ($v['VariableProfile'] ?? '')] = true;
            $used[(string) ($v['VariableCustomProfile'] ?? '')] = true;
        }
        foreach ($legacy as $name) {
            if (!isset($used[$name])) {
                IPS_DeleteVariableProfile($name);
                $this->SendDebug('Darstellungen', 'altes Profil ' . $name . ' entfernt', 0);
            }
        }
    }
}
