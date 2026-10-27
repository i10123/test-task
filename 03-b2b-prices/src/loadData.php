<?php
declare(strict_types=1);

function readCsv(string $path, array $requiredColumns): array
{
    $stream = @fopen($path, 'r');
    if ($stream === false) {
        throw new RuntimeException("Не удалось открыть CSV: {$path}");
    }

    try {
        $header = fgetcsv($stream, null, ';', '"', '');
        if ($header === false || array_diff($requiredColumns, $header)
            || count($header) !== count(array_unique($header))) {
            throw new RuntimeException("Некорректный заголовок CSV: {$path}");
        }

        $rows = [];
        $line = 1;
        while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
            $line++;
            if ($row === [null]) {
                continue;
            }
            if (count($row) !== count($header)) {
                throw new RuntimeException("Некорректная строка {$line} в CSV: {$path}");
            }
            $rows[] = array_combine($header, $row);
        }
        if (!feof($stream)) {
            throw new RuntimeException("Не удалось прочитать CSV: {$path}");
        }

        return $rows;
    } finally {
        fclose($stream);
    }
}

function loadPriceData(string $directory): array
{
    $prices = [];
    foreach (readCsv($directory . '/prices.csv', ['sku', 'price_type', 'price']) as $row) {
        $price = filter_var($row['price'], FILTER_VALIDATE_FLOAT);
        if ($row['sku'] === '' || $row['price_type'] === '' || $price === false || !is_finite($price) || $price < 0) {
            throw new RuntimeException('Некорректная цена в prices.csv для товара: ' . $row['sku']);
        }
        if (isset($prices[$row['sku']][$row['price_type']])) {
            throw new RuntimeException('Повторная цена в prices.csv: ' . $row['sku'] . ' / ' . $row['price_type']);
        }
        $prices[$row['sku']][$row['price_type']] = $price;
    }

    $clients = readCsv($directory . '/clients.csv', ['client_id', 'price_type', 'personal_discount']);
    $products = readCsv($directory . '/products.csv', ['sku', 'category']);
    foreach (['client_id' => $clients, 'sku' => $products] as $key => $rows) {
        $ids = array_column($rows, $key);
        if (in_array('', $ids, true) || count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException("Пустой или повторяющийся идентификатор: {$key}");
        }
    }

    $path = $directory . '/discount_rules.json';
    $json = @file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException("Не удалось прочитать правила скидок: {$path}");
    }
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException("Некорректный JSON правил скидок: {$path}", 0, $error);
    }
    if (!is_array($data) || !isset($data['rules']) || !is_array($data['rules']) || !array_is_list($data['rules'])) {
        throw new RuntimeException('В discount_rules.json ожидается список rules.');
    }

    return [
        'prices' => $prices,
        'clients' => array_column($clients, null, 'client_id'),
        'products' => array_column($products, null, 'sku'),
        'rules' => $data['rules'],
    ];
}
