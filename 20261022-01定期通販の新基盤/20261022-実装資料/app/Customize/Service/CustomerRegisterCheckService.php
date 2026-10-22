<?php

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Eccube\Entity\Customer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * 会員登録時の重複チェック
 * (2系 `SC_Helper_Customer_Ex::sfCheckRegisterUserFromEmail_Brand` L85-120 /
 *  `sfCheckRegisterUserFromTel_Brand` L45-75 /
 *  `SC_Helper_Customer::sfCheckRegisterUserFromEmail` L176-213 の移植)。
 *
 * 2系はこれらを SC_Helper_Customer(_Ex) の兄弟メソッドとして持ち、chat (SC_Helper_Chat_Ex) と
 * cart (SC_CheckError 経由) の双方から呼ばれる。4系でも同じ2系関数を複数サービスから使うため、
 * 実装が分岐しないよう本サービスに集約する。
 *
 * ★呼び出し元によって戻り値の解釈が異なる点に注意 (2系の実挙動):
 *   - chat (SC_Helper_Chat_Ex L869): `if ($ret > 0)` で 1/2/3 を区別せず同一メッセージ
 *   - cart (SC_CheckError::CHECK_REGIST_CUSTOMER_EMAIL L1678-1688): case 1 と case 2 で別メッセージ、
 *     **3 は default に落ちてエラーにしない**
 *   - chat の lfSaveContact (L1074): `$mail_ret == 0` のみ通す (1/2/3 はいずれも登録スキップ)
 *   そのため戻り値をそのまま返し、解釈は呼び出し元に委ねる。
 */
class CustomerRegisterCheckService
{
    /** 退会後の再登録制限時間(時間) (2系 ENTRY_LIMIT_HOUR、mtb_constants.php L304 で 1) */
    private const ENTRY_LIMIT_HOUR = 1;

    /** 4系 mtb_customer_status: 3 = 退会 (2系の del_flg=1 に相当) */
    private const CUSTOMER_STATUS_WITHDRAWN = 3;

    /** 2系の戻り値 3 = 自分のアドレス (ログイン中に自身の登録済みメールを入力した場合) */
    public const RET_OWN_EMAIL = 3;

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {}

    /**
     * メールアドレスの会員重複チェック
     * (2系 `SC_Helper_Customer_Ex::sfCheckRegisterUserFromEmail_Brand` L85-120)。
     *
     * ★`$email` は2系同様、大文字小文字を変換せずそのまま比較すること (Issue #670)。
     *   2系 SC_Helper_Chat_Ex L868 / SC_CheckError L1677 のいずれも無変換で渡しており、
     *   実データに大文字を含むメール (email <> lower(email)) が存在するため、入力側だけ
     *   小文字化すると重複を検出できなくなる。
     *   (件数は2系が本番稼働中で増え続けるため確定値ではない。2026-07-15 時点で 1,753件・
     *    全て del_flg=0。docs/parity の 1,746件 は当時のスナップショット時点の値)
     *   (2系 lfSaveContact L1072 のみ strtolower するが、あちらは brand_id を取らない
     *    別関数 `sfCheckRegisterUserFromEmail` の呼び出しであり本メソッドとは別系統。
     *    なお cart は SC_CheckError に渡す手前の LC_Page_Store_Cart::lfCheckError L694 で
     *    `toLower('order_email')` 済みの値を渡してくる。小文字化は呼び出し元の責務)
     *
     * @return int 0:登録可能 1:登録済み 2:再登録制限期間内(退会後 ENTRY_LIMIT_HOUR 時間以内)
     *             3:自分のアドレス (Issue #912)
     */
    public function checkRegisterUserFromEmailBrand(string $email, int $brandId): int
    {
        // 2系 L91-96: ログイン中に自身の登録済みメールを入力した場合は brand_id を問わず 3 を返す
        if ($this->isOwnEmail($email)) {
            return self::RET_OWN_EMAIL;
        }

        return $this->checkDuplicateEmailInBrand($email, $brandId);
    }

    /**
     * 2系 `sfCheckRegisterUserFromEmail_Brand` L97-122 の**検索・判定部だけ**
     * (L91-95 の「3:自分のアドレス」判定を含まない版)。
     *
     * 「自分のアドレスか」の判定に `Security::getUser()` を使えない呼び出し元のために分離している。
     * マイページ「お客様情報変更」({@see \Customize\Controller\Mypage\ChangeController}) では
     * ログイン中の Customer がフォーム適用後の値に書き換わっており、`isOwnEmail()` が
     * 「変更後の入力値」と自分自身を比較して常に一致してしまうため使えない (Issue #1332)。
     * 同コントローラは代わりに DB の現在値と比較する自前の判定を持つ。
     *
     * @return int 0:登録可能 1:登録済み 2:再登録制限期間内(退会後 ENTRY_LIMIT_HOUR 時間以内)
     */
    public function checkDuplicateEmailInBrand(string $email, int $brandId): int
    {
        $row = $this->fetchCandidate(
            'SELECT customer_status_id, update_date FROM dtb_customer'
            . ' WHERE email = ? AND brand_id = ?'
            . ' ORDER BY (customer_status_id = ?) ASC, update_date DESC LIMIT 1',
            [$email, $brandId, self::CUSTOMER_STATUS_WITHDRAWN],
        );

        return $this->judge($row);
    }

    /**
     * メールアドレスの会員重複チェック (brand_id を問わない版)
     * (2系 `SC_Helper_Customer::sfCheckRegisterUserFromEmail` L176-213)。
     *
     * brand版と違い brand_id で絞らないため、他ブランドの会員とも重複判定される。
     * 2系で本関数を使うのは `SC_Helper_Chat_Ex::lfSaveContact` L1073 の1箇所のみ (Issue #911)。
     *
     * ★2系の `email = ? OR email_mobile = ?` のうち `email_mobile` 側は再現しない。
     *   4系 dtb_customer に email_mobile 列が無い (`transform_dtb_customer.sql` の column_drop) が、
     *   2系実データは全 402,180 件で email_mobile が空 (非空 0 件) のため OR 条件は決して真にならず、
     *   `email = ?` 単独と等価。列を移行しなくても判定結果に差は出ない。
     *
     * @return int 0:登録可能 1:登録済み 2:再登録制限期間内(退会後 ENTRY_LIMIT_HOUR 時間以内)
     *             3:自分のアドレス
     */
    public function checkRegisterUserFromEmail(string $email): int
    {
        // 2系 L182-186
        if ($this->isOwnEmail($email)) {
            return self::RET_OWN_EMAIL;
        }

        $row = $this->fetchCandidate(
            'SELECT customer_status_id, update_date FROM dtb_customer'
            . ' WHERE email = ?'
            . ' ORDER BY (customer_status_id = ?) ASC, update_date DESC LIMIT 1',
            [$email, self::CUSTOMER_STATUS_WITHDRAWN],
        );

        return $this->judge($row);
    }

    /**
     * 電話番号の会員重複チェック
     * (2系 `SC_Helper_Customer_Ex::sfCheckRegisterUserFromTel_Brand` L45-75)。
     *
     * ★email 版と違い **ret=3 の判定は無い**。2系の Tel 版 (非brand版 L248-278 も同様) は
     *   `$objCustomer = new SC_Customer_Ex();` を生成するだけで isLoginSuccess を呼んでおらず、
     *   `return 3` は email 版2関数 (SC_Helper_Customer.php L185 / SC_Helper_Customer_Ex.php L94) に
     *   しか存在しない。ここで ret=3 を足すと2系より緩くなるため足さないこと。
     *
     * @return int 0:登録可能 1:登録済み 2:再登録制限期間内(退会後 ENTRY_LIMIT_HOUR 時間以内)
     */
    public function checkRegisterUserFromTelBrand(string $tel, int $brandId): int
    {
        $row = $this->fetchCandidate(
            'SELECT customer_status_id, update_date FROM dtb_customer'
            . ' WHERE phone_number = ? AND brand_id = ?'
            . ' ORDER BY (customer_status_id = ?) ASC, update_date DESC LIMIT 1',
            [$tel, $brandId, self::CUSTOMER_STATUS_WITHDRAWN],
        );

        return $this->judge($row);
    }

    /**
     * ログイン中の会員が自身の登録済みメールアドレスを入力したか
     * (2系 `$objCustomer->isLoginSuccess(true)
     *      && SC_Helper_Customer_Ex::sfCustomerEmailDuplicationCheck($customer_id, $email)`)。
     *
     * 2系の `isLoginSuccess(true)` (SC_Customer L231-253) は「コア顧客セッション
     * (`$_SESSION['customer']`) が張られており、かつセッションのメールが dtb_customer の現在値と
     * 一致する」判定。4系ではこれが Symfony Security のトークンに相当し、user provider が
     * リクエスト毎に DB から会員を再読込するため `getUser()` が Customer を返すことと等価。
     * (引数 true = モバイル用の email_mobile チェックをしない。4系にモバイル判定は無い)
     *
     * ★到達経路: LP カートの ajax ログイン (2系 LC_Page_Store_Cart L317-318) は
     *   `startSession()` と `order_login` を同時にセットするため重複チェック自体がスキップされる
     *   (2系 lfCheckError L715-717 / 4系 StoreCartController::resolveLoginPurchase)。
     *   本判定に到達するのは `order_login` を伴わずにログインした場合 —
     *   マイページログイン (2系 doLoginForBrand/2/3 = 4系 CustomerNameAuthenticator)、
     *   顧客コードログイン (setLoginFromCode)、会員登録直後 (LC_Page_Entry L146 / LC_Page_Regist L148)
     *   のいずれか。
     *
     * 2系 `sfCustomerEmailDuplicationCheck` (SC_Helper_Customer L225-238) は email / email_mobile と
     * **厳密一致 (`===`)** で比較する。email_mobile 側は実データ非空 0 件のため再現不要
     * (`checkRegisterUserFromEmail` の注記参照)。
     */
    private function isOwnEmail(string $email): bool
    {
        $customer = $this->security->getUser();
        if ($customer instanceof Customer) {
            $ownEmail = (string) $customer->getEmail();
        } else {
            // ★LP購入経路の `$_SESSION['customer']` (4系 {@see LpCustomerSession}) も2系では
            //   同じ `isLoginSuccess(true)` が真になる。ゲスト購入確定後の `setLoginOffline()`
            //   (Confirm L700) と LPカートのログインがこれに当たり、同じセッションで2回目の
            //   AmazonPay 購入をすると自分のメールとして 3 が返る (2系 L91-96)。
            //   2系 `isLoginSuccess()` は毎回 dtb_customer を引き直すので、ここも DB の現在値で比べる。
            $session = $this->requestStack->getCurrentRequest()?->hasSession()
                ? $this->requestStack->getSession()
                : null;
            $sessionCustomerId = $session !== null ? LpCustomerSession::customerId($session) : 0;
            if ($sessionCustomerId <= 0) {
                return false;
            }
            $ownEmail = (string) ($this->connection->fetchOne(
                'SELECT email FROM dtb_customer WHERE id = ?',
                [$sessionCustomerId],
            ) ?: '');
        }

        // 2系 L233: strlen($arrResults['email']) >= 1 && $email === $arrResults['email']
        return $ownEmail !== '' && $email === $ownEmail;
    }

    /**
     * 2系は `ORDER BY del_flg` (退会していない行を優先) のみで tie-break が無く、退会済みが
     * 複数件ある場合にどの行の update_date を見るかが不定になる。4系では直近の退会時刻で
     * 再登録制限を判定するのが自然なため `update_date DESC` を追加している (意図的な差異)。
     *
     * @param array<int, mixed> $params
     * @return array<string, mixed>|false
     */
    private function fetchCandidate(string $sql, array $params): array|false
    {
        return $this->connection->fetchAssociative($sql, $params);
    }

    /**
     * 2系 L102-118 の判定部 (email/tel 版で共通)。
     *
     * @param array<string, mixed>|false $row
     */
    private function judge(array|false $row): int
    {
        if ($row === false) {
            return 0;
        }
        if ((int) $row['customer_status_id'] !== self::CUSTOMER_STATUS_WITHDRAWN) {
            // 退会していない会員が存在する = 登録済み
            return 1;
        }

        // 退会した会員である場合、退会からの経過時間で再登録制限を判定する
        $leaveTime   = new \DateTimeImmutable((string) $row['update_date']);
        $passSeconds = (new \DateTimeImmutable())->getTimestamp() - $leaveTime->getTimestamp();
        if ($passSeconds < self::ENTRY_LIMIT_HOUR * 3600) {
            return 2;
        }

        return 0;
    }
}
