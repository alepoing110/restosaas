<?php

function chatbot_recommend_times(PDO $pdo, array $context, string $date, int $partySize): array
{
    $times = ['12:00', '12:30', '13:00', '13:30', '19:00', '19:30', '20:00', '20:30'];
    $recommendations = [];
    foreach ($times as $time) {
        $table = chatbot_recommend_table($pdo, $context, $date, $time, $partySize);
        if ($table) {
            $recommendations[] = [
                'time' => $time,
                'table' => $table,
            ];
        }
    }
    return array_slice($recommendations, 0, 3);
}

function chatbot_recommend_table(PDO $pdo, array $context, string $date, string $time, int $partySize): ?array
{
    $tables = loadTables($pdo, $context);
    if (!$tables) {
        return null;
    }

    $reservations = chatbot_active_reservations_for_date($pdo, $context, $date);
    $newMinutes = (int)substr($time, 0, 2) * 60 + (int)substr($time, 3, 2);
    $duration = 120;
    $newEnd = $newMinutes + $duration;

    usort($tables, static function (array $a, array $b): int {
        return ((int)($a['seats'] ?? 0)) <=> ((int)($b['seats'] ?? 0));
    });

    foreach ($tables as $table) {
        $seats = (int)($table['seats'] ?? 0);
        if ($seats > 0 && $seats < $partySize) {
            continue;
        }
        $blocked = false;
        foreach ($reservations as $reservation) {
            if (($reservation['table_id'] ?? '') !== ($table['id'] ?? '')) {
                continue;
            }
            $existingMinutes = (int)substr($reservation['reservation_time'], 0, 2) * 60 + (int)substr($reservation['reservation_time'], 3, 2);
            $existingEnd = $existingMinutes + $duration;
            if ($newMinutes < $existingEnd && $newEnd > $existingMinutes) {
                $blocked = true;
                break;
            }
        }
        if (!$blocked) {
            return $table;
        }
    }

    return null;
}

function chatbot_active_reservations_for_date(PDO $pdo, array $context, string $date): array
{
    $stmt = $pdo->prepare("SELECT `id`, `table_id`, `reservation_time`, `party_size`, `customer_name` FROM `reservations` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `reservation_date` = :reservation_date AND `status` IN ('pendiente','confirmada')");
    $stmt->execute([
        'tenant_id' => $context['tenant_id'],
        'branch_id' => $context['branch_id'],
        'reservation_date' => $date,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
