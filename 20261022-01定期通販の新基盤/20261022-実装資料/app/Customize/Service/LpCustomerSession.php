<?php

declare(strict_types=1);

namespace Customize\Service;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * LP購入経路の「2系 `$_SESSION['customer']`」に当たるセッションキーをまとめて扱う。
 *
 * 2系はログイン購入 (ajax ログイン `doLoginForBrand*`)・ゲスト購入確定後の
 * `setLoginOffline()` (`LC_Page_Store_Confirm.php` L700)・確定顧客の上書き (同 L726-727) の
 * すべてを **`$_SESSION['customer']` という1つの変数**で持ち、`SC_Customer::EndSession()`
 * (`SC_Customer.php` L205-223) でまとめて消す。
 *
 * 4系はこれを2つのキーに分けて持っている:
 * - `lp_login_customer_id` / `lp_login_name` … LPカート・チャットのログイン
 *   (`StoreCartService::ajaxLogin()` / `ChatScenarioService::doAutoLogin()` /
 *    `StoreEntryController::reloginForAmazonPay()`)
 * - `guest_purchase_customer_id` … 受注確定で決まった顧客 (2系 L700 / L726-727、
 *   `StoreConfirmService::resolveCustomer()`、Issue #2232)
 *
 * ★マイページ等の Symfony Security の実ログインはここでは扱わない (2系の `EndSession()` は
 *   マイページのログインも同じ変数なので一緒に切れるが、4系は購入経路のキーだけを消す)。
 * ★`order_login` は消さない。2系 `EndSession()` は `$_SESSION['order_login']` に触れず、
 *   消すのは LP 入場 (`LC_Page_Store.php` L87) と確認画面「戻る」(Confirm L619) だけ。
 *   4系のログイン購入判定は `order_login && lp_login_customer_id > 0` なので、
 *   `lp_login_customer_id` を消せば2系と同じくログイン購入ではなくなる。
 */
final class LpCustomerSession
{
    public const GUEST_PURCHASE_KEY = 'guest_purchase_customer_id';
    public const LP_LOGIN_KEY       = 'lp_login_customer_id';
    public const LP_LOGIN_NAME_KEY  = 'lp_login_name';

    /**
     * 2系 `$_SESSION['customer']['customer_id']` に当たる顧客ID (無ければ 0)。
     *
     * 受注確定のキーを優先する。2系も L726-727 で確定顧客に上書きするため、
     * ログイン後に購入が確定したセッションでは確定顧客が残っている。
     */
    public static function customerId(SessionInterface $session): int
    {
        $guest = (int) $session->get(self::GUEST_PURCHASE_KEY, 0);
        if ($guest > 0) {
            return $guest;
        }

        return (int) $session->get(self::LP_LOGIN_KEY, 0);
    }

    /**
     * 2系 `$objCustomer->EndSession()` (`SC_Customer.php` L205-223) の
     * `unset($_SESSION['customer'])` に当たる。
     *
     * 同メソッドの残り (`unsetAllShippingTemp(true)` / `destroyToken()` / `unsetUniqId()`) は
     * 4系では `StoreConfirmService::finalizeOrder()` が受注確定時に `shipping` / `site_uniqid` を
     * 破棄済みのため、ここでは扱わない。
     */
    public static function end(SessionInterface $session): void
    {
        $session->remove(self::GUEST_PURCHASE_KEY);
        $session->remove(self::LP_LOGIN_KEY);
        $session->remove(self::LP_LOGIN_NAME_KEY);
        // AmazonPay 与信後に終える印 (`StoreConfirmService::finalizeOrder()`)。終えたら不要
        $session->remove('amazonpay_login_offline');
    }
}
