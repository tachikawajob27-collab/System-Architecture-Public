<!--{*
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */
*}-->

<style>
	.order_table th {
		text-align:center !important;
	}
</style>

<script type="text/javascript">
<!--
	var send = true;

	function fnSelectCheckSubmit(action){

		var fm = document.form1;

		if (!fm["pdf_order_id[]"]) {
			return false;
		}

		var checkflag = false;
		var max = fm["pdf_order_id[]"].length;

		if (max) {
			for (var i=0; i<max; i++) {
				if(fm["pdf_order_id[]"][i].checked == true){
					checkflag = true;
				}
			}
		} else {
			if(fm["pdf_order_id[]"].checked == true) {
				checkflag = true;
			}
		}

		if(!checkflag){
			alert('チェックボックスが選択されていません');
			return false;
		}

		fnOpenPdfSettingPage(action);
	}

	function fnOpenPdfSettingPage(action){
		var fm = document.form1;
		eccube.openWindow("about:blank", "pdf_input", "620","650");

		// 退避
		tmpTarget = fm.target;
		tmpMode = fm.mode.value;
		tmpAction = fm.action;

		fm.target = "pdf_input";
		fm.mode.value = 'pdf';
		fm.action = action;
		fm.submit();

		// 復元
		fm.target = tmpTarget;
		fm.mode.value = tmpMode;
		fm.action = tmpAction;
	}


	function fnSelectMailCheckSubmit(action){

		var fm = document.form1;

		if (!fm["mail_order_id[]"]) {
			return false;
		}

		var checkflag = false;
		var max = fm["mail_order_id[]"].length;

		if (max) {
			for (var i=0; i<max; i++) {
				if(fm["mail_order_id[]"][i].checked == true){
					checkflag = true;
				}
			}
		} else {
			if(fm["mail_order_id[]"].checked == true) {
				checkflag = true;
			}
		}

		if(!checkflag){
			alert('チェックボックスが選択されていません');
			return false;
		}

		fm.mode.value="mail_select";
		fm.action=action;
		fm.submit();
	}

	// 検索条件隠し項目の表示/非表示確認
	function changeOtherSearchFormDispFlg() {
		var fm = document.search_form;
		if (fm['search_other_order_search_flg'].value > 0) {
			fm['search_other_order_search_flg'].value = '';
		} else {
			fm['search_other_order_search_flg'].value = '1';
		}
	}
	// 検索条件の表示/非表示切替
	function changeToggleSearchForm() {
		$('.other_order_search').toggle();
	}

	// 検索条件の初期化
	function clearForm(){
		$("#search_form").find("input:text").val("");
		$("#search_form").find("select").val("");
		$("#search_form").find("input:checkbox").attr("checked",false);
		$("#search_form").find("option").attr("selected",false);
		$("#search_page_max").val($("#init_page_no").val());
	}
	// ▼ Enterキーで処理を実行させる START
	function keydown(e) {
		if (e.keyCode === 13) {
			if (send) {
				send = false;
				document.search_form.submit();
			}
			return false;
		}
	}
	window.onkeydown = keydown;
	// ▲ Enterキーで処理を実行させる E N D

	// 隠し検索項目表示/非表示切り替え
	//changeOtherSearchFormDispFlg();
	//changeToggleSearchForm();
//-->
</script>
<div id="order" class="contents-main">
		<form name="search_form" id="search_form" method="post" action="?" enctype="multipart/form-data">
		<input type="hidden" name="<!--{$smarty.const.TRANSACTION_ID_NAME}-->" value="<!--{$transactionid}-->" />
		<input type="hidden" name="mode" value="search" />
		<input type="hidden" name="init_page_no" id="init_page_no" value="<!--{$smarty.const.SEARCH_PMAX}-->" />
		<input type="hidden" name="search_other_order_search_flg" value="<!--{$arrForm.search_other_order_search_flg.value}-->" />

		<!--{* テレAI用CSV取込 *}-->
		<table>
			<tr>
				<th>TeleAI用CSVファイル</th>
				<td>
					<!--{if $arrErr.csv_file}-->
						<span class="attention"><!--{$arrErr.csv_file}--></span>
					<!--{/if}-->

					<input type="file" name="csv_file" size="40" />

					&nbsp;&nbsp;

					<a class="btn-action" href="javascript:;"
						onclick="
							if (document.search_form.csv_file.value == '') {
								alert('CSVファイルがセットされていません。');
								return false;
							}
							eccube.fnFormModeSubmit('search_form', 'tele_ai_upload', '', '');
							return false;
						">
						<span class="btn-next">アップロード</span>
					</a>
				</td>
			</tr>
		</table>

		<h2>検索条件設定<input type="button" onclick="clearForm()" value="検索条件を全てクリア"></h2>
		<!--{* 検索条件設定テーブルここから *}-->
		<table>
			<tr>
				<th>注文番号</th>
				<td>
					<!--{assign var=key1 value="search_order_id"}-->
					<span class="attention"><!--{$arrErr[$key1]}--></span>
					<input type="text" name="<!--{$key1}-->" value="<!--{$arrForm[$key1].value|h}-->" maxlength="<!--{$arrForm[$key1].length}-->" style="<!--{$arrErr[$key1]|sfGetErrorColor}-->" size="15" class="box15" />
				</td>
				<th>注文番号（対象顧客の全注文検索）</th>
				<td>
					<!--{assign var=key1 value="search_order_all"}-->
					<span class="attention"><!--{$arrErr[$key1]}--></span>
					<input type="text" name="<!--{$key1}-->" value="<!--{$arrForm[$key1].value|h}-->" maxlength="<!--{$arrForm[$key1].length}-->" style="<!--{$arrErr[$key1]|sfGetErrorColor}-->" size="15" class="box15" />
				</td>
			</tr>
			<tr>
				<th>顧客ID</th>
				<td>
					<!--{assign var=key1 value="search_customer_id"}-->
					<span class="attention"><!--{$arrErr[$key1]}--></span>
					<input type="text" name="<!--{$key1}-->" value="<!--{$arrForm[$key1].value|h}-->" maxlength="<!--{$arrForm[$key1].length}-->" style="<!--{$arrErr[$key1]|sfGetErrorColor}-->" size="15" class="box15" />
				</td>
				<th>顧客名</th>
				<td>
					<!--{assign var=key value="search_order_name"}-->
					<span class="attention"><!--{$arrErr[$key]}--></span>
					<input type="text" name="<!--{$key}-->" value="<!--{$arrForm[$key].value|h}-->" maxlength="<!--{$arrForm[$key].length}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" size="30" class="box30" />
				</td>
			</tr>
			<tr>
				<th>メールアドレス</th>
				<td>
					<!--{assign var=key value="search_order_email"}-->
					<span class="attention"><!--{$arrErr[$key]}--></span>
					<input type="text" name="<!--{$key}-->" value="<!--{$arrForm[$key].value|h}-->" maxlength="<!--{$arrForm[$key].length}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" size="30" class="box30" />
				</td>
				<th>電話番号</th>
				<td>
					<!--{assign var=key value="search_order_tel"}-->
					<span class="attention"><!--{$arrErr[$key]}--></span>
					<input type="text" name="<!--{$key}-->" value="<!--{$arrForm[$key].value|h}-->" maxlength="<!--{$arrForm[$key].length}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" size="30" class="box30" />
				</td>
			</tr>
			<tr>
				<th>都道府県</th>
				<td>
					<!--{assign var=key value="search_order_pref"}-->
					<select name="<!--{$key}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->">
					<option value="">都道府県を選択</option>
					<!--{html_options options=$arrPref selected=$arrForm[$key].value}-->
					</select>
				</td>
				<th>市区町村</th>
				<td>
					<!--{assign var=key value="search_order_addr"}-->
					<span class="attention"><!--{$arrErr[$key]}--></span>
					<input type="text" name="<!--{$key}-->" value="<!--{$arrForm[$key].value|h}-->" maxlength="<!--{$arrForm[$key].length}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" size="30" class="box30" />
				</td>
			</tr>
			<tr>
				<th colspan="4" style="text-align: center">
					<input type="button" onclick="changeOtherSearchFormDispFlg(); changeToggleSearchForm(); return false;" value="検索条件をさらに表示する">
				</th>
			</tr>
			<tr class="other_order_search">
				<th>配送方法</th>
				<td>
					<!--{assign var=key value="search_order_deliv"}-->
					<select name="<!--{$key}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->">
					<option value="">--------</option>
					<!--{html_options options=$arrDeliv selected=$arrForm[$key].value}-->
					</select>
				</td>
				<th>当日出荷依頼</th>
				<td>
					<!--{assign var=key value="search_shipping_today"}-->
					<span class="attention"><!--{$arrErr[$key]}--></span>
					<!--{html_checkboxes name="$key" options=$arrShippingToday selected=$arrForm[$key].value}-->
				</td>
			</tr>
			<tr class="other_order_search">
				<th>対応状況</th>
				<td colspan="3">
				<!--{assign var=key value="search_order_status"}-->
				<span class="attention"><!--{$arrErr[$key]|h}--></span>
				<!--{html_checkboxes name="$key" options=$arrORDERSTATUS selected=$arrForm[$key].value}-->
				</td>
			</tr>
			<tr class="other_order_search">
				<th>支払方法</th>
				<td colspan="3">
				<!--{assign var=key value="search_payment_id"}-->
				<span class="attention"><!--{$arrErr[$key]|h}--></span>
				<!--{html_checkboxes name="$key" options=$arrPayments selected=$arrForm[$key].value}-->
				</td>
			</tr>
			<tr class="other_order_search">
				<th>注文アイコン</th>
				<td colspan="3">
				<!--{assign var=key value="search_order_status_ex"}-->
				<span class="attention"><!--{$arrErr[$key]|h}--></span>
				<!--{html_checkboxes name="$key" options=$arrOrderIcon selected=$arrForm[$key].value}-->
				</td>
			</tr>
			<tr class="other_order_search">
				<th>顧客アイコン</th>
				<td colspan="3">
				<!--{assign var=key value="search_customer_status_ex"}-->
				<span class="attention"><!--{$arrErr[$key]|h}--></span>
				<!--{html_checkboxes name="$key" options=$arrCustomerIcon selected=$arrForm[$key].value}-->
				</td>
			</tr>
			<tr class="other_order_search">
				<th>受注日</th>
				<td colspan="3">
					<!--{assign var=key1 value="search_s_orderdate"}-->
					<!--{assign var=key2 value="search_e_orderdate"}-->
					<!--{if $arrErr[$key1]}--><span class="attention"><!--{$arrErr[$key1]}--></span><!--{/if}-->
					<!--{if $arrErr[$key2]}--><span class="attention"><!--{$arrErr[$key2]}--></span><!--{/if}-->
					<div style="display:flex; align-items: center;">
						<div class="input-group calender-group">
							<input type="text" name="<!--{$key1}-->"  id="<!--{$key1}-->" value="<!--{$arrForm[$key1].value|h}-->"size="15" class="box15" style="<!--{$arrErr[$key1]|sfGetErrorColor}-->" data-input />
							<a class="calender-icon" title="toggle" data-toggle>
								<i class="material-icons">date_range</i>
							</a>
						</div>
						　〜　
						<div class="input-group calender-group">
							<input type="text" name="<!--{$key2}-->"  id="<!--{$key2}-->" value="<!--{$arrForm[$key2].value|h}-->"size="15" class="box15" style="<!--{$arrErr[$key2]|sfGetErrorColor}-->" data-input />
							<a class="calender-icon" title="toggle" data-toggle>
								<i class="material-icons">date_range</i>
							</a>
						</div>
					</div>
				</td>
			</tr>
			<tr class="other_order_search">
				<th>お届け予定日</th>
				<td colspan="3">
					<!--{assign var=key1 value="search_s_shippingdate"}-->
					<!--{assign var=key2 value="search_e_shippingdate"}-->
					<!--{if $arrErr[$key1]}--><span class="attention"><!--{$arrErr[$key1]}--></span><!--{/if}-->
					<!--{if $arrErr[$key2]}--><span class="attention"><!--{$arrErr[$key2]}--></span><!--{/if}-->
					<div style="display:flex; align-items: center;">
						<div class="input-group calender-group">
							<input type="text" name="<!--{$key1}-->"  id="<!--{$key1}-->" value="<!--{$arrForm[$key1].value|h}-->"size="15" class="box15" style="<!--{$arrErr[$key1]|sfGetErrorColor}-->" data-input />
							<a class="calender-icon" title="toggle" data-toggle>
								<i class="material-icons">date_range</i>
							</a>
						</div>
						　〜　
						<div class="input-group calender-group">
							<input type="text" name="<!--{$key2}-->"  id="<!--{$key2}-->" value="<!--{$arrForm[$key2].value|h}-->"size="15" class="box15" style="<!--{$arrErr[$key2]|sfGetErrorColor}-->" data-input />
							<a class="calender-icon" title="toggle" data-toggle>
								<i class="material-icons">date_range</i>
							</a>
						</div>
					</div>
				</td>
			</tr>
			<tr class="other_order_search">
				<th>次回お届け予定日</th>
				<td colspan="3">
					<!--{assign var=key1 value="search_s_nextshippingdate"}-->
					<!--{assign var=key2 value="search_e_nextshippingdate"}-->
					<!--{if $arrErr[$key1]}--><span class="attention"><!--{$arrErr[$key1]}--></span><!--{/if}-->
					<!--{if $arrErr[$key2]}--><span class="attention"><!--{$arrErr[$key2]}--></span><!--{/if}-->
					<div style="display:flex; align-items: center;">
						<div class="input-group calender-group">
							<input type="text" name="<!--{$key1}-->"  id="<!--{$key1}-->" value="<!--{$arrForm[$key1].value|h}-->"size="15" class="box15" style="<!--{$arrErr[$key1]|sfGetErrorColor}-->" data-input />
							<a class="calender-icon" title="toggle" data-toggle>
								<i class="material-icons">date_range</i>
							</a>
						</div>
						　〜　
						<div class="input-group calender-group">
							<input type="text" name="<!--{$key2}-->"  id="<!--{$key2}-->" value="<!--{$arrForm[$key2].value|h}-->"size="15" class="box15" style="<!--{$arrErr[$key2]|sfGetErrorColor}-->" data-input />
							<a class="calender-icon" title="toggle" data-toggle>
								<i class="material-icons">date_range</i>
							</a>
						</div>
					</div>
				</td>
			</tr>
			<tr class="other_order_search">
				<th>購入金額</th>
				<td>
					<!--{assign var=key1 value="search_total1"}-->
					<!--{assign var=key2 value="search_total2"}-->
					<span class="attention"><!--{$arrErr[$key1]}--></span>
					<span class="attention"><!--{$arrErr[$key2]}--></span>
					<input type="text" name="<!--{$key1}-->" value="<!--{$arrForm[$key1].value|h}-->" maxlength="<!--{$arrForm[$key1].length}-->" style="<!--{$arrErr[$key1]|sfGetErrorColor}-->" size="10" class="box10" />
					円 ～
					<input type="text" name="<!--{$key2}-->" value="<!--{$arrForm[$key2].value|h}-->" maxlength="<!--{$arrForm[$key2].length}-->" style="<!--{$arrErr[$key2]|sfGetErrorColor}-->" size="10" class="box10" />
					円
				</td>
				<th>購入商品名</th>
				<td>
					<!--{assign var=key value="search_product_name"}-->
					<!--{if $arrErr[$key]}--><span class="attention"><!--{$arrErr[$key]}--></span><!--{/if}-->
					<input type="text" name="<!--{$key}-->" value="<!--{$arrForm[$key].value|h}-->" maxlength="<!--{$arrForm[$key].length}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" size="30" class="box30" />
				</td>
			</tr>
			<tr class="other_order_search">
				<th>購入回数</th>
				<td colspan="3">
					<!--{assign var=key value="search_order_count"}-->
					<!--{if $arrErr[$key]}--><span class="attention"><!--{$arrErr[$key]}--></span><!--{/if}-->
					<input type="text" name="<!--{$key}-->" value="<!--{$arrForm[$key].value|h}-->" maxlength="<!--{$arrForm[$key].length}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" size="10" class="box10" />
				</td>
			</tr>
		</table>

		<div class="btn">
			<p class="page_rows">検索結果表示件数
			<!--{assign var=key value="search_page_max"}-->
			<span class="attention"><!--{$arrErr[$key]}--></span>
			<select name="<!--{$arrForm[$key].keyname}-->" id="<!--{$arrForm[$key].keyname}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->">
			<!--{html_options options=$arrPageMax selected=$arrForm[$key].value|default:$smarty.const.SEARCH_PMAX}-->
			</select> 件</p>
			<div class="btn-area">
				<ul>
					<li><a class="btn-action" href="javascript:;" onclick="eccube.fnFormModeSubmit('search_form', 'search', '', ''); return false;"><span class="btn-next">この条件で検索する</span></a></li>
				</ul>
			</div>
		</div>
		<!--検索条件設定テーブルここまで-->
	<!--▲登録テーブルここまで-->
	</form>

	<!--{if empty($arrErr) and ($smarty.post.mode == 'search' or $smarty.post.mode == 'delete')}-->

		<!--★★検索結果一覧★★-->
		<form name="form1" id="form1" method="post" action="?">
			<input type="hidden" name="<!--{$smarty.const.TRANSACTION_ID_NAME}-->" value="<!--{$transactionid}-->" />
			<input type="hidden" name="mode" value="search" />
			<input type="hidden" name="order_id" value="" />
			<!--{foreach key=key item=item from=$arrHidden}-->
				<!--{if is_array($item)}-->
					<!--{foreach item=c_item from=$item}-->
					<input type="hidden" name="<!--{$key|h}-->[]" value="<!--{$c_item|h}-->" />
					<!--{/foreach}-->
				<!--{else}-->
					<input type="hidden" name="<!--{$key|h}-->" value="<!--{$item|h}-->" />
				<!--{/if}-->
			<!--{/foreach}-->
			<h2>検索結果一覧</h2>
				<div class="btn">
				<span class="attention"><!--検索結果数--><!--{$tpl_linemax}-->件</span>&nbsp;が該当しました。
				<!--{if $tpl_linemax > 0}-->
					<!--{* <a class="btn-normal" href="javascript:;" onclick="eccube.setModeAndSubmit('csv','',''); return false;">CSV ダウンロード</a> *}-->
					<a class="btn-normal" href="javascript:;" onclick="eccube.setModeAndSubmit('auth_entry_all','',''); return false;">与信一括登録</a>
					<a class="btn-normal" href="javascript:;" onclick="fnSelectMailCheckSubmit('mail.php'); return false;"><span>メール一括通知</span></a>
					<a class="btn-normal" href="javascript:;" onclick="eccube.setModeAndSubmit('csv_output_all','',''); return false;">出荷データ一括出力</a>
					<a class="btn-normal" href="javascript:;" onclick="eccube.setModeAndSubmit('csv_output_invoice_all','',''); return false;">請求書データ一括出力(VAMOS)</a>
				<!--{/if}-->
			</div>
			<!--{if !empty($arrResults)}-->

				<!--{include file=$tpl_pager}-->

				<!--{* 検索結果表示テーブル *}-->
				<table class="order_table">
					<col width="1%" />
					<col width="6%" />
					<col width="13%" />
					<col width="13%" />
					<col width="10%" />
					<col width="6%" />
					<col width="6%" />
					<col width="33%" />
					<col width="6%" />
					<col width="6%" />

					<!-- ▼ヘッダー情報▼ -->
					<tr>
						<th rowspan=3><input type="checkbox" name="order_check_all" id="order_check_all" onclick="eccube.checkAllBox(this, 'input[name=\'order_check[]\']')" /></th>
						<th>注文番号</th>
						<th>定期状況</th>
						<th>顧客情報</th>
						<th>受注日</th>
						<th>支払方法</th>
						<th>金額</th>
						<th>今回商品 (発送予定/確約個数)</th>
						<th rowspan=3>メール <input type="checkbox" name="mail_check" id="mail_check" onclick="eccube.checkAllBox(this, 'input[name=\'mail_order_id[]\']')" /></th>
						<th rowspan=3>当日<br>出荷依頼</th>
					</tr>
					<tr>
						<th rowspan=2>編集</th>
						<th>出荷状況</th>
						<th>お届け先</th>
						<th>お届け日</th>
						<th colspan="2">回数</th>
						<th>次回以降定期商品</th>
					</tr>
					<tr>
						<th>定期購入日</th>
						<th colspan=2>顧客アイコン</th>
						<th colspan=2>オーソリ状況(NP/クレカ/Amazon)</th>
						<th>注文アイコン</th>
					</tr>
					<!-- ▲ヘッダー情報▲ -->

					<!--{section name=cnt loop=$arrResults}-->
						<!--{assign var=status value="`$arrResults[cnt].status`"}-->
						<!--{assign var=key value=$arrResults[cnt].teiki_status}-->
						<!--{assign var=payment_id value="`$arrResults[cnt].payment_id`"}-->
						<!--{assign var=order_id value="`$arrResults[cnt].order_id`"}-->
						<tr style="background:<!--{$arrORDERSTATUS_COLOR[$status]}-->;">

							<td class="center" rowspan="3">
								<input type="checkbox" name="order_check[]" class="order_check" value="<!--{$arrResults[cnt].order_id}-->">
							</td>
							<td class="center" style="border-bottom:1px dotted #7F7F7F">
								<!--{$arrResults[cnt].order_id}-->
							</td>
							<td class="center" style="border-bottom:1px dotted #7F7F7F; font-size:11.5px;">
								<!--{if $arrResults[cnt].teiki_order_date != ""}-->
									定期ID：<!--{$arrResults[cnt].teiki_id|h}--><br/><br/>
									<span style="font-size:15px;">
										<!--{if $arrResults[cnt].teiki_status!=''}-->定期: <!--{$arrTeikiStatus[$key]}--><!--{/if}-->
									</span>
								<!--{/if}-->
							</td>
							<td class="center" style="border-bottom:1px dotted #7F7F7F; font-size:11.5px;">
								顧客ID：<!--{$arrResults[cnt].customer_id|h}--><br/>
								<!--{$arrResults[cnt].customer_name|h}--><br/>
								〒<!--{$arrResults[cnt].customer_zip|h}--> <!--{$arrPref[$arrResults[cnt].customer_pref]|h}--><!--{$arrResults[cnt].customer_addr01|h}--><!--{$arrResults[cnt].customer_addr02|h}-->
							</td>
							<td class="center" style="border-bottom:1px dotted #7F7F7F;">
								<!--{$arrResults[cnt].create_date|sfDispDBDate}-->
							</td>
							<td class="center" style="border-bottom:1px dotted #7F7F7F;">
								<!--{$arrPayments[$payment_id]}-->
							</td>
							<td class="center" style="border-bottom:1px dotted #7F7F7F;">
								<!--{$arrResults[cnt].payment_total|number_format}-->
							</td>
							<td class="center" style="border-bottom:1px dotted #7F7F7F;">
								<!--{foreach from=","|explode:$arrResults[cnt].product_name item="product"}-->
									■<!--{$product|trim}--><br>
								<!--{/foreach}-->
							</td>
							<td class="center" rowspan="3">
								<!--{if $arrResults[cnt].customer_email|strlen >= 1}-->
									<input type="checkbox" name="mail_order_id[]" value="<!--{$arrResults[cnt].order_id}-->" id="mail_order_id_<!--{$arrResults[cnt].order_id}-->"/><label for="mail_order_id_<!--{$arrResults[cnt].order_id}-->">一括通知</label><br />
									<a href="?" onclick="eccube.changeAction('<!--{$smarty.const.ADMIN_ORDER_MAIL_URLPATH}-->'); eccube.setModeAndSubmit('pre_edit', 'order_id', '<!--{$arrResults[cnt].order_id}-->'); return false;"><span class="icon_mail">個別通知</span></a>
								<!--{/if}-->
							</td>
							<td class="center" rowspan="3">
								<!--{if $arrResults[cnt].is_today_shipping == 1}-->
									<span class="material-icons">local_shipping</span><br>当日出荷<br><br>
									<a class="btn-normal" style="padding:10px;" href="javascript:;" onclick="eccube.setModeAndSubmit('today_ship_cancel','order_id','<!--{$arrResults[cnt].order_id}-->'); return false;">依頼解除</a>
								<!--{elseif $status == 1 || $status == 8 || $status == 9001}-->
									<a class="btn-normal" style="padding:10px;" href="javascript:;" onclick="eccube.setModeAndSubmit('today_ship_ins','order_id','<!--{$arrResults[cnt].order_id}-->'); return false;"></i>依頼登録</a>
								<!--{else}-->
									依頼対象外
								<!--{/if}-->
							</td>
						</tr>
						<tr style="background:<!--{$arrORDERSTATUS_COLOR[$status]}-->;">
							<td class="center" style="border-top:none;" rowspan="2">
								<a href="?" onclick="eccube.changeAction('<!--{$smarty.const.ADMIN_ORDER_EDIT_URLPATH}-->'); eccube.setModeAndSubmit('pre_edit', 'order_id', '<!--{$arrResults[cnt].order_id}-->'); return false;"><span class="icon_edit">編集</span></a>
							</td>
							<td class="center" style="border-top:none; border-bottom:1px dotted #7F7F7F;">
								<!--{$arrDeliv[$arrResults[cnt].deliv_id]}-->
								<br>
								<!--{if $arrResults[cnt].shipping_commit_date == ""}-->
									未発送
								<!--{else}-->
									発送済み
								<!--{/if}-->
								<br>
								<span style="font-size:15px; color:#008000;">
									<b><!--{$arrORDERSTATUS[$status]}--></b>
								</span>
							</td>
							<td class="center" style="border-top:none; font-size:11.5px; border-bottom:1px dotted #7F7F7F;">
								<!--上記と同じ-->
								顧客ID：<!--{$arrResults[cnt].customer_id|h}--><br/>
								<!--{$arrResults[cnt].shipping_name01|h}--><br/>
								〒<!--{$arrResults[cnt].shipping_zip01|h}--> <!--{$arrPref[$arrResults[cnt].shipping_pref]|h}--><!--{$arrResults[cnt].shipping_addr01|h}--><!--{$arrResults[cnt].shipping_addr02|h}-->
							</td>
							<td class="center" style="border-top:none; border-bottom:1px dotted #7F7F7F;">
								<!--{$arrResults[cnt].shipping_date|date_format:"%Y/%m/%d"|default:""}-->
							</td>
							<td class="center" colspan="2" style="border-top:none; border-bottom:1px dotted #7F7F7F;">
								<!--{$arrResults[cnt].order_count|number_format}-->
							</td>
							<td class="center" style="border-top:none; border-bottom:1px dotted #7F7F7F;">
								<!--{if $arrResults[cnt].teiki_id != "" && $arrResults[cnt].teiki_status == 1}-->
									<!--{if $arrResults[cnt].teiki_product_name != ""}-->
										<!--{foreach from=","|explode:$arrResults[cnt].teiki_product_name item="product"}-->
											■<!--{$product|trim}--><br>
										<!--{/foreach}-->
									<!--{/if}-->
								<!--{/if}-->
							</td>
						</tr>
						<tr style="background:<!--{$arrORDERSTATUS_COLOR[$status]}-->;">
							<td class="center" style="text-align:center; height:15px; border-top:none;">
								<!--{$arrResults[cnt].teiki_order_date|sfDispDBDate}-->
							</td>
							<!--{if $arrResults[cnt].personal_del_flg > 0}-->
								<td class="center" colspan="2" style="text-align:left;color: #ff0000;font-weight: bold; height:15px; border-top:none;"><!--{$arrResults[cnt].customer_icon}--></td>
							<!--{else}-->
								<td class="center" colspan="2" style="text-align:left; height:15px; border-top:none;"><!--{$arrResults[cnt].customer_icon}--></td>
							<!--{/if}-->
							
							<td class="center" colspan="2" style="height:15px; border-top:none;">
								<span style="font-size:15px; color:#008000;">
									<b>
										<!--{if $arrResults[cnt].payment_id == 5}-->
											<!--{assign var=memo09 value=`$arrResults[cnt].memo09`}-->
											<!--{$arrDispKind[$memo09]}-->
										<!--{elseif $arrResults[cnt].payment_id == 9}-->
											<!--{$arrAmazonStatus[$order_id]}-->
										<!--{elseif $arrResults[cnt].payment_id == 6}-->
											<!--{$arrNpStatus[$arrResults[cnt].memo08]}-->
										<!--{elseif $arrResults[cnt].payment_id == 11}-->
											<!--{$arrNpRestStatus[$arrResults[cnt].memo08]}-->
										<!--{elseif $arrResults[cnt].payment_id == 12}-->
											<!--{$arrNyukinStatus[$arrResults[cnt].nyukin_status]}-->
										<!--{elseif $arrResults[cnt].payment_id == 13}-->
											<!--{$arrVaStatus[$arrResults[cnt].memo08]}-->
										<!--{/if}-->
									</b>
								</span>
							</td>
							<td class="center" style="text-align:left; height:15px; border-top:none;"><!--{$arrResults[cnt].order_icon}--></td>
						</tr>
					<!--{/section}-->
				</table>
				<!--{* 検索結果表示テーブル *}-->
			<!--{/if}-->
		</form>
	<!--{/if}-->
</div>

<!--{* 元
<td class="center"><!--{$arrResults[cnt].create_date|sfDispDBDate}--></td>
<td class="center"><!--{$arrResults[cnt].order_id}--></td>
<td class="center"><!--{$arrResults[cnt].order_name01|h}--> <!--{$arrResults[cnt].order_name02|h}--></td>
<td class="center"><!--{$arrResults[cnt].payment_method|h}--></td>
<td class="right"><!--{$arrResults[cnt].total|n2s}--></td>
<td class="center"><!--{$arrResults[cnt].commit_date|sfDispDBDate|default:"未発送"}--></td>
<td class="center"><!--{$arrORDERSTATUS[$status]}--></td>
<td class="center">
	<input type="checkbox" name="pdf_order_id[]" value="<!--{$arrResults[cnt].order_id}-->" id="pdf_order_id_<!--{$arrResults[cnt].order_id}-->"/><label for="pdf_order_id_<!--{$arrResults[cnt].order_id}-->">一括出力</label><br />
	<a href="./" onClick="eccube.openWindow('pdf.php?order_id=<!--{$arrResults[cnt].order_id}-->','pdf_input','620','650'); return false;"><span class="icon_class">個別出力</span></a>
</td>
<td class="center"><a href="?" onclick="eccube.changeAction('<!--{$smarty.const.ADMIN_ORDER_EDIT_URLPATH}-->'); eccube.setModeAndSubmit('pre_edit', 'order_id', '<!--{$arrResults[cnt].order_id}-->'); return false;"><span class="icon_edit">編集</span></a></td>
<td class="center">
	<!--{if $arrResults[cnt].order_email|strlen >= 1}-->
		<input type="checkbox" name="mail_order_id[]" value="<!--{$arrResults[cnt].order_id}-->" id="mail_order_id_<!--{$arrResults[cnt].order_id}-->"/><label for="mail_order_id_<!--{$arrResults[cnt].order_id}-->">一括通知</label><br />
		<a href="?" onclick="eccube.changeAction('<!--{$smarty.const.ADMIN_ORDER_MAIL_URLPATH}-->'); eccube.setModeAndSubmit('pre_edit', 'order_id', '<!--{$arrResults[cnt].order_id}-->'); return false;"><span class="icon_mail">個別通知</span></a>
	<!--{/if}-->
</td>
<td class="center"><a href="?" onclick="eccube.setModeAndSubmit('delete', 'order_id', <!--{$arrResults[cnt].order_id}-->); return false;"><span class="icon_delete">削除</span></a></td>
*}-->

<!--{if $smarty.get.mode == 'complete'}-->
<script type="text/javascript">
    // EC-CUBE2系では一度JS変数に受けるのが安全
    var success = <!--{$smarty.get.success|default:0}-->;
    var skip    = <!--{$smarty.get.skip|default:0}-->;
    var error   = <!--{$smarty.get.error|default:0}-->;

    alert(
        "CSV取込結果\n\n" +
        "成功　　　：" + success + "件\n" +
        "既存スキップ（システムに連絡してください）：" + skip + "件\n" +
        "エラー（システムに確認してください）：" + error + "件"
    );
</script>
<!--{/if}-->

<script>
	/* カレンダー設定 */
	const calender_conf = {
		wrap: true,
		locale:"ja",
		dateFormat: "Y-m-d",
		maxDate: new Date().fp_incr(365)
	}
	// カレンダー設定
	flatpickr(".calender-group", calender_conf);
</script>