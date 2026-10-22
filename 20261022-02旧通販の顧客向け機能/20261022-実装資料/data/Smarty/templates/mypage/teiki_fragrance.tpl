<!--{*
 * 【マイページ】定期情報確認・変更(香りの変更)
 *}-->


<div class="mypage_main" style="<!--{if $smarty.session.category_group_id == 2}-->--theme-color: #5F93CC; --theme-color-hover: #4a80b8; --theme-color-bg: #f0f5fa; --theme-color-shadow: rgba(95, 147, 204, 0.2);<!--{else}-->--theme-color: #cd6872; --theme-color-hover: #b15861; --theme-color-bg: #fdf5f6; --theme-color-shadow: rgba(205, 104, 114, 0.2);<!--{/if}-->">
	<div class="navi_path">
		<ul>
			<li><a href="./index.php?login=success" rel="external">マイページ</a></li>
			<li><a href="./teiki.php" rel="external">定期情報を確認/変更する</a></li>
			<li><!--{$tpl_subtitle}--></li>
		</ul>
	</div>

	<div class="mypage_contents">
		<div class="menu_title">
			<p class="title_body"><!--{$tpl_subtitle}--></p>
		</div>

		<form name="form1" action="./teiki_fragrance.php" id="form1" method="post">
			<input type="hidden" name="<!--{$smarty.const.TRANSACTION_ID_NAME}-->" value="<!--{$transactionid}-->" />
			<input type="hidden" name="mode" value="update">
			<input type="hidden" name="teiki_id" value="<!--{$teiki_id}-->">
			<input type="hidden" name="teiki_detail_id" value="<!--{$teiki_detail_id}-->">
            <!--{if $smarty.get.from_top == 1 || $smarty.post.from_top == 1}-->
            <input type="hidden" name="from_top" value="1">
            <!--{/if}-->
            
            <!--{if $arrErr.scent}-->
            <div class="error_message_area">
                <p class="attention"><span class="material-icons" style="vertical-align: middle; font-size: 18px;">error</span> <!--{$arrErr.scent|h}--></p>
            </div>
            <!--{/if}-->

			<div class="fragrance_selection_area">
                <div class="current_info_section">
                    <p class="section_title">現在の設定内容</p>
                    <div class="current_settings_box">
                        <table class="current_settings_table">
                            <!--{foreach from=$arrTeikiProductOption item=option}-->
                            <tr>
                                <th>香り</th>
                                <td><!--{$option.option_detail_name|h}--></td>
                            </tr>
                            <!--{/foreach}-->
                            <tr>
                                <th>合計数量</th>
                                <td><!--{$tpl_arrTeikiData.quantity}--> 個</td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!--{if $smarty.get.from_top == 1}-->
                    <input type="hidden" id="teiki_quantity" name="teiki_quantity" value="<!--{$tpl_arrTeikiData.quantity}-->">
                    <!--{if $tpl_arrTeikiData.quantity > 1}-->
                    <div class="quantity_section" style="padding: 15px; margin-bottom: 20px; text-align: center;">
                        <p class="section_title" style="margin-bottom: 0;">※ <!--{$tpl_arrTeikiData.quantity}-->個分の香りを選択してください。</p>
                    </div>
                    <!--{/if}-->
                <!--{else}-->
                    <div class="quantity_section">
                        <p class="section_title">変更後の数量を選択</p>
                        <select id="teiki_quantity" name="teiki_quantity" class="select_quantity">
                            <!--{html_options options=$arrTeikiQuantity selected=$tpl_arrTeikiData.quantity}-->
                        </select>
                        <p class="note_text">※数量分まで香りを選択可能です。</p>
                    </div>
                <!--{/if}-->

                <div id="scent_containers">
                    <!--{* ここにJavaScriptで香り選択肢が生成される *}-->
                </div>
            </div>

			<div class="info_btn_area">
				<!--{if $smarty.session.category_group_id != 2}-->
				<p class="note_text" style="font-size: 11px; color: #888; text-align: center; margin-bottom: 10px;">※アクアマリンの限定容器でのお届けは7月中のご注文に限ります。<br />それ以外の期間は通常容器でのお届けとなります。</p>
				<!--{/if}-->
				<button type="submit" class="fragrance_submit_btn">この内容で登録する</button>
			</div>
			<div class="info_btn_area">
                <!--{if $smarty.get.from_top == 1}-->
				<a class="back_btn" href="javascript:history.back();" rel="external"><span class="material-icons">keyboard_double_arrow_left</span>前の画面に戻る</a>
                <!--{else}-->
                <a class="back_btn" href="./teiki.php" rel="external"><span class="material-icons">keyboard_double_arrow_left</span>戻る</a>
                <!--{/if}-->
			</div>
		</form>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const quantitySelect = document.getElementById('teiki_quantity');
    const container = document.getElementById('scent_containers');
    
    // PHP側から渡された香りの選択肢
    const productOptions = <!--{$tpl_product_options_json}-->;
    // 現在の選択内容
    const currentSelections = <!--{$tpl_teiki_options_json}-->;

    function renderScentSelectors() {
        // オプション（香り選択肢）がない場合は、メッセージのみ表示して終了
        if (Object.keys(productOptions).length === 0) {
            container.innerHTML = '<p class="note_text" style="color: #333; font-weight: bold; background: #f9f9f9; padding: 15px; border-radius: 8px;">この商品は、数量の変更のみ承っております。</p>';
            return;
        }

        const quantity = parseInt(quantitySelect.value);
        container.innerHTML = '';

        const optionId = Object.keys(productOptions)[0];
        if (!optionId) return;

        const scents = productOptions[optionId].detail;

        for (let i = 0; i < quantity; i++) {
            const scentSection = document.createElement('div');
            scentSection.className = 'scent_section';
            
            let html = `<p class="section_title">香り ${i + 1} の選択</p>`;
            
            // 定番と限定を分ける
            const standardScents = [];
            const limitedScents = [];
            
            for (let detailId in scents) {
                const scent = { id: detailId, name: scents[detailId].name, is_limited: scents[detailId].is_limited };
                if (scent.is_limited) {
                    limitedScents.push(scent);
                } else {
                    standardScents.push(scent);
                }
            }

            const renderGroup = (title, groupList) => {
                if (groupList.length === 0) return '';
                let groupHtml = `<p class="scent_group_title">${title}</p>`;
                groupHtml += `<div class="scent_list">`;
                
                groupList.forEach(scent => {
                    const detailId = scent.id;
                    const scentName = scent.name;
                    let checked = '';
                    let selectedClass = '';
                    if (currentSelections[i] && currentSelections[i].option_detail_id == detailId) {
                        checked = 'checked';
                        selectedClass = 'selected';
                    } else if (!currentSelections[i] && i === 0 && detailId == Object.keys(scents)[0]) {
                        // 初期状態で何も選択されていない場合のデフォルト（先頭）
                        checked = 'checked';
                        selectedClass = 'selected';
                    }

                    groupHtml += `
                        <label class="scent_item ${selectedClass}">
                            <input type="radio" name="scent[${i}]" value="${detailId}" ${checked}>
                            <span class="scent_name">${scentName}</span>
                        </label>
                    `;
                });
                groupHtml += `</div>`;
                return groupHtml;
            };

            html += renderGroup('限定の香り', limitedScents);
            html += renderGroup('定番の香り', standardScents);
            
            scentSection.innerHTML = html;
            container.appendChild(scentSection);
        }

        // ラジオボタン変更時のハイライト制御
        container.querySelectorAll('.scent_list').forEach(group => {
            group.addEventListener('change', function(e) {
                if (e.target.type === 'radio') {
                    // 同じ香りセクション内の全選択解除
                    const parentSection = e.target.closest('.scent_section');
                    parentSection.querySelectorAll('.scent_item').forEach(item => {
                        item.classList.remove('selected');
                    });
                    e.target.closest('.scent_item').classList.add('selected');
                }
            });
        });
    }

    quantitySelect.addEventListener('change', renderScentSelectors);
    
    // 初期表示時にDBから取得した現在の数量が選択されていることを確実にし、それに基づいてレンダリング
    if (quantitySelect.value) {
        renderScentSelectors();
    } else {
        // 万が一値が空の場合は、tpl_arrTeikiData.quantityを基準にする
        renderScentSelectors();
    }

    // 旧容器ユーザー向けのポップアップ警告
    const form = document.getElementById('form1');
    form.addEventListener('submit', function(e) {
        const isOldContainer = <!--{if $tpl_is_old_container}-->true<!--{else}-->false<!--{/if}-->;
        if (isOldContainer) {
            // 現在の選択内容（DB値）
            const oldScents = Array.isArray(currentSelections) ? currentSelections.map(opt => opt.option_detail_id.toString()).sort() : [];
            
            // 現在画面で選択されている内容
            const formData = new FormData(form);
            const newScents = [];
            for (let [key, value] of formData.entries()) {
                if (key.startsWith('scent[')) {
                    newScents.push(value);
                }
            }
            newScents.sort();

            // 香りのセット、または数量（配列の長さ）に変更があるかチェック
            const isChanged = JSON.stringify(oldScents) !== JSON.stringify(newScents);

            // 新容器専用の香り（5: アクアマリン, 12: ピュアムスク, 13: ジャスミンブラン, 14: ピーチ＆ホワイトティー, 15: キンモクセイ）が含まれているかチェック
            const hasNewOnlyScent = newScents.some(id => ['5', '12', '13', '14', '15'].includes(id));
            
            console.log("Selected IDs (newScents):", newScents);
            console.log("hasNewOnlyScent:", hasNewOnlyScent);

            if (isChanged && hasNewOnlyScent) {
                if (!confirm('新容器に切り替わります。\n新容器は80mlになりますが、変更してもよろしいでしょうか？\n2回目以降、ウォッシュは全て詰め替え用のパウチ(内容量：80ml)となります。')) {
                    e.preventDefault();
                }
            }
        }
    });
});
</script>

<style>
.mypage_main {
    <!--{if $smarty.session.category_group_id == 2}-->
    --theme-color: #5F93CC;
    --theme-color-hover: #4a80b8;
    --theme-color-bg: #f0f5fa;
    --theme-color-shadow: rgba(95, 147, 204, 0.2);
    <!--{else}-->
    --theme-color: #cd6872;
    --theme-color-hover: #b15861;
    --theme-color-bg: #fdf5f6;
    --theme-color-shadow: rgba(205, 104, 114, 0.2);
    <!--{/if}-->
}
@media screen and (max-width: 749px) {
    .mypage_main {
        margin: 0 5px;
    }
}
.navi_path ul li:first-child::after, .navi_path ul li:nth-child(2)::after {
	content: "/";
    color: #959595;
}
.error_message_area {
    margin: 15px;
    padding: 12px;
    background: #fff0f0;
    border: 1px solid #ffcccc;
    border-radius: 8px;
}
.error_message_area .attention {
    color: #cc0000;
    font-weight: bold;
    font-size: 13px;
    margin: 0;
}
.fragrance_selection_area {
    margin: 20px 15px;
}
.current_info_section {
    margin-bottom: 25px;
    padding: 15px;
    background: #fdfdfd;
    border: 1px solid #eee;
    border-radius: 12px;
}
.current_settings_box {
    margin-top: 10px;
}
.current_settings_table {
    width: 100%;
    font-size: 13px;
    color: #555;
    border-collapse: collapse;
}
.current_settings_table th {
    text-align: left;
    width: 80px;
    font-weight: normal;
    color: #888;
    padding: 4px 0;
}
.current_settings_table td {
    font-weight: bold;
    color: #333;
    padding: 4px 0;
}
.quantity_section {
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    margin-bottom: 25px;
}
.select_quantity {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23666' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 16px;
    width: 100%;
    height: 45px;
    padding: 0 35px 0 12px;
    background-color: #fff !important;
    color: #333 !important;
}
.scent_section {
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}
.section_title {
    font-weight: bold;
    font-size: 15px;
    margin-bottom: 15px;
    border-left: 5px solid var(--theme-color);
    padding-left: 12px;
    color: #333;
}
.scent_list {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 20px;
}
.scent_group_title {
    font-size: 13px;
    font-weight: bold;
    color: #888;
    margin: 15px 0 8px 5px;
    display: flex;
    align-items: center;
}
.scent_group_title::before {
    content: "";
    display: inline-block;
    width: 6px;
    height: 6px;
    background: var(--theme-color);
    border-radius: 50%;
    margin-right: 8px;
}
.scent_item {
    display: flex;
    align-items: center;
    padding: 10px 8px;
    border: 2px solid #f0f0f0;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    background: #fdfdfd;
}
.scent_item:active {
    transform: scale(0.98);
}
.scent_item.selected {
    border-color: var(--theme-color);
    background: var(--theme-color-bg);
    box-shadow: 0 2px 8px var(--theme-color-shadow);
}
.scent_item input[type="radio"] {
    margin-right: 8px;
    width: 16px;
    height: 16px;
    accent-color: var(--theme-color);
    flex-shrink: 0;
}
.scent_name {
    font-size: 13px;
    color: #444;
    font-weight: 500;
    line-height: 1.2;
    overflow: hidden;
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}
.scent_item.selected .scent_name {
    color: var(--theme-color);
    font-weight: bold;
}
.note_text {
    font-size: 12px;
    color: #999;
    margin-top: 8px;
}
.info_btn_area {
    margin: 30px 0;
    text-align: center;
}
.fragrance_submit_btn {
    display: inline-block;
    width: 85%;
    padding: 15px 0;
    background: var(--theme-color);
    color: #fff;
    font-weight: bold;
    border-radius: 30px;
    text-decoration: none;
    border: none;
    cursor: pointer;
    font-size: 16px;
    transition: all 0.3s ease;
}
.fragrance_submit_btn:hover {
    background: var(--theme-color-hover);
    transform: translateY(-2px);
}
.back_btn {
    display: inline-flex;
    align-items: center;
    color: #888;
    font-size: 14px;
    text-decoration: none;
}
.back_btn .material-icons {
    font-size: 18px;
    margin-right: 4px;
}
</style>
