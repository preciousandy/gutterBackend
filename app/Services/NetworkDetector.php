<?php

namespace App\Services;

class NetworkDetector
{
    public const PREFIXES = [
        'MTN' => [
            '0803', '0806', '0703', '0706', '0813', '0816', '0810', '0814',
            '0903', '0906', '0913', '0916', '0704', '07025', '07026',
        ],
        'Glo' => [
            '0805', '0807', '0705', '0815', '0811', '0905', '0915',
        ],
        'Airtel' => [
            '0802', '0808', '0708', '0812', '0701', '0902', '0901', '0904',
            '0907', '0912', '0911',
        ],
        '9mobile' => [
            '0809', '0817', '0818', '0909', '0908',
        ],
    ];

    /**
     * Normalize Nigerian phone number to standard 11-digit 080... format.
     */
    public static function normalize(string $phone): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($clean, '234') && strlen($clean) === 13) {
            $clean = '0' . substr($clean, 3);
        } elseif (strlen($clean) === 10) {
            $clean = '0' . $clean;
        }

        return $clean;
    }

    /**
     * Detect network operator from phone number prefix.
     */
    public static function detect(string $phone): ?string
    {
        $normalized = self::normalize($phone);

        // Allow sandbox universal test number (0801...)
        if (str_starts_with($normalized, '0801')) {
            return null;
        }

        // Check 5-digit prefixes (e.g. 07025)
        $prefix5 = substr($normalized, 0, 5);
        foreach (self::PREFIXES as $network => $prefixes) {
            if (in_array($prefix5, $prefixes, true)) {
                return $network;
            }
        }

        // Check 4-digit prefixes (e.g. 0803, 0807)
        $prefix4 = substr($normalized, 0, 4);
        foreach (self::PREFIXES as $network => $prefixes) {
            if (in_array($prefix4, $prefixes, true)) {
                return $network;
            }
        }

        return null;
    }

    /**
     * Validate if the phone number matches the selected network operator.
     * Returns an error message if mismatched, or null if valid.
     */
    public static function validateNetwork(string $selectedNetwork, string $phone): ?string
    {
        $detected = self::detect($phone);

        if ($detected !== null && strcasecmp($detected, $selectedNetwork) !== 0) {
            return "{$phone} is a {$detected} number, but you selected {$selectedNetwork}. Please select {$detected} or enter an {$selectedNetwork} number.";
        }

        return null;
    }
}
