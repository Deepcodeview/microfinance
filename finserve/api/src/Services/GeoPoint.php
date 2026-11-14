<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/** Phone location sent with a collection or visit: {latitude, longitude, accuracy, captured_at}. */
final class GeoPoint
{
    /** @return array{latitude: string, longitude: string, location_accuracy_m: ?int, located_at: string}|null */
    public static function fromInput(mixed $input): ?array
    {
        if ($input === null) {
            return null;
        }
        $lat = is_array($input) ? ($input['latitude'] ?? null) : null;
        $lng = is_array($input) ? ($input['longitude'] ?? null) : null;
        $accuracy = is_array($input) ? ($input['accuracy'] ?? null) : null;
        $at = is_array($input) ? ($input['captured_at'] ?? null) : null;
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180 || ((float) $lat === 0.0 && (float) $lng === 0.0)) {
            throw HttpException::validation(['location' => 'Invalid location.']);
        }
        $time = is_string($at) ? strtotime($at) : false;
        // Offline collections sync later, so the reading may be a few days old — but never from the future.
        if ($time === false || $time > time() + 600 || $time < time() - 7 * 86400) {
            throw HttpException::validation(['location' => 'Invalid location time.']);
        }
        return [
            'latitude' => number_format((float) $lat, 6, '.', ''),
            'longitude' => number_format((float) $lng, 6, '.', ''),
            'location_accuracy_m' => is_numeric($accuracy) ? max(0, min(100000, (int) round((float) $accuracy))) : null,
            'located_at' => gmdate('Y-m-d H:i:s', $time),
        ];
    }
}
