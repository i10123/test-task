<?php
declare(strict_types=1);

final class FileCache
{
    public function __construct(private readonly string $file, private readonly DateTimeImmutable $now) {}

    public function remember(string $key, int $ttlSeconds, callable $compute): float
    {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('Срок хранения кеша должен быть положительным.');
        }
        $stream = @fopen($this->file, 'c+');
        if ($stream === false) {
            throw new RuntimeException("Не удалось открыть файл кеша: {$this->file}");
        }

        try {
            if (!flock($stream, LOCK_EX)) {
                throw new RuntimeException("Не удалось заблокировать файл кеша: {$this->file}");
            }
            $json = stream_get_contents($stream);
            if ($json === false) {
                throw new RuntimeException("Не удалось прочитать файл кеша: {$this->file}");
            }
            $data = $json === '' ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                throw new RuntimeException("Некорректный формат кеша: {$this->file}");
            }

            if (isset($data[$key])) {
                $entry = $data[$key];
                if (!is_array($entry) || !isset($entry['value'], $entry['expires_at'])
                    || !is_numeric($entry['value']) || !is_finite((float) $entry['value'])
                    || $entry['value'] < 0 || !is_string($entry['expires_at'])) {
                    throw new RuntimeException("Некорректная запись кеша: {$key}");
                }
                $expires = DateTimeImmutable::createFromFormat(DATE_ATOM, $entry['expires_at']);
                if ($expires === false || $expires->format(DATE_ATOM) !== $entry['expires_at']) {
                    throw new RuntimeException("Некорректный срок записи кеша: {$key}");
                }
                if ($expires > $this->now) {
                    return (float) $entry['value'];
                }
            }

            $value = $compute();
            if (!is_finite($value) || $value < 0) {
                throw new RuntimeException('В кеш можно сохранить только корректную неотрицательную цену.');
            }
            $data[$key] = [
                'value' => $value,
                'expires_at' => $this->now->modify("+{$ttlSeconds} seconds")->format(DATE_ATOM),
                'stored_at' => $this->now->format(DATE_ATOM),
            ];
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (!rewind($stream) || !ftruncate($stream, 0)
                || fwrite($stream, $json) !== strlen($json) || !fflush($stream)) {
                throw new RuntimeException("Не удалось записать файл кеша: {$this->file}");
            }

            return $value;
        } catch (JsonException $error) {
            throw new RuntimeException("Некорректный JSON кеша: {$this->file}", 0, $error);
        } finally {
            fclose($stream);
        }
    }
}

final class PriceResolver
{
    public function __construct(
        private readonly array $prices,
        private readonly array $clients,
        private readonly array $products,
        private readonly array $rules,
        private readonly FileCache $cache,
        private readonly DateTimeImmutable $now,
    ) {}

    public function basePrice(string $clientId, string $sku): float
    {
        $this->validateRequest($clientId, $sku);
        $type = $this->clients[$clientId]['price_type'];

        if (!isset($this->prices[$sku][$type])) {
            throw new RuntimeException("Для товара {$sku} отсутствует цена типа {$type}. Проверьте выгрузку 1С.");
        }

        $price = $this->prices[$sku][$type];
        if (!is_numeric($price) || !is_finite((float) $price) || $price < 0) {
            throw new RuntimeException("Некорректная цена товара: {$sku}");
        }

        return (float) $price;
    }

    public function discountPercent(string $clientId, string $sku, int $qty): float
    {
        $this->validateRequest($clientId, $sku, $qty);
        $percent = $this->percentage($this->clients[$clientId]['personal_discount']);

        foreach ($this->rules as $rule) {
            if (!is_array($rule)) {
                throw new RuntimeException('Правило скидки должно быть объектом.');
            }
            if (!$this->isActive($rule)) {
                continue;
            }

            $applies = match ($rule['type'] ?? null) {
                'volume' => $qty >= $this->minimumQuantity($rule['min_qty'] ?? null),
                'promo' => isset($rule['category']) && $rule['category'] === $this->products[$sku]['category'],
                default => throw new RuntimeException('Неизвестный тип правила скидки.'),
            };
            if ($applies) {
                $percent = max($percent, $this->percentage($rule['percent'] ?? null));
            }
        }

        return $percent;
    }

    private function isActive(array $rule): bool
    {
        $timezone = $this->now->getTimezone();
        $fromText = $rule['valid_from'] ?? '';
        $toText = $rule['valid_to'] ?? '';
        if (!is_string($fromText) || !is_string($toText)) {
            throw new RuntimeException('Даты правила скидки должны быть строками.');
        }
        $from = DateTimeImmutable::createFromFormat('!d.m.Y', $fromText, $timezone);
        $to = DateTimeImmutable::createFromFormat('!d.m.Y', $toText, $timezone);
        if ($from === false || $to === false
            || $from->format('d.m.Y') !== $fromText
            || $to->format('d.m.Y') !== $toText || $from > $to) {
            throw new RuntimeException('Некорректный период правила скидки.');
        }
        $today = $this->now->setTime(0, 0);

        return $today >= $from && $today <= $to;
    }

    public function price(string $clientId, string $sku, int $qty = 1): float
    {
        $this->validateRequest($clientId, $sku, $qty);
        $version = hash('sha256', json_encode([
            $this->clients[$clientId], $this->prices[$sku] ?? [],
            $this->products[$sku], $this->rules, $this->now->format('Y-m-d'),
        ], JSON_THROW_ON_ERROR));

        return $this->cache->remember("price:v2:{$clientId}:{$sku}:{$qty}:{$version}", 86400, function () use ($clientId, $sku, $qty): float {
            $base = $this->basePrice($clientId, $sku);
            $discount = $this->discountPercent($clientId, $sku, $qty);

            return round($base * (100 - $discount) / 100, 2);
        });
    }

    private function validateRequest(string $clientId, string $sku, int $qty = 1): void
    {
        if (!isset($this->clients[$clientId])) {
            throw new InvalidArgumentException("Неизвестный клиент: {$clientId}");
        }
        if (!isset($this->products[$sku])) {
            throw new InvalidArgumentException("Неизвестный товар: {$sku}");
        }
        if ($qty < 1) {
            throw new InvalidArgumentException('Количество должно быть положительным целым числом.');
        }
    }

    private function percentage(mixed $value): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || $value < 0 || $value > 100) {
            throw new RuntimeException('Скидка должна быть числом от 0 до 100.');
        }

        return (float) $value;
    }

    private function minimumQuantity(mixed $value): int
    {
        $quantity = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($quantity === false) {
            throw new RuntimeException('Порог объёмной скидки должен быть положительным целым числом.');
        }

        return $quantity;
    }
}
