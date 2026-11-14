<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain;

/**
 * How many SMS segments a text needs.
 *
 * A plain "160 characters" check is wrong in practice: one character outside
 * the GSM 03.38 alphabet (Turkish "ş", "ğ", "ı", an emoji, ...) switches the
 * whole message to UCS-2 and the limit drops from 160 to 70 characters.
 * Carriers bill per segment, so the segment count is the limit that matters.
 */
final readonly class SmsSegments
{
    private const string GSM7_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
        .'¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /** Characters from the GSM extension table take two septets each. */
    private const string GSM7_EXTENDED = '^{}\\[~]|€';

    private const int GSM7_SINGLE = 160;

    private const int GSM7_MULTIPART = 153;

    private const int UCS2_SINGLE = 70;

    private const int UCS2_MULTIPART = 67;

    private function __construct(
        public string $encoding,
        public int $units,
        public int $segments,
    ) {}

    public static function for(string $text): self
    {
        $units = self::gsm7Units($text);

        if ($units !== null) {
            return new self(
                'GSM-7',
                $units,
                $units <= self::GSM7_SINGLE ? 1 : (int) ceil($units / self::GSM7_MULTIPART),
            );
        }

        // UCS-2 limits are in UTF-16 code units (an emoji counts as two).
        $units = intdiv(strlen(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);

        return new self(
            'UCS-2',
            $units,
            $units <= self::UCS2_SINGLE ? 1 : (int) ceil($units / self::UCS2_MULTIPART),
        );
    }

    /**
     * Septets needed in GSM-7, or null when the text cannot be encoded in it.
     */
    private static function gsm7Units(string $text): ?int
    {
        $units = 0;

        foreach (mb_str_split($text) as $char) {
            if (str_contains(self::GSM7_BASIC, $char)) {
                $units++;
            } elseif (str_contains(self::GSM7_EXTENDED, $char)) {
                $units += 2;
            } else {
                return null;
            }
        }

        return $units;
    }
}
