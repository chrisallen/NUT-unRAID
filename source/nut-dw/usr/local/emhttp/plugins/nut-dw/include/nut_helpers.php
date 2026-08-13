<?
/* Copyright Derek Macias (parts of code from NUT package)
 * Copyright macester (parts of code from NUT package)
 * Copyright gfjardim (parts of code from NUT package)
 * Copyright SimonF (parts of code from NUT package)
 * Copyright Dan Landon (parts of code from Web GUI)
 * Copyright Bergware International (parts of code from Web GUI)
 * Copyright Lime Technology (any and all other parts of Unraid)
 *
 * Copyright desertwitch (as author and maintainer of this file)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 */

function nut_status_rows($name, $ip) {
    $rows = [];
    $status = 1;

    $cmd = '/usr/bin/upsc '
        . escapeshellarg($name) . '@' . escapeshellarg($ip) . ' 2>/dev/null';

    exec($cmd, $rows, $status);

    if ($status === 0 && !empty($rows)) {
        return $rows;
    }

    // Fallback for broken NUT protocol implementations
    // Try to get a number of known important variables one by one...
    $vars = [
        'battery.charge.low',
        'battery.charge',
        'battery.low', // according to user reports for UniFi UPS
        'battery.runtime',
        'battery.voltage',
        'input.current',
        'input.frequency',
        'input.voltage',
        'output.current',
        'output.frequency',
        'output.power.nominal', // according to user reports for UniFi UPS
        'output.power', // according to user reports for UniFi UPS
        'output.powerfactor',
        'output.realpower.nominal',
        'output.realpower',
        'output.voltage',
        'ups.id',
        'ups.load',
        'ups.mfr',
        'ups.model',
        'ups.power.nominal',
        'ups.power',
        'ups.powerfactor',
        'ups.realpower.nominal',
        'ups.realpower',
        'ups.serial',
        'ups.status',
        'ups.test.date',
        'ups.test.interval',
        'ups.test.result',
        'ups.type',
    ];

    $rows = [];
    foreach ($vars as $var) {
        $out = [];
        $code = 1;

        $cmd = '/usr/bin/upsc '
             . escapeshellarg($name) . '@' . escapeshellarg($ip) . ' '
             . escapeshellarg($var) . ' 2>/dev/null';

        exec($cmd, $out, $code);

        if ($code === 0 && isset($out[0]) && $out[0] !== '') {
            $rows[] = $var . ': ' . $out[0];
        }
    }

    if(!empty($rows)) {
        $rows[] = "x.plugin.varfetch: fallback";
    }

    return $rows;
}

function nut_numeric_variable($values, $names) {
    foreach ($names as $name) {
        if (!array_key_exists($name, $values)) continue;

        $value = strtok(trim((string)$values[$name]), ' ');
        if (is_numeric($value)) return (float)$value;
    }

    return null;
}

function nut_power_metric_available($value) {
    return $value !== null && is_numeric($value) && $value >= 0;
}

function nut_apply_power_overrides($metrics, $options) {
    if (empty($options['manual'])) return $metrics;

    // -1 keeps the UPS value. Negative values replace only the nominal
    // rating, zero hides it, and positive values also hide the live value.
    $manualVA = isset($options['powerva']) ? intval($options['powerva']) : -1;
    if ($manualVA !== -1) {
        $metrics['apparent_nominal'] = abs($manualVA);
        if ($manualVA > 0) $metrics['apparent'] = null;
    }

    $manualW = isset($options['powerw']) ? intval($options['powerw']) : -1;
    if ($manualW !== -1) {
        $metrics['real_nominal'] = abs($manualW);
        if ($manualW > 0) $metrics['real'] = null;
    }

    return $metrics;
}

function nut_power_load_percentage($measured, $nominal) {
    if ($measured === null || $measured < 0 || $nominal === null || $nominal <= 0) return null;

    $load = round($measured / $nominal * 100);

    return $load >= 0 && $load <= 100 ? $load : null;
}

function nut_calculate_power_load($metrics, $options) {
    if ($metrics['load'] !== null && $metrics['load'] > 0 && empty($options['force_load'])) return $metrics;

    // Load may be derived from measured and nominal power, but a reported
    // load percentage must never be used to invent live VA or W readings.
    $loadW = nut_power_load_percentage($metrics['real'], $metrics['real_nominal']);
    $loadVA = nut_power_load_percentage($metrics['apparent'], $metrics['apparent_nominal']);
    $loadUnit = isset($options['load_unit']) ? $options['load_unit'] : 'W';

    if ($loadUnit === 'VA' && $loadVA !== null) $metrics['load'] = $loadVA;
    if ($loadUnit === 'W' && $loadW !== null) $metrics['load'] = $loadW;

    return $metrics;
}

function nut_power_factor_ratio($realPower, $apparentPower) {
    if ($realPower === null || $realPower < 0 || $apparentPower === null || $apparentPower <= 0) return null;
    if ($realPower > $apparentPower) return null;

    return $realPower / $apparentPower;
}

function nut_add_power_factor($metrics, $values) {
    // Prefer NUT's standard output value, then measured W/VA, and finally the
    // nominal ratio. The UPS-level name remains as a compatibility fallback.
    $directPowerFactor = nut_numeric_variable(
        $values,
        ['output.powerfactor', 'ups.powerfactor']
    );
    if ($directPowerFactor !== null && $directPowerFactor > 0 && $directPowerFactor <= 1) {
        $metrics['power_factor'] = $directPowerFactor;
        $metrics['power_factor_source'] = 'direct';

        return $metrics;
    }

    $measuredPowerFactor = nut_power_factor_ratio($metrics['real'], $metrics['apparent']);
    if ($measuredPowerFactor !== null) {
        $metrics['power_factor'] = $measuredPowerFactor;
        $metrics['power_factor_source'] = 'measured';

        return $metrics;
    }

    $nominalPowerFactor = nut_power_factor_ratio($metrics['real_nominal'], $metrics['apparent_nominal']);
    if ($nominalPowerFactor !== null && $nominalPowerFactor > 0) {
        $metrics['power_factor'] = $nominalPowerFactor;
        $metrics['power_factor_source'] = 'nominal';
    }

    return $metrics;
}

function nut_power_metrics($values, $options = []) {
    // Canonical aggregate NUT names take precedence over legacy output aliases.
    $metrics = [
        'load' => nut_numeric_variable($values, ['ups.load']),
        'apparent' => nut_numeric_variable($values, ['ups.power', 'output.power']),
        'apparent_nominal' => nut_numeric_variable($values, ['ups.power.nominal', 'output.power.nominal']),
        'real' => nut_numeric_variable($values, ['ups.realpower', 'output.realpower']),
        'real_nominal' => nut_numeric_variable($values, ['ups.realpower.nominal', 'output.realpower.nominal']),
        'power_factor' => null,
        'power_factor_source' => null,
    ];

    $metrics = nut_apply_power_overrides($metrics, $options);
    $metrics = nut_calculate_power_load($metrics, $options);

    return nut_add_power_factor($metrics, $values);
}

function nut_format_power_number($value) {
    if ($value === null || !is_numeric($value)) return '';
    if ((float)$value == (int)$value) return (string)(int)$value;

    return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
}

function nut_power_display($metrics) {
    $values = [];
    $details = [];
    $hasLoad = nut_power_metric_available($metrics['load']);

    if ($hasLoad) {
        $details[] = "Load: " . nut_format_power_number($metrics['load']) . "&thinsp;%";
    }
    if (nut_power_metric_available($metrics['real'])) {
        $realPower = nut_format_power_number($metrics['real']);
        $values[] = $realPower . "&thinsp;W";
        $details[] = "Measured Real Power: " . $realPower . "&thinsp;W";
    }
    if (nut_power_metric_available($metrics['apparent'])) {
        $apparentPower = nut_format_power_number($metrics['apparent']);
        $values[] = $apparentPower . "&thinsp;VA";
        $details[] = "Measured Apparent Power: " . $apparentPower . "&thinsp;VA";
    }

    return [
        'text' => !empty($values)
            ? implode(' / ', $values)
            : ($hasLoad ? nut_format_power_number($metrics['load']) . "&thinsp;%" : ''),
        'details' => implode(' - ', $details),
        'high_load' => $hasLoad && $metrics['load'] >= 90,
    ];
}

/* get options for battery level */
function nut_get_battery_options($selected=20){
    $range = [1,99];
    rsort($range);
    $options = "";
    foreach(range($range[0], $range[1], 1) as $level){
        $options .= "<option value='$level'";

        // set saved option as selected
        if (intval($selected) === $level) {
            $options .= " selected";
        }

        $options .= ">$level</option>";
    }
    return $options;
}

/* get options for time intervals */
function nut_get_minute_options($time){
    $options = '';
        for($i = 1; $i <= 180; $i++){
            $options .= '<option value="'.($i*60).'"';

            if(intval($time) === ($i*60)) {
                $options .= ' selected';
            }

            $options .= '>'.$i.'</option>';
        }
    return $options;
}

function nut_ups_status($rows, $valueOnly = false)
{
    global $nut_states;

    $severity = 0;
    $status_values = [];
    $status_fulltext = [];

    array_walk($rows, function($row) use (&$severity, &$status_fulltext, &$status_values, $nut_states, $valueOnly) {
        if ($valueOnly) {
            # if only ups.status value as param
            $status_values = explode(' ', $row);
        }
        elseif (preg_match('/^ups.status:\s*([^$]+)/i', $row, $matches)) {
            # if status array as param, find ups.status
            $status_values = explode(' ', $matches[1]);
        } else {
            # skip everything else
            return;
        }

        # if debug constant defined, overwrite ups.status values
        if (defined('NUT_STATUS_DEBUG')) {
            $status_values = explode(' ', NUT_STATUS_DEBUG);
        }

        # replace ups.status flags with full text message.
        $status_fulltext = array_map(function($var) use (&$severity, $nut_states) {
            if (isset($nut_states[$var]) && $nut_states[$var]) {
                # keep the highest severity message level
                $severity = max($severity, $nut_states[$var]['severity']);
                return $nut_states[$var]['msg'];
            } else {
                # if unknown status flag, return it
                return $var;
            }
        }, $status_values);
    });

    # return highest severity message level, array of status flags and array of full text status message
    return ['severity' => $severity, 'value' => $status_values, 'fulltext' => $status_fulltext];
}

function nut_download_url($url, $conn_timeout = 15, $timeout = 45) {
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $conn_timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_REFERER, "");
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        $out = curl_exec($ch) ?: false;
        curl_close($ch);
        return $out;
    } catch (\Throwable $t) { // For PHP 7
        return false;
    } catch (\Exception $e) { // For PHP 5
        return false;
    }
}

function nut_get_dev_message() {
    try {
        $dev_message_url = "https://raw.githubusercontent.com/desertwitch/NUT-unRAID/master/plugin/developer_message";
        $raw_dev_message = nut_download_url($dev_message_url, 30, 45);
        if($raw_dev_message && strpos($raw_dev_message, "NODISPLAY") === false) {
            return $raw_dev_message;
        } else {
            return false;
        }
    } catch (\Throwable $t) { // For PHP 7
        return false;
    } catch (\Exception $e) { // For PHP 5
        return false;
    }
}

function nut_tailFile($filePath, $lines = 90) {
    try {
        if (!file_exists($filePath)) {
            throw new Exception("... requested syslog file is either empty or does not exist (yet)");
        }

        $f = fopen($filePath, "r");
        if (!$f) {
            throw new Exception("... requested syslog file exists but was not accessible");
        }

        $buffer = 4096;
        $lineCount = 0;
        $position = -1;
        $text = '';

        fseek($f, 0, SEEK_END);

        while ($lineCount < $lines) {
            $position -= $buffer;
            if ($position < -ftell($f)) {
                $position = -ftell($f);
            }

            fseek($f, $position, SEEK_END);
            $chunk = fread($f, $buffer);
            $text = $chunk . $text;
            $lineCount = substr_count($text, "\n");

            if ($position == -ftell($f)) {
                break;
            }
        }

        fclose($f);

        $allLines = explode("\n", str_replace("\r\n", "\n", $text));
        $sanitizedLines = array_map('htmlspecialchars', array_slice($allLines, -$lines));
        return implode("\n", $sanitizedLines);
    } catch (\Throwable $t) {
        return $t->getMessage();
    } catch (\Exception $e) {
        return $e->getMessage();
    }
}

?>
