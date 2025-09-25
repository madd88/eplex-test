<?php

/**
 * PurchasePlanner - Оптимальный планировщик закупок товаров
 *
 * Класс решает задачу оптимального выбора поставщиков для закупки заданного количества товара
 * с учетом ограничений на минимальную партию (pack) и доступное количество у каждого поставщика.
 */
class PurchasePlanner
{
    private const INF = PHP_INT_MAX;

    /**
     * Находит оптимальный план закупки товара у поставщиков
     *
     * @param array $offers Массив предложений от поставщиков. Каждое предложение должно содержать:
     *     - id: уникальный идентификатор предложения (int)
     *     - count: количество товара на складе (int > 0)
     *     - price: цена за единицу товара (float > 0)
     *     - pack: минимальная партия закупки (int > 0)
     * @param int $requiredQuantity Требуемое количество товара для закупки
     *
     * @return array Массив элементов вида [id поставщика, количество для закупки]
     *               или пустой массив, если решение не найдено
     *
     * @throws InvalidArgumentException Если входные данные не соответствуют ожидаемому формату
     */
    public function findOptimalPlan(array $offers, int $requiredQuantity): array
    {
        // Валидация входных данных
        if ($requiredQuantity <= 0) {
            throw new InvalidArgumentException('Требуемое количество должно быть положительным числом');
        }

        // Проверка достаточности общего количества товара
        if (!$this->hasSufficientStock($offers, $requiredQuantity)) {
            return [];
        }

        // Фильтрация и сортировка предложений поставщиков
        $filteredOffers = $this->filterAndSortOffers($offers);
        if (empty($filteredOffers)) {
            return [];
        }

        $purchaseUnits = $this->createPurchaseUnits($filteredOffers, $requiredQuantity);

        return $this->calculateOptimalPurchase($purchaseUnits, $requiredQuantity);
    }

    /**
     * Проверяет, достаточно ли общего количества товара у всех поставщиков
     *
     * @param array $offers Массив предложений от поставщиков
     * @param int $requiredQuantity Требуемое количество товара
     *
     * @return bool True если общего количества достаточно, иначе false
     */
    private function hasSufficientStock(array $offers, int $requiredQuantity): bool
    {
        $totalStock = array_sum(array_column($offers, 'count'));

        return $totalStock >= $requiredQuantity;
    }

    /**
     * Фильтрует и сортирует предложения поставщиков
     *
     * Фильтрация включает:
     * - Проверку положительных значений count, price, pack
     * - Проверку наличия хотя бы одной полной партии (pack)
     *
     * Сортировка: по возрастанию цены
     *
     * @param array $offers Исходный массив предложений
     *
     * @return array Отфильтрованный и отсортированный массив предложений
     */
    private function filterAndSortOffers(array $offers): array
    {
        $validOffers = array_filter($offers, function ($offer) {
            return $offer['count'] > 0
                && $offer['price'] > 0
                && $offer['pack'] > 0
                && intdiv($offer['count'], $offer['pack']) > 0;
        });

        usort($validOffers, fn($a, $b) => $a['price'] <=> $b['price']);

        return $validOffers;
    }

    /**
     * Создает "единицы закупки" через бинарное разложение предложений
     *
     * Каждое предложение разбивается на степени двойки (1, 2, 4, 8... партий)
     *
     * @param array $offers Отфильтрованные предложения поставщиков
     * @param int $maxQuantity Максимальное количество товара (для оптимизации)
     *
     * @return array Массив единиц закупки с информацией о поставщике, количестве и стоимости
     */
    private function createPurchaseUnits(array $offers, int $maxQuantity): array
    {
        $units = [];

        foreach ($offers as $offer) {
            $maxBatches = intdiv($offer['count'], $offer['pack']);
            $batchSize = 1;

            while ($maxBatches > 0) {
                $batchesToTake = min($batchSize, $maxBatches);
                $quantity = $batchesToTake * $offer['pack'];

                // Создаем единицу закупки только если она не превышает требуемое количество
                if ($quantity <= $maxQuantity) {
                    $units[] = [
                        'supplierId' => $offer['id'],
                        'quantity'   => $quantity,
                        'cost'       => $quantity * $offer['price'],
                        'unitPrice'  => $offer['price']
                    ];
                }

                $maxBatches -= $batchesToTake;
                $batchSize *= 2;
            }
        }

        return $units;
    }

    /**
     * Вычисляет оптимальный план закупки
     *
     * @param array $purchaseUnits Массив единиц закупки
     * @param int $requiredQuantity Требуемое количество товара
     *
     * @return array Оптимальный план закупки или пустой массив если решение не найдено
     */
    private function calculateOptimalPurchase(array $purchaseUnits, int $requiredQuantity): array
    {
        // Инициализация массива минимальных стоимостей
        $minCost = array_fill(0, $requiredQuantity + 1, self::INF);

        // Массив для отслеживания источников оптимальных решений
        $purchaseSource = array_fill(0, $requiredQuantity + 1, null);

        $minCost[0] = 0;

        $bestCost = self::INF;

        foreach ($purchaseUnits as $index => $unit) {
            if ($bestCost !== self::INF && $unit['unitPrice'] > $bestCost / $requiredQuantity * 1.5) {
                continue;
            }

            for ($qty = $requiredQuantity; $qty >= $unit['quantity']; $qty--) {
                $remainingQty = $qty - $unit['quantity'];

                if ($minCost[$remainingQty] !== self::INF) {
                    $newCost = $minCost[$remainingQty] + $unit['cost'];

                    // Если новая стоимость лучше текущей
                    if ($newCost < $minCost[$qty]) {
                        $minCost[$qty] = $newCost;
                        $purchaseSource[$qty] = [
                            'previousQty' => $remainingQty,
                            'unitIndex'   => $index
                        ];

                        if ($qty === $requiredQuantity) {
                            $bestCost = min($bestCost, $newCost);
                        }
                    }
                }
            }
        }

        return $minCost[$requiredQuantity] === self::INF
            ? []
            : $this->buildPurchasePlan($purchaseSource, $purchaseUnits, $requiredQuantity);
    }

    /**
     * Восстанавливает оптимальный план закупки
     *
     * @param array $purchaseSource Массив источников решений из DP
     * @param array $purchaseUnits Массив единиц закупки
     * @param int $targetQuantity Целевое количество товара
     *
     * @return array План закупки в формате [[id поставщика, количество], ...]
     */
    private function buildPurchasePlan(array $purchaseSource, array $purchaseUnits, int $targetQuantity): array
    {
        $purchaseQuantities = [];
        $currentQuantity = $targetQuantity;

        while ($currentQuantity > 0 && isset($purchaseSource[$currentQuantity])) {
            $source = $purchaseSource[$currentQuantity];
            $unit = $purchaseUnits[$source['unitIndex']];
            $supplierId = $unit['supplierId'];
            $quantity = $unit['quantity'];

            // Суммируем количество для каждого поставщика
            $purchaseQuantities[$supplierId] = ($purchaseQuantities[$supplierId] ?? 0) + $quantity;
            $currentQuantity = $source['previousQty'];
        }

        return array_map(
            fn($supplierId, $quantity) => [$supplierId, $quantity],
            array_keys($purchaseQuantities),
            array_values($purchaseQuantities)
        );
    }
}

/**
 * Функция тестирования планировщика закупок
 */
function testPurchasePlanner(): void
{
    $planner = new PurchasePlanner();

    // Тестовые данные: предложения от поставщиков
    $offers = [
        ['id' => 111, 'count' => 42, 'price' => 9.0, 'pack' => 2],
        ['id' => 222, 'count' => 77, 'price' => 11.0, 'pack' => 10],
        ['id' => 333, 'count' => 103, 'price' => 10.0, 'pack' => 50],
        ['id' => 444, 'count' => 65, 'price' => 12.0, 'pack' => 5]
    ];

    // Тестируем различные сценарии закупки
    $N = 22;
    $result = $planner->findOptimalPlan($offers, $N);

    print_r($result);
}

// Запуск
testPurchasePlanner();
