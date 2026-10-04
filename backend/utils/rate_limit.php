<?php

declare(strict_types=1);

function enforceRateLimit(
    string $key,
    int $maxRequests,
    int $windowSeconds
): void {
    $safeKey = hash('sha256', $key);

    $file = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'company_card_rate_' . $safeKey . '.json';

    $handle = fopen($file, 'c+');

    if ($handle === false) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }

        $contents = stream_get_contents($handle);

        $data = [
            'started_at' => time(),
            'count' => 0,
        ];

        if ($contents !== '') {
            $decoded = json_decode($contents, true);

            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $now = time();

        if (
            !isset($data['started_at']) ||
            ($now - (int) $data['started_at']) >= $windowSeconds
        ) {
            $data = [
                'started_at' => $now,
                'count' => 0,
            ];
        }

        $data['count']++;

        if ($data['count'] > $maxRequests) {
            jsonResponse([
                'success' => false,
                'message' => 'Too many requests. Please try again later.',
            ], 429);
        }

        rewind($handle);
        ftruncate($handle, 0);

        fwrite(
            $handle,
            json_encode($data, JSON_THROW_ON_ERROR)
        );

        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}