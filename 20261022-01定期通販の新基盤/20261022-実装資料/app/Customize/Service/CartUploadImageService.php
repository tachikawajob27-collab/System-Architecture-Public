<?php

declare(strict_types=1);

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * `html/upload/cart/` に置かれた画像ファイルの「他レコードからの参照有無」を判定し、
 * どこからも参照されなくなったファイルだけを削除する (Issue #2740 / #1680 残件).
 *
 * ## なぜ「参照チェック」が要るのか
 *
 * `html/upload/cart/` は **複数機能が共有する1つのディレクトリ**で、専用ディレクトリを持たない:
 *
 * | 参照元 | 書き込む Controller |
 * |---|---|
 * | `dtb_chat_icon.chat_icon`                       | `Chat\ChatIconController` |
 * | `dtb_model.chat_image_1〜5 / confirm_image_1〜3` | `ModelEditController` |
 * | `dtb_remake_setting.banner_img`                 | `RemakeEditController` |
 * | `dtb_image_library.file_name`                   | 移行データのみ (4系の新規保存先は `html/upload/image_library/`) |
 *
 * 2系はこの4つを **1つのディレクトリ** `html/upload/save_image/` (cart サーバ) に保存し、
 * `SC_Utils_Ex::remoteFileSend(mode=1, ...)` で shop サーバの `html/upload/cart/` へ SFTP
 * 転送していた (2026-09-02 本番現物 `/var/www/html/cart/data/class_extends/util_extends/SC_Utils_Ex.php`
 * L77-80 で確認)。4系は1台構成なので `html/upload/cart/` が保存先と公開先を兼ねる。
 *
 * さらに **同名ファイルの共有は例外ではなく多数派**である (2026-09-02 実測、2系 `ai-analysis-db`):
 * `dtb_model` の画像8列に現れる実ファイル名は 48件、参照は 271件 ＝ 平均5.6レコードが同じ
 * ファイル名を指している。ガード無しで unlink すると、あるモデルの画像を差し替えただけで
 * 無関係なモデルの画像が 404 になる (しかも `<img>` が壊れるだけで例外は出ない)。
 *
 * ## 2系の実装 (本番現物で確認、2026-09-02)
 *
 * - `/var/www/html/cart/data/class/pages/admin/affiliate/LC_Page_Admin_Model_Edit.php`
 *   - `lfHasSameImage()` L764-798 … `dtb_model` に同名ファイルが他に在るかを `exists()` で判定
 *   - `lfRegister()` L690-701 (更新分岐) … 「DBに値が在り、フォームの save_ が空」＝取り消し要求
 *     のとき、同名参照が無ければ `SC_Image_Ex::deleteImage()`
 *   - `lfSaveUploadFiles()` L799-822 … 新規アップロードで置き換えられた旧ファイルを同様に削除
 * - `/var/www/html/cart/data/class_extends/page_extends/admin/chat/LC_Page_Admin_Chat_Icon_Input_Ex.php`
 *   - `lfHasSameProductImage()` L406-432 / `lfRegister()` L487-503 / `lfSaveUploadFiles()` L369-390
 *     … 上と同じ構造で対象テーブルが `dtb_chat_icon`
 * - `SC_Image::deleteImage()` は `file_exists()` してから `unlink()` するだけ (L64-69)
 *
 * ★2系の判定は **自テーブル1本だけ**を見る (ModelEdit は `dtb_model`、ChatIcon は
 * `dtb_chat_icon`)。実体ディレクトリは共有なので、これは2系の穴である。
 * 2系実データでのテーブル跨ぎの同名共有は **0件**だったため実害は出ていなかったが
 * (2026-09-02 実測: 上表4テーブルの実ファイル名 77件のうち複数テーブルに現れるものは0件)、
 * 4系では上表の**全参照元を横断**して判定する。判定を広げても起きるのは「消さずに残す」だけで、
 * 消しすぎる方向には振れない。
 *
 * ★`brand_id` では絞らない。2系 `lfHasSameImage()` は `brand_id = $_SESSION['brand_id']` を
 * 付けているが、実体ディレクトリはブランド共有なので他ブランドが参照中のファイルを
 * 消してしまう。ここは意図的に2系より広く見る (同上、消しすぎない方向の差)。
 *
 * ★`del_flg = 0` は2系どおり付ける (論理削除済みレコードは参照とみなさない)。
 * `dtb_remake_setting` には `del_flg` 列が無いので付けない (4系スキーマ実測)。
 *
 * ## 呼び出し規約
 *
 * - **DB更新をコミットした後**に呼ぶこと。参照判定は生きた DB を引くので、更新後に呼べば
 *   「自レコードの新しい値」も自動的に参照としてカウントされ、除外条件を書かずに済む。
 * - 削除に失敗しても例外は投げない。画像がディスクに残るのは軽微だが、保存できないのは重大。
 * - **既存ファイルを一括で消すバッチは用意しない。** 移行済みの孤児 (2系本番実測で
 *   173件中96件 = 55.5%) の掃除は人の判断で行う。
 *
 * ## 一時ディレクトリ経由のアップロード (Issue #3195)
 *
 * 2系は `SC_UploadFile_Ex(IMAGE_TEMP_REALDIR, IMAGE_SAVE_REALDIR)` の2段構えで、
 * `makeTempFile()` は `temp_dir` にしか置かない。`save_dir` へ移すのは登録確定
 * (`mode=complete`) の `lfSaveUploadFiles()` → `SC_Image_Ex::moveTempImage()` だけ。
 * ＝ 登録しなければ公開ディレクトリには1バイトも増えない。
 * {@see self::commitTempFile()} 以下がこの2段構えを4系へ移したもの。
 */
class CartUploadImageService
{
    /** 保存・公開を兼ねるディレクトリ。`ChatIconController` / `ModelEditController` / `RemakeEditController` と同一 */
    public const UPLOAD_DIR = '/html/upload/cart/';

    /**
     * 登録確定前の一時アップロード先 (Issue #3195).
     *
     * 2系 `IMAGE_TEMP_REALDIR` (`data/mtb_constants_init.php` L181 =
     * `HTML_REALDIR . "upload/temp_image/"`) と同じ位置。4系では
     * `eccube_temp_image_dir` (app/config/eccube/packages/eccube.yaml) と
     * asset package `temp_image` (同 framework.yaml) が既に同じパスを指しており、
     * `ScriptEditController` / `ShareEditController` が先行して使っている。
     */
    public const TEMP_DIR = '/html/upload/temp_image/';

    /**
     * `html/upload/cart/` のファイル名を「素のファイル名」で保持している全列。
     *
     * ★ここに列を足し忘れると、その機能の画像が他画面の保存操作で黙って消える。
     *   `html/upload/cart/` を指す Controller を追加したら必ずここにも追加すること。
     *
     * @var array<array{table: string, columns: string[], del_flg: bool}>
     */
    private const REFERENCES = [
        ['table' => 'dtb_chat_icon', 'columns' => ['chat_icon'], 'del_flg' => true],
        ['table' => 'dtb_model', 'columns' => [
            'chat_image_1', 'chat_image_2', 'chat_image_3', 'chat_image_4', 'chat_image_5',
            'confirm_image_1', 'confirm_image_2', 'confirm_image_3',
        ], 'del_flg' => true],
        ['table' => 'dtb_remake_setting', 'columns' => ['banner_img'], 'del_flg' => false],
        ['table' => 'dtb_image_library', 'columns' => ['file_name'], 'del_flg' => true],
    ];

    /**
     * ファイル名を「素の値」ではなく **HTML片の中に埋め込んで**参照している列.
     *
     * 列を1つずつ `=` で突合する {@see self::REFERENCES} では原理的に見つからないため、
     * `LIKE '%<ファイル名>%'` で走査する。
     *
     * 2026-09-02 に2系 `ai-analysis-db` の **text/varchar 全837列**を
     * `LIKE '%upload/cart%'` で総当たりした結果、素の列以外でヒットしたのは
     * `dtb_confirm_block.code` の1件だけだった (もう1件の `mtb_constants.name` は
     * `CART_IMAGE_PATH = ROOT_URLPATH . "upload/cart/"` という定数定義そのもので参照ではない)。
     * 埋め込まれている実ファイル名は `06101014_6666535faefec.jpg` 1件で、4系DBでも同じ。
     * 本番 shop の docroot 側にはファイル実体としての参照は0件 (同日 `grep -rl` 実測)。
     *
     * ★このファイルは `dtb_chat_icon.chat_icon` からも参照されているため現状は
     *   {@see self::REFERENCES} 側で守られるが、アイコン画像を差し替えると参照が外れて
     *   確認ブロックのHTMLだけが壊れる。HTML埋め込みは列突合では見えない
     *   ([[feedback_grep_only_misses_db_stored_html]]) ので明示的に走査する。
     *
     * ★`dtb_confirm_layout_detail.customize_code` は 2026-09-02 時点では 0件 (2系165行 /
     *   4系150行とも `LIKE '%upload/cart%'` ヒット無し) だが、
     *   {@see \Customize\Controller\Admin\Confirm\ConfirmLayoutController::replaceModelImagePlaceholders()}
     *   の docblock どおり **`block_type <> 2` の行では置換後の実画像パスが POST 経由で
     *   DB に焼き付く**経路が既に在る (同 docblock は「customize_code に旧絶対パスが焼き付いた
     *   まま残っている行」があった実績も記録している)。`___MODEL-IMAGE-` プレースホルダを
     *   持つ行は 4系で 18件あるので、先回りして走査対象に入れる。
     *
     * ★この2列には `del_flg = 0` を付けていない ({@see self::REFERENCES} は付ける)。
     *   HTML に焼き付いた参照は論理削除の有無と無関係に配信され得るため、
     *   意図的に非対称にしてある (判定が広い ＝ 消さない方向にしか振れない)。
     *
     * @var array<array{table: string, column: string}>
     */
    private const EMBEDDED_HTML_REFERENCES = [
        ['table' => 'dtb_confirm_block', 'column' => 'code'],
        ['table' => 'dtb_confirm_layout_detail', 'column' => 'customize_code'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    /** 他の Customize Service (ModelService 等) と同じく EntityManager 経由で DBAL を取る */
    private function conn(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * 指定ファイル名が上表のいずれかの列から参照されているか
     * (2系 `lfHasSameImage()` / `lfHasSameProductImage()` 相当。ただし全参照元を横断する).
     */
    public function isReferenced(string $filename): bool
    {
        if ($filename === '') {
            return false;
        }

        foreach (self::REFERENCES as $ref) {
            $conditions = [];
            foreach ($ref['columns'] as $column) {
                $conditions[] = sprintf('%s = :filename', $column);
            }
            $where = '(' . implode(' OR ', $conditions) . ')';
            if ($ref['del_flg']) {
                $where = 'del_flg = 0 AND ' . $where;
            }

            $found = $this->conn()->fetchOne(
                sprintf('SELECT 1 FROM %s WHERE %s LIMIT 1', $ref['table'], $where),
                ['filename' => $filename],
            );
            if ($found !== false) {
                return true;
            }
        }

        foreach (self::EMBEDDED_HTML_REFERENCES as $ref) {
            $found = $this->conn()->fetchOne(
                sprintf('SELECT 1 FROM %s WHERE %s LIKE :pattern LIMIT 1', $ref['table'], $ref['column']),
                // ファイル名は uniqid 由来 (`06101014_6666535faefec.jpg` /
                // `model_68b6....jpg`) で LIKE のワイルドカード `%` `_` を含みうる。
                // `_` は「任意の1文字」なので実害は「参照ありと誤判定＝消さない」方向にしか
                // 振れないが、意図を明示するためエスケープしておく。
                ['pattern' => '%' . addcslashes($filename, '%_\\') . '%'],
            );
            if ($found !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * `dtb_model` の画像8列の現在のDB値を取る (2系 `lfRegister()` L688 の
     * `$arrRet = $this->lfGetModelData($arrList['id'])` に相当).
     *
     * 旧ファイル名は保存前のDBからしか取れない (`ModelEditController::handleImageUpload()` が
     * `$post['save_*']` を新ファイル名で上書きしてしまうため)。列リストを
     * {@see self::REFERENCES} と1箇所に保つため、取得もここに置く。
     *
     * @return array<string, string|null> 列名 => ファイル名。該当レコードが無ければ空配列
     */
    public function currentModelImageFilenames(int $id, int $brandId): array
    {
        if ($id <= 0) {
            return [];
        }

        $row = $this->conn()->fetchAssociative(
            'SELECT chat_image_1, chat_image_2, chat_image_3, chat_image_4, chat_image_5,'
            . ' confirm_image_1, confirm_image_2, confirm_image_3'
            . ' FROM dtb_model WHERE id = :id AND brand_id = :brand_id',
            ['id' => $id, 'brand_id' => $brandId],
        );

        return $row ?: [];
    }

    /**
     * `dtb_remake_setting.banner_img` の現在のDB値を取る (Issue #3205).
     *
     * `RemakeEditController` も `handleImageUpload()` 相当の処理で hidden `save_banner_img` を
     * 新ファイル名に差し替えてから `mode=complete` に持ち込むため、旧ファイル名は
     * {@see self::currentModelImageFilenames()} と同じく保存前のDBからしか取れない。
     *
     * ★`brand_id` で絞る。`RemakeService::findById()` / `save()` と同じスコープで、
     *   別ブランドのリマケ設定の旧ファイル名を掴んで消しに行かないため
     *   (2系 `lfHasSameImage()` L822 も `$_SESSION['brand_id']` を条件に入れている)。
     *
     * @return string|null 該当レコードが無い・新規登録 (`$id <= 0`) なら null
     */
    public function currentRemakeBannerFilename(int $id, int $brandId): ?string
    {
        if ($id <= 0) {
            return null;
        }

        $value = $this->conn()->fetchOne(
            'SELECT banner_img FROM dtb_remake_setting WHERE id = :id AND brand_id = :brand_id',
            ['id' => $id, 'brand_id' => $brandId],
        );

        return ($value === false || $value === null) ? null : (string) $value;
    }

    /**
     * 旧ファイルを「どこからも参照されていないとき **だけ**」削除する
     * (2系 `SC_Image_Ex::deleteImage()` 相当).
     *
     * DB更新をコミットした後に呼ぶこと。削除できなかった場合も例外は投げず false を返す。
     *
     * @return bool 実際に unlink したか
     */
    public function deleteIfUnreferenced(?string $filename): bool
    {
        $safe = $this->sanitizeFilename($filename);
        if ($safe === null) {
            return false;
        }

        if ($this->isReferenced($safe)) {
            return false;
        }

        $path = $this->projectDir . self::UPLOAD_DIR . $safe;
        if (!is_file($path)) {
            return false;
        }

        if (@unlink($path)) {
            return true;
        }

        // 消せなくても保存は成功させる (画像が残るのは軽微、保存できないのは重大) が、
        // 無言で握りつぶすと「消えていない」ことに誰も気付けないのでログには残す。
        $this->logger->warning('[CartUploadImage] 孤児画像の削除に失敗した', ['path' => $path]);

        return false;
    }

    /**
     * 「保存前のDB値」と「保存後に確定した値」を突き合わせ、置き換え・取り消しで
     * 参照が外れた旧ファイルだけを削除する。
     *
     * 2系の削除条件そのまま:
     *   - `lfSaveUploadFiles()` … 新しいファイルで置き換えられた旧ファイル
     *   - `lfRegister()` 更新分岐 … 取り消し要求 (フォーム値が空) で外れた旧ファイル
     * どちらも「旧値が空でなく、確定値と異なる」に集約できる。
     *
     * @param array<string, string|null> $before 列名 => 保存前のDB値
     * @param array<string, string|null> $after  列名 => 保存後にその列が指す値 (フォーム確定値)
     */
    public function deleteReplacedFiles(array $before, array $after): void
    {
        foreach ($before as $column => $old) {
            if ($old === null || $old === '') {
                continue;
            }
            if (($after[$column] ?? '') === $old) {
                continue;
            }
            $this->deleteIfUnreferenced($old);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // 一時ディレクトリ (Issue #3195)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * まだ登録確定していない (一時ディレクトリにしか実体が無い) ファイルか.
     *
     * 2系は hidden `temp_<key>` / `save_<key>` の2値でこれを区別している
     * (`SC_UploadFile::getHiddenFileList()` / `setHiddenFileList()`)。4系の
     * ChatIcon / ModelEdit / Remake は画面ごとに hidden の持ち方が違い、値も1本しか
     * 持っていないため、**実体の在り処**で判定する。ファイル名は `uniqid(..., true)`
     * 由来で衝突しないので、両方に在る (＝確定済み) 場合は確定側を優先する。
     */
    public function isPendingTempFile(?string $filename): bool
    {
        $safe = $this->sanitizeFilename($filename);
        if ($safe === null) {
            return false;
        }

        return is_file($this->projectDir . self::TEMP_DIR . $safe)
            && !is_file($this->projectDir . self::UPLOAD_DIR . $safe);
    }

    /**
     * プレビュー表示に使うベースURL (2系 `SC_UploadFile::getFormFileList()` 相当).
     *
     * 2系も「一時ファイルがあれば temp_dir 側、無ければ save_dir 側」を返している
     * (`getFormFileList()` L341-359)。戻り値はそのまま `<img src>` の前半に使う。
     */
    public function imageBaseUrl(?string $filename): string
    {
        return $this->isPendingTempFile($filename) ? self::TEMP_DIR : self::UPLOAD_DIR;
    }

    /**
     * 一時ディレクトリのファイルを公開ディレクトリへ移す
     * (2系 `SC_Image::moveTempImage()` L49-61 / `lfSaveUploadFiles()` 相当).
     *
     * **DB を保存した後に呼ぶこと** (2系も `lfRegister()` → `lfSaveUploadFiles()` の順)。
     * 一時ファイルが無い場合 (＝ 既に確定済みの値がそのまま再送されただけ) は何もしない。
     * 2系同様、ファイル名は変えない。
     *
     * @return bool 実際に移動したか
     */
    public function commitTempFile(?string $filename): bool
    {
        $safe = $this->sanitizeFilename($filename);
        if ($safe === null) {
            return false;
        }

        $from = $this->projectDir . self::TEMP_DIR . $safe;
        if (!is_file($from)) {
            return false;
        }

        $dir = $this->projectDir . self::UPLOAD_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $to = $dir . $safe;

        if (@rename($from, $to)) {
            return true;
        }
        // 2系 `moveTempImage()` は copy → 成功時のみ unlink。rename が使えない
        // (別デバイス等) 場合のフォールバックとして同じ手順を踏む。
        if (@copy($from, $to)) {
            @unlink($from);

            return true;
        }

        // 移動できなくても保存自体は成功扱いにする (2系も moveTempImage の失敗を
        // ログに落とすだけ) が、無言だと「画像だけ表示されない」原因が追えないので残す。
        $this->logger->warning('[CartUploadImage] 一時画像の確定移動に失敗した', ['from' => $from, 'to' => $to]);

        return false;
    }

    /**
     * 複数キー分をまとめて確定する ({@see self::commitTempFile()}).
     *
     * @param array<string, string|null> $filenames 列名 => 確定させるファイル名
     */
    public function commitTempFiles(array $filenames): void
    {
        foreach ($filenames as $filename) {
            $this->commitTempFile($filename);
        }
    }

    /**
     * 一時ディレクトリのファイルを削除する (2系 `SC_UploadFile::deleteFile()` L184-199 =
     * `SC_Image::deleteImage($temp_file, $temp_dir)` 相当).
     *
     * 差し替え・取り消しで不要になった**未確定**ファイル専用。公開ディレクトリ側には
     * 一切触れない (確定済みファイルの削除は {@see self::deleteIfUnreferenced()})。
     *
     * @return bool 実際に unlink したか
     */
    public function deleteTempFile(?string $filename): bool
    {
        $safe = $this->sanitizeFilename($filename);
        if ($safe === null) {
            return false;
        }

        $path = $this->projectDir . self::TEMP_DIR . $safe;
        if (!is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * ディレクトリ外への到達を防ぐ。DB由来・POST由来を問わず素のファイル名以外は触らない。
     *
     * ★`basename()` だけに頼らない: PHP の `basename()` は **実行OSの区切り文字しか見ない**ため、
     *   Linux では `..\foo.jpg` がそのまま返り `$safe === $filename` を通過してしまう
     *   (この環境では `<dir>..\foo.jpg` という実在しないファイル名になるだけで実害は無いが、
     *   ガードとしては効いていない)。区切り文字とドット始まりを明示的に弾く。
     *
     * @return string|null 安全なファイル名。触ってはいけない値なら null
     */
    private function sanitizeFilename(?string $filename): ?string
    {
        if ($filename === null || $filename === '') {
            return null;
        }

        $safe = basename($filename);
        if ($safe === '' || $safe !== $filename
            || str_contains($filename, '/') || str_contains($filename, '\\')
            || str_starts_with($filename, '.')
        ) {
            return null;
        }

        return $safe;
    }
}
