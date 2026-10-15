<?php

namespace Azuriom\Plugin\JangoPay\Support;

use InvalidArgumentException;

final class Assets
{
    /** Format: USDT:tron,USDC:ethereum; confirm combinations in live API before enabling. */
    public static function parse(string $text): array
    {
        $result = [];
        foreach (preg_split('/\s*,\s*/', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $asset) {
            $parts = explode(':', $asset);
            if (count($parts) !== 2 || ! in_array(strtoupper($parts[0]), ['USDT', 'USDC'], true)
                || ! preg_match('/^[a-z0-9_-]{2,32}$/D', $parts[1])) {
                throw new InvalidArgumentException('Invalid asset/network combination.');
            }
            $result[] = strtoupper($parts[0]).':'.$parts[1];
        }
        $result = array_values(array_unique($result));
        if ($result === []) {
            throw new InvalidArgumentException('At least one supported asset/network must be configured.');
        }
        return $result;
    }
}
