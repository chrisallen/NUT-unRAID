<?php
ini_set('short_open_tag', '1');
require_once __DIR__ . '/../source/nut-dw/usr/local/emhttp/plugins/nut-dw/include/nut_helpers.php';

function expect_equal($actual, $expected, $message) {
    if ($actual !== $expected) {
        fwrite(STDERR, "$message: expected " . var_export($expected, true)
            . ", got " . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$canonical = nut_power_metrics([
    'ups.power' => '500', 'output.power' => '400',
    'ups.realpower' => '450', 'output.realpower' => '350',
    'ups.power.nominal' => '1000', 'ups.realpower.nominal' => '900',
    'ups.load' => '45',
]);
expect_equal($canonical['apparent'], 500.0, 'canonical apparent power wins');
expect_equal($canonical['real'], 450.0, 'canonical real power wins');
expect_equal($canonical['power_factor_source'], 'measured', 'measured power factor source');

$legacy = nut_power_metrics([
    'output.power' => '500', 'output.realpower' => '400',
    'output.power.nominal' => '1000', 'output.realpower.nominal' => '900',
]);
expect_equal($legacy['apparent'], 500.0, 'legacy apparent alias');
expect_equal($legacy['real_nominal'], 900.0, 'legacy nominal real alias');

$realOnlyDisplay = nut_power_display(nut_power_metrics(['ups.realpower' => '125']));
expect_equal($realOnlyDisplay['text'], '125&thinsp;W', 'real power displays without load');

$apparentOnlyDisplay = nut_power_display(nut_power_metrics(['ups.power' => '150']));
expect_equal($apparentOnlyDisplay['text'], '150&thinsp;VA', 'apparent power displays without load');

$noSynthesis = nut_power_metrics([
    'ups.load' => '37', 'ups.power.nominal' => '1000',
    'ups.realpower.nominal' => '900',
]);
expect_equal($noSynthesis['apparent'], null, 'load must not synthesize apparent power');
expect_equal($noSynthesis['real'], null, 'load must not synthesize real power');
expect_equal($noSynthesis['power_factor_source'], 'nominal', 'nominal ratio is labelled nominal');

$direct = nut_power_metrics([
    'ups.power' => '500', 'ups.realpower' => '300', 'output.powerfactor' => '0.95',
]);
expect_equal($direct['power_factor'], 0.95, 'direct power factor wins');
expect_equal($direct['power_factor_source'], 'direct', 'direct power factor source');

$canonicalDirect = nut_power_metrics([
    'ups.powerfactor' => '0.91', 'output.powerfactor' => '0.89',
]);
expect_equal($canonicalDirect['power_factor'], 0.89, 'standard output power factor wins');

$invalidLive = nut_power_metrics([
    'ups.power' => '300', 'ups.realpower' => '400',
]);
expect_equal($invalidLive['power_factor'], null, 'physically invalid live ratio is omitted');

$zero = nut_power_metrics(['ups.power' => '0', 'ups.realpower' => '0', 'ups.load' => '0']);
expect_equal($zero['apparent'], 0.0, 'legitimate measured zero is retained');
expect_equal($zero['real'], 0.0, 'legitimate measured real zero is retained');
expect_equal(nut_power_metric_available($zero['apparent']), true, 'measured zero is displayable');
expect_equal(nut_power_metric_available(null), false, 'missing measurement is not displayable');
expect_equal(nut_power_metric_available(-1), false, 'negative sentinel is not displayable');
$zeroDisplay = nut_power_display($zero);
expect_equal($zeroDisplay['text'], '0&thinsp;W / 0&thinsp;VA', 'measured zero is displayed');

$loadOnlyDisplay = nut_power_display(nut_power_metrics(['ups.load' => '91']));
expect_equal($loadOnlyDisplay['text'], '91&thinsp;%', 'load remains a display fallback');
expect_equal($loadOnlyDisplay['high_load'], true, 'high load retains warning state');

$manualNegative = nut_power_metrics([
    'ups.power' => '500', 'ups.power.nominal' => '1000',
    'ups.realpower' => '400', 'ups.realpower.nominal' => '900',
], [
    'manual' => true, 'powerva' => '-1200', 'powerw' => '-950',
]);
expect_equal($manualNegative['apparent_nominal'], 1200, 'negative manual VA overrides nominal');
expect_equal($manualNegative['real_nominal'], 950, 'negative manual W overrides nominal');
expect_equal($manualNegative['apparent'], 500.0, 'negative manual VA retains live measurement');
expect_equal($manualNegative['real'], 400.0, 'negative manual W retains live measurement');

$manualPositive = nut_power_metrics([
    'ups.power' => '500', 'ups.realpower' => '400',
], [
    'manual' => true, 'powerva' => '1200', 'powerw' => '950',
]);
expect_equal($manualPositive['apparent_nominal'], 1200, 'positive manual VA overrides nominal');
expect_equal($manualPositive['real_nominal'], 950, 'positive manual W overrides nominal');
expect_equal($manualPositive['apparent'], null, 'positive manual VA hides live measurement');
expect_equal($manualPositive['real'], null, 'positive manual W hides live measurement');

$manualSpecial = nut_power_metrics([
    'ups.power' => '500', 'ups.power.nominal' => '1000',
    'ups.realpower' => '400', 'ups.realpower.nominal' => '900',
], [
    'manual' => true, 'powerva' => '0', 'powerw' => '-1',
]);
expect_equal($manualSpecial['apparent_nominal'], 0, 'zero manual VA hides nominal');
expect_equal($manualSpecial['apparent'], 500.0, 'zero manual VA retains live measurement');
expect_equal($manualSpecial['real_nominal'], 900.0, '-1 manual W keeps UPS nominal');
expect_equal($manualSpecial['real'], 400.0, '-1 manual W keeps live measurement');

fwrite(STDOUT, "power metrics tests passed" . PHP_EOL);
