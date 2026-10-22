<?php
declare(strict_types=1);
/** 出荷締め時刻の判断部分を抜粋。説明用のクラス名に変更し、周辺の処理は省略。 */
final class ShippingTimeExample
{
    private const RECENT_EXCLUDE_MINUTES = 20;
    public static function createdBefore(\DateTimeImmutable $now, string $borderTime): array
    {
        if ($now->format('Hi') > $borderTime) {
            $hh = (int) substr($borderTime, 0, 2);
            $mm = (int) substr($borderTime, 2, 2);

            return [$now->setTime($hh, $mm)->modify('+1 minute'), true];
        }

        return [$now->modify('-' . self::RECENT_EXCLUDE_MINUTES . ' minutes'), false];
    }
}
