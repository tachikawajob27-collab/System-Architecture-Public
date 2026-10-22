<?php

namespace Customize\Service\Order;

use Eccube\Entity\Shipping;

/**
 * 受注編集で「お届け日」(`dtb_shipping.shipping_date`) が**フォーム bind 前**にいくつだったかを、
 * 同一リクエスト内で覚えておく (Issue #3681)。
 *
 * 2系 `mode=change_shipping_deliv` (`LC_Page_Admin_Order_Edit_Ex.php` L1721-1723) は更新の前に
 * `$bf_shipping_date = $arrBeforeShipping[0]['shipping_date']` を取り、保存後に「変更前後の差分日数」で
 * `dtb_teiki.next_shipping_date` を動かす。4系はコアの `EditController` がフォーム bind → flush →
 * `ADMIN_ORDER_EDIT_INDEX_COMPLETE` の順に進み、COMPLETE の時点では Shipping エンティティは既に
 * 新しい値になっているので、bind 前の値を別に取っておく必要がある。
 *
 * - 書く側: `ShippingDateFieldExtension` の POST_SET_DATA (= フォームにエンティティが載った直後、submit の前)
 * - 読む側: `OrderEditShippingDateTeikiSyncSubscriber` (COMPLETE)
 *
 * リクエストスコープの状態を持つが、Symfony のサービスは 1 リクエスト 1 インスタンスなので
 * 同一リクエスト内の受け渡しにだけ使う (`OrderItemCourseTypeExtension` の `$originalStates` と同じ発想)。
 * キーはエンティティのオブジェクト同一性 (`spl_object_id`。GC で id が再利用されないよう参照も保持する)。
 */
class ShippingDateChangeTracker
{
    /** @var array<int, ?\DateTimeImmutable> spl_object_id(Shipping) => bind 前の値 */
    private array $before = [];

    /** @var array<int, Shipping> 同一性を保つための参照 (GC で object id が再利用されないよう保持) */
    private array $keep = [];

    public function remember(Shipping $Shipping): void
    {
        // ★clone しておく。Doctrine は同じ DateTime インスタンスを差し替えず in-place で変えることがある
        //   (コアの datetimetz 型は flush 時にタイムゾーンを UTC へ書き換える)
        $date = $Shipping->getShippingDate();
        $this->before[spl_object_id($Shipping)] = $date !== null ? \DateTimeImmutable::createFromInterface($date) : null;
        $this->keep[spl_object_id($Shipping)] = $Shipping;
    }

    public function has(Shipping $Shipping): bool
    {
        $id = spl_object_id($Shipping);

        // `$keep` の同一インスタンスであることまで見る (object id の再利用で別インスタンスと取り違えない)
        return array_key_exists($id, $this->before) && ($this->keep[$id] ?? null) === $Shipping;
    }

    public function before(Shipping $Shipping): ?\DateTimeInterface
    {
        return $this->before[spl_object_id($Shipping)] ?? null;
    }
}
