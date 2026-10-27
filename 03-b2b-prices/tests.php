<?php
declare(strict_types=1);

require __DIR__ . '/src/PriceResolver.php';
require __DIR__ . '/src/loadData.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function withDataFixture(callable $action): void
{
    $directory = sys_get_temp_dir() . '/b2b-data-test-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $files = ['prices.csv', 'clients.csv', 'products.csv', 'discount_rules.json'];

    try {
        foreach ($files as $file) {
            copy(__DIR__ . '/data/' . $file, $directory . '/' . $file);
        }
        $action($directory);
    } finally {
        foreach ($files as $file) {
            if (is_file($directory . '/' . $file)) {
                unlink($directory . '/' . $file);
            }
        }
        rmdir($directory);
    }
}

function expectPrice(float $actual, float $expected): void
{
    if (abs($actual - $expected) > 0.000001) {
        throw new RuntimeException("Ожидалось {$expected}, получено {$actual}");
    }
}

function expectError(callable $action, string $messagePart): void
{
    try {
        $action();
    } catch (Exception $error) {
        if (!str_contains($error->getMessage(), $messagePart)) {
            throw new RuntimeException('Неожиданная ошибка: ' . $error->getMessage());
        }
        return;
    }
    throw new RuntimeException('Ожидалась ошибка: ' . $messagePart);
}

['prices' => $prices, 'clients' => $clients, 'products' => $products, 'rules' => $rules] = loadPriceData(__DIR__ . '/data');
$now = new DateTimeImmutable('2026-10-22 12:00:00', new DateTimeZone('Europe/Minsk'));
$cacheFile = tempnam(sys_get_temp_dir(), 'b2b-price-test-');
if ($cacheFile === false) {
    throw new RuntimeException('Не удалось создать временный кеш для проверок');
}

$legacyCache = json_encode([
    'price:K-004:B-103:1' => ['value' => 15.48, 'expires_at' => '2026-10-23T09:05:00+03:00'],
    'price:K-001:B-101:1' => ['value' => 13.72, 'expires_at' => '2026-10-23T12:00:00+03:00'],
    'price:K-003:B-105:1' => ['value' => 4.70, 'expires_at' => '2026-10-23T12:00:00+03:00'],
], JSON_THROW_ON_ERROR);

$make = function (
    ?array $clientData = null,
    ?array $priceData = null,
    ?array $ruleData = null,
    ?array $productData = null,
    ?DateTimeImmutable $at = null,
) use ($clients, $prices, $rules, $products, $now, $cacheFile): PriceResolver {
    $at ??= $now;
    return new PriceResolver(
        $priceData ?? $prices, $clientData ?? $clients, $productData ?? $products,
        $ruleData ?? $rules, new FileCache($cacheFile, $at), $at,
    );
};

$failures = 0;
$checks = 0;
$check = function (string $name, callable $action) use (&$failures, &$checks, $cacheFile, $legacyCache): void {
    $checks++;
    try {
        file_put_contents($cacheFile, $legacyCache);
        $action();
        echo "[OK] {$name}\n";
    } catch (Throwable $error) {
        $failures++;
        echo "[FAIL] {$name}: {$error->getMessage()}\n";
    }
};

try {
    $check('Нет дилерской цены: ошибка вместо розничной цены или старого кеша', function () use ($make): void {
        expectError(fn() => $make()->basePrice('K-003', 'B-105'), 'DEALER');
        expectError(fn() => $make()->price('K-003', 'B-105'), 'DEALER');
    });
    $check('Сентябрьская акция завершилась: скидка 0%, цена 14.75', function () use ($make): void {
        expectPrice($make()->discountPercent('K-001', 'B-101', 1), 0);
        expectPrice($make()->price('K-001', 'B-101'), 14.75);
    });
    $check('Из скидок 5%, 3% и 4% выбирается 5%; цена 3.51', function () use ($make): void {
        expectPrice($make()->discountPercent('K-002', 'B-106', 12), 5);
        expectPrice($make()->price('K-002', 'B-106', 12), 3.51);
    });
    $check('Старая запись 15.48 не скрывает текущую цену 15.72', function () use ($make): void {
        expectPrice($make()->price('K-004', 'B-103'), 15.72);
    });
    $check('Разные группы цен: 10.89 у K-005 и 10.28 у K-006', function () use ($make): void {
        expectPrice($make()->price('K-005', 'B-107'), 10.89);
        expectPrice($make()->price('K-006', 'B-107'), 10.28);
    });
    $check('Граница объёмной скидки: 9 и 10 единиц', function () use ($make): void {
        expectPrice($make()->price('K-001', 'B-101', 9), 14.75);
        expectPrice($make()->price('K-001', 'B-101', 10), 14.31);
    });
    $check('Акция начинается в первый день в 00:00', function () use ($make, $now): void {
        expectPrice($make(at: $now->modify('2026-10-14 23:59:59'))->price('K-005', 'B-107'), 11.34);
        expectPrice($make(at: $now->modify('2026-10-15 00:00:00'))->price('K-005', 'B-107'), 10.89);
    });
    $check('Последний день включён; после полуночи цена меняется без ожидания TTL', function () use ($make, $now): void {
        expectPrice($make(at: $now->modify('2026-10-31 23:59:59'))->price('K-005', 'B-107'), 10.89);
        expectPrice($make(at: $now->modify('2026-11-01 00:00:00'))->price('K-005', 'B-107'), 11.34);
    });
    $check('Даты корректно сравниваются на границе года', function () use ($make, $now): void {
        $newYearRule = [['type' => 'promo', 'category' => 'Канцтовары', 'percent' => 10,
            'valid_from' => '31.12.2026', 'valid_to' => '01.01.2027']];
        expectPrice($make(ruleData: $newYearRule, at: $now->modify('2027-01-01 12:00:00'))->price('K-001', 'B-101'), 13.28);
        expectPrice($make(ruleData: $newYearRule, at: $now->modify('2027-01-02 12:00:00'))->price('K-001', 'B-101'), 14.75);
    });
    $check('Смена группы клиента обновляет цену при заполненном кеше', function () use ($make, $clients): void {
        $before = $clients;
        $before['K-004']['price_type'] = 'OPT1';
        expectPrice($make(clientData: $before)->price('K-004', 'B-103'), 16.65);
        expectPrice($make()->price('K-004', 'B-103'), 15.72);
    });
    $check('Изменение персональной скидки обновляет цену', function () use ($make, $clients): void {
        expectPrice($make()->price('K-005', 'B-107'), 10.89);
        $changed = $clients;
        $changed['K-005']['personal_discount'] = '10';
        expectPrice($make(clientData: $changed)->price('K-005', 'B-107'), 10.21);
    });
    $check('Обновление базовой цены учитывается при заполненном кеше', function () use ($make, $prices): void {
        expectPrice($make()->price('K-002', 'B-106', 12), 3.51);
        $changed = $prices;
        $changed['B-106']['OPT1'] = 4.00;
        expectPrice($make(priceData: $changed)->price('K-002', 'B-106', 12), 3.80);
    });
    $check('Изменение правил скидок обновляет цену', function () use ($make, $rules): void {
        expectPrice($make()->price('K-005', 'B-107'), 10.89);
        $changed = $rules;
        $changed[2]['percent'] = 9;
        expectPrice($make(ruleData: $changed)->price('K-005', 'B-107'), 10.32);
    });
    $check('Изменение категории товара обновляет применимость акции', function () use ($make, $products): void {
        expectPrice($make()->price('K-005', 'B-107'), 10.89);
        $changed = $products;
        $changed['B-107']['category'] = 'Канцтовары';
        expectPrice($make(productData: $changed)->price('K-005', 'B-107'), 11.34);
    });
    $check('Повторный расчёт с теми же данными возвращает ту же цену', function () use ($make): void {
        expectPrice($make()->price('K-002', 'B-106', 12), 3.51);
        expectPrice($make()->price('K-002', 'B-106', 12), 3.51);
    });
    $check('Неизвестный клиент и товар дают понятную ошибку', function () use ($make): void {
        expectError(fn() => $make()->price('UNKNOWN', 'B-101'), 'клиент');
        expectError(fn() => $make()->price('K-001', 'UNKNOWN'), 'товар');
    });
    $check('Нулевое и отрицательное количество отклоняются', function () use ($make): void {
        expectError(fn() => $make()->price('K-001', 'B-101', 0), 'Количество');
        expectError(fn() => $make()->discountPercent('K-001', 'B-101', -1), 'Количество');
    });
    $check('Некорректные даты и обратный период скидки отклоняются', function () use ($make, $rules): void {
        foreach ([['31.02.2026', '31.12.2026'], ['31.12.2026', '01.01.2026'], ['', '31.12.2026']] as [$from, $to]) {
            $changed = $rules;
            $changed[0]['valid_from'] = $from;
            $changed[0]['valid_to'] = $to;
            expectError(fn() => $make(ruleData: $changed)->price('K-001', 'B-101'), 'период');
        }
    });
    $check('Неверные скидки и порог количества не создают ложную цену', function () use ($make, $clients, $rules): void {
        foreach (['abc', '-1', '101'] as $discount) {
            $changed = $clients;
            $changed['K-001']['personal_discount'] = $discount;
            expectError(fn() => $make(clientData: $changed)->price('K-001', 'B-101'), 'Скидка');
        }
        $changed = $rules;
        $changed[0]['min_qty'] = 0;
        expectError(fn() => $make(ruleData: $changed)->price('K-001', 'B-101'), 'Порог');
        $changed = $rules;
        $changed[2]['percent'] = 101;
        expectError(fn() => $make(ruleData: $changed)->price('K-005', 'B-107'), 'Скидка');
    });
    $check('Неизвестное правило и некорректная цена дают ошибку', function () use ($make, $rules, $prices): void {
        $changedRules = $rules;
        $changedRules[0]['type'] = 'unknown';
        expectError(fn() => $make(ruleData: $changedRules)->price('K-001', 'B-101'), 'тип правила');
        $changedPrices = $prices;
        $changedPrices['B-101']['RETAIL'] = -1;
        expectError(fn() => $make(priceData: $changedPrices)->price('K-001', 'B-101'), 'Некорректная цена');
    });
    $check('CSV: неверное число полей и заголовок дают понятную ошибку', function (): void {
        withDataFixture(function (string $directory): void {
            file_put_contents($directory . '/prices.csv', "sku;price_type;price\nB-101;RETAIL\n");
            expectError(fn() => loadPriceData($directory), 'строка 2');
            file_put_contents($directory . '/prices.csv', "sku;price;price\nB-101;10;10\n");
            expectError(fn() => loadPriceData($directory), 'заголовок CSV');
        });
    });
    $check('CSV: неверная или повторная цена не заменяется нулём', function (): void {
        withDataFixture(function (string $directory): void {
            foreach (['abc', '-1', '1e999'] as $price) {
                file_put_contents($directory . '/prices.csv', "sku;price_type;price\nB-101;RETAIL;{$price}\n");
                expectError(fn() => loadPriceData($directory), 'Некорректная цена');
            }
            file_put_contents($directory . '/prices.csv', "sku;price_type;price\nB-101;RETAIL;10\nB-101;RETAIL;12\n");
            expectError(fn() => loadPriceData($directory), 'Повторная цена');
        });
    });
    $check('Повторная карточка клиента не перезаписывает первую', function (): void {
        withDataFixture(function (string $directory): void {
            file_put_contents($directory . '/clients.csv', "\nK-001;Дубль;RETAIL;10\n", FILE_APPEND);
            expectError(fn() => loadPriceData($directory), 'повторяющийся идентификатор');
        });
    });
    $check('Отсутствующий CSV, битый JSON и неверный список правил дают ошибку', function () use ($cacheFile): void {
        expectError(fn() => readCsv($cacheFile . '/missing.csv', ['sku']), 'открыть CSV');
        withDataFixture(function (string $directory): void {
            file_put_contents($directory . '/discount_rules.json', '{');
            expectError(fn() => loadPriceData($directory), 'JSON правил скидок');
            file_put_contents($directory . '/discount_rules.json', '{"rules":{"bad":[]}}');
            expectError(fn() => loadPriceData($directory), 'список rules');
        });
    });
    $check('Кеш вызывает расчёт один раз; в момент истечения срока пересчитывает', function () use ($cacheFile, $now): void {
        $calls = 0;
        $compute = function () use (&$calls): float {
            $calls++;
            return $calls * 1.25;
        };
        $cache = new FileCache($cacheFile, $now);
        expectPrice($cache->remember('ttl-check', 60, $compute), 1.25);
        expectPrice($cache->remember('ttl-check', 60, $compute), 1.25);
        expectPrice((new FileCache($cacheFile, $now->modify('+60 seconds')))->remember('ttl-check', 60, $compute), 2.50);
    });
    $check('Ошибка расчёта не меняет кеш и освобождает блокировку', function () use ($cacheFile, $now): void {
        $before = file_get_contents($cacheFile);
        $cache = new FileCache($cacheFile, $now);
        expectError(fn() => $cache->remember('failed', 60, fn() => throw new RuntimeException('Расчёт прерван')), 'прерван');
        if (file_get_contents($cacheFile) !== $before) {
            throw new RuntimeException('При ошибке расчёта кеш изменился');
        }
        expectPrice($cache->remember('after-failure', 60, fn(): float => 5.0), 5.0);
    });
    $check('Повреждённый кеш не перезаписывается без сообщения об ошибке', function () use ($cacheFile, $now): void {
        $cache = new FileCache($cacheFile, $now);
        file_put_contents($cacheFile, '{');
        expectError(fn() => $cache->remember('bad', 60, fn(): float => 1.0), 'JSON кеша');
        if (file_get_contents($cacheFile) !== '{') {
            throw new RuntimeException('Повреждённый файл был перезаписан');
        }
        foreach (['null', '[1]'] as $invalidData) {
            file_put_contents($cacheFile, $invalidData);
            expectError(fn() => $cache->remember('bad', 60, fn(): float => 1.0), 'формат кеша');
        }
    });
    $check('Неверные значения и даты в кеше не выдаются за цену', function () use ($cacheFile, $now): void {
        $cache = new FileCache($cacheFile, $now);
        file_put_contents($cacheFile, '{"bad":{"value":"abc","expires_at":"2026-10-23T12:00:00+03:00"}}');
        expectError(fn() => $cache->remember('bad', 60, fn(): float => 1.0), 'запись кеша');
        file_put_contents($cacheFile, '{"bad":{"value":10,"expires_at":"bad-date"}}');
        expectError(fn() => $cache->remember('bad', 60, fn(): float => 1.0), 'срок записи кеша');
    });
    $check('Недоступный файл кеша, неверный срок и отрицательная цена отклоняются', function () use ($cacheFile, $now): void {
        expectError(fn() => (new FileCache($cacheFile . '/missing.json', $now))->remember('x', 60, fn(): float => 1.0), 'открыть файл кеша');
        $cache = new FileCache($cacheFile, $now);
        expectError(fn() => $cache->remember('x', 0, fn(): float => 1.0), 'Срок хранения');
        expectError(fn() => $cache->remember('x', 60, fn(): float => -1.0), 'неотрицательную цену');
    });
    $check('Шесть параллельных процессов сохраняют все свои записи кеша', function () use ($cacheFile): void {
        $code = 'require $argv[1]; $now = new DateTimeImmutable("2026-10-22T12:00:00+03:00");'
            . '$cache = new FileCache($argv[2], $now);'
            . '$cache->remember($argv[3], 60, function (): float { usleep(30000); return 1.0; });';
        $workers = [];
        $errors = [];
        try {
            for ($index = 0; $index < 6; $index++) {
                $process = proc_open(
                    [PHP_BINARY, '-r', $code, __DIR__ . '/src/PriceResolver.php', $cacheFile, "worker:{$index}"],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
                );
                if ($process === false) {
                    throw new RuntimeException('Не удалось запустить процесс проверки');
                }
                fclose($pipes[0]);
                $workers[] = [$process, $pipes[1], $pipes[2]];
            }
        } finally {
            foreach ($workers as [$process, $stdout, $stderr]) {
                $output = stream_get_contents($stdout) . stream_get_contents($stderr);
                fclose($stdout);
                fclose($stderr);
                if (proc_close($process) !== 0) {
                    $errors[] = $output;
                }
            }
        }
        if ($errors) {
            throw new RuntimeException(implode('; ', $errors));
        }
        $data = json_decode(file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
        for ($index = 0; $index < 6; $index++) {
            if (!isset($data["worker:{$index}"])) {
                throw new RuntimeException("Потеряна запись процесса {$index}");
            }
            expectPrice((float) $data["worker:{$index}"]['value'], 1.0);
        }
    });
} finally {
    unlink($cacheFile);
}

printf("\nПроверок: %d. Ошибок: %d.\n", $checks, $failures);
exit($failures === 0 ? 0 : 1);
