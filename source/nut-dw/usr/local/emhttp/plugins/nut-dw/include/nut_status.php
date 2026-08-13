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
require_once '/usr/local/emhttp/plugins/nut-dw/include/nut_config.php';

$nuts_response = [];

try {
    $result = [];
    $rows = [];

    $red    = "class='red-text'";
    $green  = "class='green-text'";
    $orange = "class='orange-text'";
    $status = array_fill(0,7,"<td>-</td>");
    $all    = $_GET['all']=='true';

    if ($nut_running) {
        $rows = nut_status_rows($nut_name, $nut_ip);

        if (isset($_GET['diagsave']) && $_GET['diagsave'] == "true") {
            $diagarray = $rows;

            array_walk($diagarray, function(&$var) {
                if (preg_match('/^(device|ups)\.(serial|macaddr):/i', $var, $matches)) {
                    $var = $matches[1] . '.' . $matches[2] . ': REMOVED';
                }
            });

            $diagstring = implode("\n",$diagarray);
            header('Content-Disposition: attachment; filename="nut-ups.dev"');
            header('Content-Type: text/plain');
            header('Content-Length: ' . strlen($diagstring));
            header('Connection: close');
            die($diagstring);
        }

        for ($z=0; $z<count($rows); $z++) {
            $arow = array_map('trim', explode(':', $rows[$z], 2));
            $aprop = $arow[0];
            if (stripos($aprop, "ups.alarm")!== false) {
                $nuts_response["success"]["alarms"][] = htmlspecialchars($arow[1]);
            }
        }

        $upsStatus = nut_ups_status($rows);

        $runtime = 0;
        $upsValues = [];

        $descriptorMapping = [];
        $descriptorFilePath = '/usr/share/nut/cmdvartab';
        try {
            if (file_exists($descriptorFilePath) && $descriptorFileHandle = fopen($descriptorFilePath, 'r')) {
                while (($descriptorFileLine = fgets($descriptorFileHandle)) !== false) {
                    if (preg_match('/^VARDESC\s+([a-zA-Z0-9_.]+)\s+"([^"]+)"$/', trim($descriptorFileLine), $descriptorRegexMatches)) {
                        $descriptorMapping[$descriptorRegexMatches[1]] = htmlspecialchars($descriptorRegexMatches[2]);
                    }
                }
                fclose($descriptorFileHandle);
            }
        } catch (\Throwable $t) {
            $descriptorMapping = [];
        } catch (\Exception $e) {
            $descriptorMapping = [];
        }

        for ($i=0; $i<count($rows); $i++) {
            $row = array_map('trim', explode(':', $rows[$i], 2));
            $key = $row[0];
            $val = $row[1];
            $upsValues[$key] = $val;

            switch ($key) {
                case 'ups.status':
                    if ($upsStatus['fulltext']) {
                        $status[0] = '<td' . (isset($nut_msgSeverity[$upsStatus['severity']]) ? ' class="' . $nut_msgSeverity[$upsStatus['severity']]['css_class'] . '"' : '') . '>' . implode(' - ', $upsStatus['fulltext']) . '</td>';
                    } else {
                        $status[0] = '<td class="' . $nut_msgSeverity[1]['css_class'] . '">Refreshing...</td>';
                    }
                    break;
                case 'battery.charge':
                    $status[1] = "<td $green>".intval($val). "&thinsp;%</td>";
                    if (strtok($val,' ')<=50) {
                        $status[1] = "<td $orange>".intval($val). "&thinsp;%</td>";
                    }
                    if (strtok($val,' ')<=20) {
                        $status[1] = "<td $red>".intval($val). "&thinsp;%</td>";
                    }
                    break;
                case $nut_runtime:
                    if (!is_numeric($val)) break;
                    $runtime   = $nut_rtunit == "minutes" ? gmdate("H:i:s", round($val*60)) : gmdate("H:i:s", round($val));
                    $status[2] = strtok(($nut_rtunit == "minutes" ? round($val) : round($val/60)),' ')<=5 && !in_array('ups.status: OL', $rows) ? "<td $red>$runtime</td>" : "<td $green>$runtime</td>";
                    break;
            }

            if ($all) {
                if ($i%2==0) $result[] = "<tr>";

                if(isset($descriptorMapping[$key])) {
                    $result[]= "<td><span class='tooltip-nutvar' style='cursor:help;' title='$descriptorMapping[$key]'><strong>$key</strong></span></td><td>$val</td>";
                } else {
                    $result[]= "<td><strong>$key</strong></td><td>$val</td>";
                }

                if ($i%2==1) $result[] = "</tr>";
            }
        }

        $powerMetrics = nut_power_metrics($upsValues, [
            'manual' => $nut_power == 'manual',
            'powerva' => $nut_powerva,
            'powerw' => $nut_powerw,
            'force_load' => $nut_loadcalc == 'enable',
            'load_unit' => $nut_loadunit,
        ]);
        $realPower = $powerMetrics['real'];
        $realPowerNominal = $powerMetrics['real_nominal'];
        $apparentPower = $powerMetrics['apparent'];
        $powerNominal = $powerMetrics['apparent_nominal'];
        $load = $powerMetrics['load'];

        $hasLoad = nut_power_metric_available($load);
        $highLoad = $hasLoad && $load >= 90;
        $powerColor = $highLoad ? $red : $green;

        if ($hasLoad) {
            $status[5] = "<td $powerColor>"
                . nut_format_power_number($load) . "&thinsp;%</td>";
        }

        if ($powerNominal > 0 && $realPowerNominal > 0) {
            $status[3] = "<td $green>"
                . nut_format_power_number($realPowerNominal) . "&thinsp;W / "
                . nut_format_power_number($powerNominal) . "&thinsp;VA</td>";
        } elseif ($powerNominal > 0) {
            $status[3] = "<td $green>"
                . nut_format_power_number($powerNominal) . "&thinsp;VA</td>";
        } elseif ($realPowerNominal > 0) {
            $status[3] = "<td $green>"
                . nut_format_power_number($realPowerNominal) . "&thinsp;W</td>";
        }

        # Display each measured power value independently, including zero.
        $hasApparentPower = nut_power_metric_available($apparentPower);
        $hasRealPower = nut_power_metric_available($realPower);
        if ($hasApparentPower && $hasRealPower) {
            $status[4] = "<td $powerColor>"
                . nut_format_power_number($realPower) . "&thinsp;W / "
                . nut_format_power_number($apparentPower) . "&thinsp;VA</td>";
        } elseif ($hasApparentPower) {
            $status[4] = "<td $powerColor>"
                . nut_format_power_number($apparentPower) . "&thinsp;VA</td>";
        } elseif ($hasRealPower) {
            $status[4] = "<td $powerColor>"
                . nut_format_power_number($realPower) . "&thinsp;W</td>";
        }

        if ($powerMetrics['power_factor'] !== null) {
            $powerFactorLabel = $powerMetrics['power_factor_source'] === 'nominal'
                ? ' (nominal)'
                : '';
            $status[6] = "<td $green>" . round($powerMetrics['power_factor'], 2)
                . $powerFactorLabel . "</td>";
        }

        if ($all && count($rows)%2==1) $result[] = "<td></td><td></td></tr>";
    }
    if ($all && !$rows) $result[] = "<tr><td colspan='4' style='text-align:center'>No information available</td></tr>";

    if($all) {
        $nuts_response["success"]["response"] = "<tr>".implode('', $status)."</tr>";
        $nuts_response["success"]["allvars"] = implode('', $result);
    } else {
        $nuts_response["success"]["response"] = "<tr>".implode('', $status)."</tr>";
    }
}
catch (\Throwable $t) {
    error_log($t);
    $nuts_response = [];
    $nuts_response["error"]["response"] = $t->getMessage();
}
catch (\Exception $e) {
    error_log($e);
    $nuts_response = [];
    $nuts_response["error"]["response"] = $e->getMessage();
}

echo(json_encode($nuts_response));
?>
