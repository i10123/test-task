<?php
declare(strict_types=1);

require __DIR__ . '/src/PriceResolver.php';
require __DIR__ . '/src/loadData.php';

[$_, $clientId, $sku] = $argv + [null, null, null];
$qty = filter_var($argv[3] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($argc < 3 || $argc > 4) {
    fwrite(STDERR, "Использование: php run.php <client_id> <sku> [qty]\n");
    exit(2);
}
if ($qty === false) {
    fwrite(STDERR, "Количество должно быть положительным целым числом.\n");
    exit(2);
}

$now = new DateTimeImmutable('2026-10-22 12:00:00', new DateTimeZone('Europe/Minsk'));

try {
    $data = loadPriceData(__DIR__ . '/data');
    $resolver = new PriceResolver(
        $data['prices'], $data['clients'], $data['products'], $data['rules'],
        new FileCache(__DIR__ . '/var/cache.json', $now), $now,
    );
    $base = $resolver->basePrice($clientId, $sku);
    $discount = $resolver->discountPercent($clientId, $sku, $qty);
    $price = $resolver->price($clientId, $sku, $qty);
} catch (Exception $error) {
    fwrite(STDERR, 'Ошибка: ' . $error->getMessage() . "\n");
    exit(1);
}

printf("Клиент %s (%s), товар %s x%d\n", $clientId, $data['clients'][$clientId]['price_type'], $sku, $qty);
printf("  базовая цена: %.2f\n", $base);
printf("  скидка:       %.2f%%\n", $discount);
printf("  цена на сайте: %.2f\n", $price);
