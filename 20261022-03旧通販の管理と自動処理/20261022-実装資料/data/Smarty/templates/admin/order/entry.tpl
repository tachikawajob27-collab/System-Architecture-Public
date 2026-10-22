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
	/* 別送時のメッセージ欄 */
	.confirm_message {
		position: relative;
		margin: 1em 0;
		padding: 0.5em 1em;
		border: solid 3px #FF0000;
		border-radius: 8px;
		/* margin: 0.5em 0; */
		/* background: #FFC0CB; */
	}
	.confirm_message .box-title {
		position: absolute;
		display: inline-block;
		top: -13px;
		left: 10px;
		padding: 0 9px;
		line-height: 1;
		font-size: 19px;
		background: #FFF;
		color: #FF0000;
		font-weight: bold;
	}
	.confirm_message p {
		font-size: 15px;
		padding: 5px;
		margin: 0;
		letter-spacing: 0.1em;
	}

	label{
		position: relative;
		padding-left: 20px;
		cursor: pointer;
	}
	label input{
		position:absolute;
		top:0;
		bottom:0;
		left:0;
		margin:auto;
	}
	#check_modal {
		display: none;
		position: fixed;
		top: 0;
		left: 0;
		z-index: 10;
		width: 100vw;
		height: 100vh;
		background-color: rgba(0,0,0,0.6);
	}
	#check_modal_content {
		z-index: 20;
		background-color: #fff;
		position: fixed;
		top: 50%;
		left: 50%;
		transform: translate(-50%,-50%);
		text-align: center;
		width: 600px;
		height: 200px;
		border-radius: 10px;
	}
	#change_button_area {
		display: flex;
		align-items: flex-end;
		justify-content: space-between;
		height: 50%;
		padding: 20px;
	}
	a.btn-normal.change_button {
		padding: 20px;
		font-size: 15px;
		background-image: none;
	}
	#no_change_button {
		background-image: none;
	}
	#res-conf-close-btn {
		font-size: 30px;
		position: fixed;
		top: 10px;
		right: 10px;
		cursor: pointer;
		color: #fff;
		font-weight: bold;
	}
	#operator_modal {
		display: none;
		position: fixed;
		top: 0;
		left: 0;
		z-index: 10;
		width: 100vw;
		height: 100vh;
		background-color: rgba(0,0,0,0.6);
	}
	#operator_info_modal {
		z-index: 20;
		position: fixed;
		top: 45%;
		left: 50%;
		transform: translate(-50%,-50%);
		width: 50%;
		height: 55%;
		border-radius: 10px;
		background: linear-gradient(#00bcd4,#00e2ff) fixed;
	}
	#operator_info_modal table {
		width:85%;
		margin: auto auto 20px auto;
	}
	#operator_info_modal table td {
		background-color:#fff;
	}
	#operator_info_modal .title {
		text-align: center;
		margin: 15px 0;
		color: #fff;
		font-family: "ヒラギノ丸ゴ Pro W4",ヒラギノ丸ゴ Pro W4, Hiragino Maru Gothic Pro, Osaka, "ＭＳ ゴシック", sans-serif;
		font-size: 200%;
		padding: 5px 0;
	}
	.operator-btn-area {
		width:85%;
		display: flex;
		justify-content: space-around;
	}
	/* 10日前アラートダイアログ */
	.alertDialogClass .ui-dialog-titlebar {
		padding: 0.4em 1em;
		position: relative;
		height: 25px;
	}
	.alertDialogClass .ui-dialog-title {
		float: left;
		font-size: 20px;
		margin-top: 5px;
		overflow: visible;
	}
	.ui-widget-overlay {
		background-color: rgba(0,0,0,0.6);
		opacity: 1;
	}
	select {
		height: 25px;
	}
</style>


<script type="text/javascript">
<!--
	var send = true;
	// -------------------
	//	住所検索
	// -------------------
	function fnGetAddress() {
		var url = '<!--{$smarty.const.INPUT_ZIP_URLPATH}-->';

		// 検索値初期化
		document.form1.search_zip01.value = "";
		document.form1.search_zip02.value = "";

		var zip = "";
		var col1 = "";
		var col2 = "";
		zip = document.form1.order_zip01.value;
		col1 = "order_pref";
		col2 = "order_addr01";

		if (zip != "" && zip.length == 7) {
			// 切り取り
			document.form1.search_zip01.value = zip.slice(0,3);
			document.form1.search_zip02.value = zip.slice(-4);
			// 住所検索
			eccube.getAddress(url, 'search_zip01', 'search_zip02', col1, col2);
		} else {
			alert("郵便番号を正しく入力して下さい。");
		}
		return false;
	}

	// -------------------
	//	登録確認
	// -------------------
	function fnFormConfirm() {
		if (eccube.doConfirm()) {
			document.form1.submit();
		}
	}

	/****************************************************************
	* 機　能： 入力された値が日付でYYYY/MM/DD形式になっているか調べる
	* 引　数： strDate = 入力された値
	* 戻り値： 正 = true　不正 = false
	****************************************************************/
	function ckDate(strDate) {
		if(!strDate.match(/^\d{4}\-\d{2}\-\d{2}$/)){
			return false;
		}
		var y = strDate.split("-")[0];
		var m = strDate.split("-")[1] - 1;
		var d = strDate.split("-")[2];
		var date = new Date(y,m,d);
		if(date.getFullYear() != y || date.getMonth() != m || date.getDate() != d){
			return false;
		}
		return true;
	}

	// ---------------------
	// 金額再計算
	// ---------------------
	function fnRecalculate() {
		// 価格更新
		var result = window.confirm("注文金額を再計算します。よろしいでしょうか？");
		if (result) {
			document.form1.mode.value = "recalculate";
			document.form1.anchor_key.value = 'order_products';
			document.form1.submit();
		} else {
			window.alert('キャンセルされました'); // 警告ダイアログを表示
		}
	}

	// ---------------------
	// 注文商品行削除
	// ---------------------
	function fnProductsDelete(delete_no) {
		// 行削除
		var result = window.confirm("対象行を削除します。よろしいでしょうか？");
		if (result) {
			document.form1.mode.value = "products_delete";
			document.form1.anchor_key.value = 'order_products';
			document.form1.delete_no.value = delete_no;
			document.form1.submit();
		} else {
			window.alert('キャンセルされました'); // 警告ダイアログを表示
		}
	}

	// ---------------------
	// 注文コース・商品追加
	// ---------------------
	function fnAddCourse() {
		// コース・商品追加
		var course_id = $("select[name='add_course_id']").children(':selected').val();
		var order_cnt = $("input[name='add_order_count']:checked").val();
		var course_type = $("input[name='add_order_count']:checked").attr("c-type");
		if ($("select[name='option_id_" + order_cnt + "-" + course_type + "']").length) {
			var option_id = $("select[name='option_id_" + order_cnt + "-" + course_type + "']").children(':selected').attr("target-id");
			var option_detail_id = $("select[name='option_id_" + order_cnt + "-" + course_type + "']").children(':selected').val();
			var option_detail_txt = $("select[name='option_id_" + order_cnt + "-" + course_type + "']").children(':selected').text();
		} else {
			var option_id = "";
			var option_detail_id = "";
			var option_detail_txt = "";
		}
		console.log(course_id);
		console.log(order_cnt);
		console.log(course_type);
		if (course_id == "undefined") {
			window.alert('コースを選択して下さい');
			return false;
		}
		if (order_cnt == "undefined") {
			window.alert('商品を選択して下さい');
			return false;
		}
		var course = $("select[name='add_course_id']").children(':selected').text();
		var product_name = $("input[name='add_product_name_" + order_cnt + "-" + course_type + "']").val();

		var msg = "商品情報を追加します。\nよろしいですか？\n\n";
		msg = msg + "▼コース\n・" + course + "\n";
		if (option_detail_txt == "") {
			msg = msg + "▼商品名\n・" + product_name;
		} else {
			msg = msg + "▼商品名\n・" + product_name + "(" + option_detail_txt + ")";
		}
		flg = window.confirm(msg);
		if(flg){
			document.form1.mode.value = "add_course";
			document.form1.anchor_key.value = 'order_info';
			document.form1.add_course_type.value = course_type;
			document.form1.add_option_id.value = option_id;
			document.form1.add_option_detail_id.value = option_detail_id;
			document.form1.submit();
			return false;
		}else{
			window.alert('キャンセルされました'); // 警告ダイアログを表示
		}
	}

//-->
</script>



<form name="form1" id="form1" method="post" action="?">
	<input type="hidden" name="<!--{$smarty.const.TRANSACTION_ID_NAME}-->" value="<!--{$transactionid}-->" />
	<input type="hidden" name="mode" value="<!--{$tpl_mode|default:"edit"|h}-->" />
	<input type="hidden" name="teiki_id" value="<!--{$arrForm.teiki_id}-->" />
	<input type="hidden" name="detail_id" value="" />
	<input type="hidden" name="order_id" value="<!--{$arrOrder.order_id}-->" />
	<input type="hidden" name="customer_id" value="<!--{$arrOrder.customer_id}-->" />
	<input type="hidden" name="edit_customer_id" value="<!--{$arrForm.edit_customer_id}-->" />
	<input type="hidden" name="select_course_id" id="select_course_id" value="" />
	<input type="hidden" name="select_teiki_id" id="select_teiki_id" value="" />
	<input type="hidden" name="select_detail_id" id="select_detail_id" value="" />
	<input type="hidden" name="is_spread" value="0" />
	<input type="hidden" name="anchor_key" value="" />
	<input type="hidden" name="search_zip01" />
	<input type="hidden" name="search_zip02" />
	<input type="hidden" name="delete_no" id="delete_no" value="" />
	<input type="hidden" name="change_no" id="change_no" value="" />
	<input type="hidden" name="select_product_id" id="select_product_id" value="" />
	<input type="hidden" name="select_price" id="select_price" value="" />
	<input type="hidden" name="add_course_type" id="add_course_type" value="" />
	<input type="hidden" name="add_option_id" value="" />
	<input type="hidden" name="add_option_detail_id" value="" />
	<!--{foreach key=key item=item from=$arrSearchHidden}-->
		<!--{if is_array($item)}-->
			<!--{foreach item=c_item from=$item}-->
			<input type="hidden" name="<!--{$key|h}-->[]" value="<!--{$c_item|h}-->" />
			<!--{/foreach}-->
		<!--{else}-->
			<input type="hidden" name="<!--{$key|h}-->" value="<!--{$item|h}-->" />
		<!--{/if}-->
	<!--{/foreach}-->

	<div id="order_edit" class="contents-main">
		<div id="info" style="width:65%;">
			<!--▼今回注文情報情報ここから-->
			<div class="order_info" id="order_info">
				<p class="title">注文情報</p>
				<a name="order_info"></a>

				<!--▼受注商品情報ここから-->
				<a name="order_products"></a>
				<h2 id="order_products">注文商品情報</h2>
				<!--{if $arrErr.product_id}-->
					<span class="attention">※ 商品が選択されていません。</span>
				<!--{/if}-->
				<table class="form">
					<colgroup width="20%"></colgroup>
					<colgroup width="60%"></colgroup>
					<colgroup width="15%"></colgroup>
					<tr>
						<th style="vertical-align:middle;">
							コース選択
							<a class="btn-normal" style="float:right;" href="javascript:;" name="change" onclick="eccube.openWindow('../search/course_search.php?parent=order_new', 'search', '650', '500', {menubar:'no'}); return false;">コース選択</a>
						</th>
						<td colspan="2">
							<select name="add_course_id" id="add_course_id" onchange="fnGetCourseDetail();">
								<option value="">選択してください。</option>
								<!--{html_options options=$arrCourseAll}-->
							</select>
							<table id="add_course_info" style="margin:10px 0 0;display:none;width:100%;">
								<colgroup width="10%"></colgroup>
								<colgroup width="50%"></colgroup>
								<colgroup width="20%"></colgroup>
								<colgroup width="20%"></colgroup>
								<tr>
									<th>選択</th><th>商品名</th><th>数量</th><th>単価</th>
								</tr>
							</table>
						</td>
						<td id="course_submit">
							<a class="btn-normal" href="javascript:;" onclick="fnAddCourse();">追加</a>
						</td>
					</tr>
				</table>

				<h2><a class="btn-normal" href="javascript:;" name="recalculate" onclick="fnRecalculate(); return false;">計算結果の確認</a></h2>
				<table class="list" id="order-edit-products">
					<col width="6%" />
					<col width="13%" />
					<col width="32%" />
					<col width="12%" />
					<col width="6%" />
					<col width="10%" />
					<col width="8%" />
					<col width="10%" />
					<tr>
						<th class="id">商品ID</th>
						<th class="code">商品コード</th>
						<th class="name">商品名</th>
						<th class="price">単価</th>
						<th class="tax">税率</th>
						<th class="price">税込価格</th>
						<th class="qty">数量</th>
						<th class="price">小計</th>
					</tr>

					<!--{section name=cnt loop=$arrFormList.quantity.value}-->
						<!--{assign var=product_index value="`$smarty.section.cnt.index`"}-->
						<!--{assign var=quantity value=`$arrFormList.quantity.value[$product_index]`}-->
						<!--{if $arrFormList.tax_status.value[$product_index] == 1}-->
							<!--{assign var=price value=`$arrFormList.price.value[$product_index]`*1.08}-->
						<!--{else}-->
							<!--{assign var=price value=`$arrFormList.price.value[$product_index]`*1.1}-->
						<!--{/if}-->

						<tr>
							<td><!--{$arrFormList.product_id.value[$product_index]}--><br />
								<a class="btn-normal" href="javascript:;" name="delete" onclick="fnProductsDelete(<!--{$product_index}-->); return false;">削除</a>
							</td>
							<td><!--{$arrFormList.product_code.value[$product_index]}--></td>
							<td><!--{$arrFormList.product_name_disp.value[$product_index]}--><br><!--{$arrFormList.course_name.value[$product_index]}--></td>
							<td class="right">
								<!--{assign var=key value="price"}-->
								<input type="text" name="price[<!--{$product_index}-->]" value="<!--{$arrFormList.price.value[$product_index]}-->" size="6" class="box6" maxlength="9" /> 円<br />
							</td>
							<td class="right"><!--{if $arrFormList.tax_status.value[$product_index] == 1}-->8%<!--{else}-->10%<!--{/if}--></td>
							<td class="right">
								<!--{$price|floor|number_format}--> 円
							</td>
							<td class="right">
								<!--{assign var=key value="quantity"}-->
								<input type="text" name="<!--{$key}-->[<!--{$product_index}-->]" value="<!--{$arrFormList.quantity.value[$product_index]}-->" size="3" class="box3" maxlength="5" />
							</td>
							<td class="right">
								<!--{$price|floor|sfMultiply:$quantity|number_format}--> 円
							</td>
						</tr>
						<!--{* 隠し項目 *}-->
						<input type="hidden" name="product_id[<!--{$product_index}-->]" value="<!--{$arrFormList.product_id.value[$product_index]}-->" id="product_id_<!--{$product_index}-->" />
						<input type="hidden" name="course_id[<!--{$product_index}-->]" value="<!--{$arrFormList.course_id.value[$product_index]}-->" id="course_id_<!--{$product_index}-->" />
						<input type="hidden" name="order_count[<!--{$product_index}-->]" value="<!--{$arrFormList.order_count.value[$product_index]}-->" id="order_count<!--{$product_index}-->" />
						<input type="hidden" name="tax_status[<!--{$product_index}-->]" value="<!--{$arrFormList.tax_status.value[$product_index]}-->" id="tax_status_<!--{$product_index}-->" />
						<input type="hidden" name="tax_rate[<!--{$product_index}-->]" value="<!--{$arrFormList.tax_rate.value[$product_index]}-->" id="tax_rate_<!--{$product_index}-->" />
						<input type="hidden" name="tax_rule[<!--{$product_index}-->]" value="<!--{$arrFormList.tax_rule.value[$product_index]}-->" id="tax_rule_<!--{$product_index}-->" />
						<input type="hidden" name="product_name[<!--{$product_index}-->]" value="<!--{$arrFormList.product_name.value[$product_index]}-->" id="product_name_<!--{$product_index}-->" />
						<input type="hidden" name="product_name_disp[<!--{$product_index}-->]" value="<!--{$arrFormList.product_name_disp.value[$product_index]}-->" id="product_name_disp<!--{$product_index}-->" />
						<input type="hidden" name="product_code[<!--{$product_index}-->]" value="<!--{$arrFormList.product_code.value[$product_index]}-->" id="product_code_<!--{$product_index}-->" />
						<input type="hidden" name="course_name[<!--{$product_index}-->]" value="<!--{$arrFormList.course_name.value[$product_index]}-->" id="course_name_<!--{$product_index}-->" />
						<input type="hidden" name="option_id[<!--{$product_index}-->]" value="<!--{$arrFormList.option_id.value[$product_index]}-->" id="option_id<!--{$product_index}-->" />
						<input type="hidden" name="option_detail_id[<!--{$product_index}-->]" value="<!--{$arrFormList.option_detail_id.value[$product_index]}-->" id="option_detail_id<!--{$product_index}-->" />
					<!--{/section}-->
				</table>

				<table class="list" id="order-edit-products2">
					<tr>
						<th class="center">送料</th>
						<th class="center">手数料</th>
						<th class="center">小計</th>
						<th class="center">値引き</th>
						<th class="center">お支払い合計</th>
					</tr>
					<tr>
						<td class="right">
							<!--{assign var=key value="deliv_fee"}-->
							<span class="attention"><!--{$arrErr[$key]}--></span>
							<input type="text" name="<!--{$key}-->" value="<!--{$arrForm[$key]|h}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" size="5" class="box6" />
							円
						</td>
						<td class="right">
							<!--{assign var=key value="charge"}-->
							<span class="attention"><!--{$arrErr[$key]}--></span>
							<input type="text" name="<!--{$key}-->" value="<!--{$arrForm[$key]|h}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" size="5" class="box6" />
							円
						</td>
						<td class="right"><!--{$arrForm.subtotal|default:0|number_format}-->円</td>
						<td class="right">
							<!--{assign var=key value="discount"}-->
							<span class="attention"><!--{$arrErr[$key]}--></span>
							<input type="text" name="<!--{$key}-->" value="<!--{$arrForm[$key]|default:0|number_format}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" size="5" class="box6" />
							円
						</td>
						<td class="right">
							<span class="attention"><!--{$arrErr.payment_total}--></span>
							<span id="payment_total"><!--{$arrForm.payment_total|default:0|number_format}--></span>
							円
						</td>
					</tr>
					<input type="hidden" name="payment_total" value="<!--{$arrForm.payment_total}-->" />
					<input type="hidden" name="total" value="<!--{$arrForm.payment_total}-->" />
					<input type="hidden" name="subtotal" value="<!--{$arrForm.subtotal}-->" />
				</table>

				<!--▼お届け先情報ここから-->
				<a name="shipping"></a>
				<h2>配送先情報  <a class="btn-normal" href="javascript:;" name="customer_search" onclick="eccube.openWindow('../search/customer_search.php?parent=order_new', 'search', '650', '500', {menubar:'no'}); return false;">顧客検索</a></h2>
				<table class="form">
					<colgroup width="20%"></colgroup>
					<colgroup width="25%"></colgroup>
					<colgroup width="20%"></colgroup>
					<colgroup width="40%"></colgroup>

					<tr>
						<th>お名前</th>
						<td colspan=3>
							<!--{assign var=key1 value="order_name01"}-->
							<span class="attention"><!--{$arrErr[$key1]}--></span>
							<input type="text" name="<!--{$key1}-->" value="<!--{$arrForm[$key1]|h}-->" style="<!--{$arrErr[$key1]|sfGetErrorColor}-->" size="15" class="box15" />
						</td>
					</tr>
					<tr>
						<th>メールアドレス</th>
						<td colspan=3>
							<!--{assign var=key1 value="order_email"}-->
							<span class="attention"><!--{$arrErr[$key1]}--></span>
							<input type="text" name="<!--{$key1}-->" value="<!--{$arrForm[$key1]|h}-->" style="<!--{$arrErr[$key1]|sfGetErrorColor}-->" size="30" class="box30" />
						</td>
					</tr>
					<tr>
						<th>TEL</th>
						<td colspan=3>
							<!--{assign var=key1 value="order_tel01"}-->
							<span class="attention"><!--{$arrErr[$key1]}--></span>
							<input type="text" name="<!--{$key1}-->" value="<!--{$arrForm[$key1]|h}-->" maxlength="11" style="<!--{$arrErr[$key1]|sfGetErrorColor}-->" size="20" class="box11" />
						</td>
					</tr>
					<tr>
						<th>住所</th>
						<td colspan=3>
							<!--{assign var=key1 value="order_zip01"}-->
							<span class="attention"><!--{$arrErr[$key1]}--></span>
							〒
							<input type="text" id="<!--{$key1}-->" name="<!--{$key1}-->" value="<!--{$arrForm[$key1]|h}-->" maxlength="7" style="<!--{$arrErr[$key1]|sfGetErrorColor}-->" size="10" class="p-postal-code" />
							<a class="btn-normal" href="javascript:;" name="address_input" onclick="fnGetAddress(0);">住所入力</a><br>
							<!--{assign var=key value="order_pref"}-->
							<span class="attention"><!--{$arrErr[$key]}--></span>
							<select class="top" name="<!--{$key}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->">
								<option value="" selected="">都道府県を選択</option>
								<!--{html_options options=$arrPref selected=$arrForm[$key]}-->
							</select><br />
							<!--{assign var=key value="order_addr01"}-->
							<span class="attention"><!--{$arrErr[$key]}--></span>
							<input type="text" name="<!--{$key}-->" value="<!--{$arrForm[$key]|h}-->" size="60" class="box60 top" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" /><br />
							<!--{assign var=key value="order_addr02"}-->
							<span class="attention"><!--{$arrErr[$key]}--></span>
							<input type="text" name="<!--{$key}-->" value="<!--{$arrForm[$key]|h}-->" size="60" class="box60" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" />
						</td>
					</tr>
					<tr>
						<th>配送方法</th>
						<td>
							<!--{assign var=key value="deliv_id"}-->
							<span class="attention"><!--{$arrErr[$key]}--></span>
							<select name="<!--{$key}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" onchange="fnChangeDeliv();">
								<option value="" selected="">選択してください</option>
								<!--{foreach key=key2 item=item2 from=$arrDeliv}-->
									<!--{if $arrForm.deliv_id == $item2.deliv_id}-->
									<option value="<!--{$item2.deliv_id}-->" selected><!--{$item2.service_name}--></option>
									<!--{else}-->
									<option value="<!--{$item2.deliv_id}-->"><!--{$item2.service_name}--></option>
									<!--{/if}-->
								<!--{/foreach}-->
							</select>
						</td>
						<th>お届け時間</th>
						<td>
							<!--{assign var=key value="time_id"}-->
							<!--{if $arrShipping.shipping_commit_date}-->
								<!--{assign var=key2 value=$arrShipping[$key]}-->
								<!--{$arrDelivTime.$key2|default:"指定無し"}-->
							<!--{else}-->
								<span class="attention"><!--{$arrErr[$key]}--></span>
								<select name="<!--{$key}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->">
									<option value="" selected="0">指定無し</option>
									<!--{foreach key=key2 item=item2 from=$arrDelivTime}-->
										<!--{if $arrShipping.time_id == $item2.time_id && $arrForm.deliv_id == $item2.deliv_id}-->
										<option deliv="<!--{$item2.deliv_id}-->" value="<!--{$item2.time_id}-->" selected><!--{$item2.deliv_time}--></option>
										<!--{else}-->
										<option deliv="<!--{$item2.deliv_id}-->" value="<!--{$item2.time_id}-->" <!--{if $arrForm.deliv_id != $item2.deliv_id}-->style="display:none"<!--{/if}-->><!--{$item2.deliv_time}--></option>
										<!--{/if}-->
									<!--{/foreach}-->
								</select>
							<!--{/if}-->
						</td>
					</tr>
					<tr>
						<th>お支払方法</th>
						<td colspan=3>
							<!--{assign var=key value="payment_id"}-->
							<span class="attention"><!--{$arrErr[$key]}--></span>
							<select charge="0" name="<!--{$key}-->" id="<!--{$key}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" onchange="fnChangePayment();">
								<option value="" selected="">選択してください</option>
								<!--{foreach key=key item=item from=$arrPayment}-->
									<!--{if $arrForm.payment_id == $item.payment_id}-->
									<option charge="<!--{$item.charge}-->" value="<!--{$item.payment_id}-->" selected><!--{$item.payment_method}--></option>
									<!--{else}-->
									<option charge="<!--{$item.charge}-->" value="<!--{$item.payment_id}-->"><!--{$item.payment_method}--></option>
									<!--{/if}-->
								<!--{/foreach}-->
							</select>
						</td>
					</tr>
					<tr>
						<th>お届け日</th>
						<td colspan=3>
							<!--{assign var=key1 value="shipping_date"}-->
							<span class="attention"><!--{$arrErr[$key1]}--></span>
							<div class="input-group calender-group">
								<input type="text" name="<!--{$key1}-->" id="<!--{$key1}-->" value="<!--{$arrForm.shipping_date}-->"size="20" class="box20" data-input />
								<a class="calender-icon" title="toggle" data-toggle>
									<i class="material-icons">date_range</i>
								</a>
							</div>
							<input type="hidden" name="bef_shipping_date" value="<!--{$arrForm.shipping_date}-->" />
						</td>
					</tr>
				</table>
			</div>
			<!--▲今回注文情報情報ここまで-->

			<!--{* ▼▼▼ ボタン領域 ▼▼▼ *}-->
			<div class="btn-area">
				<ul>
					<li><a class="btn-action" href="javascript:;" onclick="return fnFormConfirm(); return false;"><span class="btn-next">この内容で登録する</span></a></li>
				</ul>
			</div>
			<!--{* ▲▲▲ ボタン領域 ▲▲▲ *}-->
		</div>
	</div>
</form>


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

	// 顧客情報検索
	function fnSearchCustomer(id) {
		// 送信データを準備
		var url = "./entry.php";
		var postData = {};
		var form = $('#form1');
		$(form.serializeArray()).each(function(key, val) {
			postData[val.name] = val.value;
		});
		postData["customer_id"] = id;
		postData["mode"] = "customer_search";
		console.log(postData);
		$.ajax({
			type: "POST",
			url: url,
			data: postData,
			cache: false,
			dataType: "json",
			error: function(XMLHttpRequest, textStatus, errorThrown) {
				console.log('【fnSearchCustomer】失敗しました。');
				console.log(XMLHttpRequest);
				console.log(textStatus);
				console.log(errorThrown);
			},
			success: function(result) {
				console.log(result);
				df = document.form1;
				df["order_name01"].value = result.name;
				df["order_email"].value = result.email;
				df["order_tel01"].value = result.tel;
				df["order_zip01"].value = result.zip;
				df["order_pref"].value = result.pref;
				df["order_addr01"].value = result.addr01;
				df["order_addr02"].value = result.addr02;
				df["payment_id"].value = result.payment_id;
				df["edit_customer_id"].value = id;
			}
		});
	}

	// ▼コース明細情報取得
	function fnGetCourseDetail() {
		// 送信データを準備
		var url = "./entry.php";
		var postData = {};
		var form = $('#form1');
		$(form.serializeArray()).each(function(key, val) {
			postData[val.name] = val.value;
		});
		postData["mode"] = "course_select";
		$.ajax({
			type: "POST",
			url: url,
			data: postData,
			cache: false,
			dataType: "json",
			error: function(XMLHttpRequest, textStatus, errorThrown) {
				console.log('【fnGetCourseDetail】失敗しました。');
				console.log(XMLHttpRequest);
				console.log(textStatus);
				console.log(errorThrown);
			},
			success: function(result) {
				console.log(result);
				var i = 0;
				var max = result.length;
				var elem = "#add_course_info";
				// 1行目以降を削除
				$(elem).find("tr:gt(0)").remove();
				// 取得情報を追加
				result.forEach(item => {
					console.log(item)
					$(elem).append(getRowData(item));
				});
				$(elem).show();
			}
		});
		function getRowData(item) {
			var select_tag = "";
			console.log(item.option_select);
			if (item.option_select.length > 0) {
				select_tag = select_tag + "（<select name='option_id_" + item.order_count + "-" + item.course_type + "'>";
				item.option_select.forEach(data => {
					select_tag = select_tag + "<option target-id='" + data.option_id + "' value='" + data.option_detail_id + "'>" + data.option_detail_name + "</option>";
				});
				select_tag = select_tag + "</select>）";
			}

			table = "<tr>";
			table = table + "<td><input type='radio' name='add_order_count' value='" + item.order_count + "' c-type='" + item.course_type + "'></td>";
			table = table + "<td>" + item.product_name + " " + select_tag + "</td>";
			table = table + "<td>" + item.quantity + "個</td>";
			table = table + "<td>" + Number(item.price).toLocaleString() + "円</td>";
			table = table + "<input type='hidden' name='add_product_id_" + item.order_count + "-" + item.course_type + "' value='" + item.product_id + "'>";
			table = table + "<input type='hidden' name='add_product_name_" + item.order_count + "-" + item.course_type + "' value='" + item.product_name + "'>";
			table = table + "</tr>";
			return table;
		}
	}

	// 配送方法変更時処理
	function fnChangeDeliv() {
		// 配送方法IDを取得
		var deliv = $("select[name='deliv_id']").val();

		// 配送方法より配送指定時間帯を
		$("select[name='time_id'] option").each(function(){
			//console.log($(this).attr("deliv"));
			//console.log($(this).val());
			if ($(this).val() == "") {
				$(this).show();
			} else if ($(this).attr("deliv") == deliv) {
				$(this).show();
			} else {
				$(this).hide();
			}
		});

		// 選択初期化
		$("select[name='time_id']").val("");
	}

	// 支払方法変更時処理
	function fnChangePayment() {
		// 支払方法
		var payment_id = $("select[name='payment_id']").val();
		var charge = Number($("select[name='payment_id'] option:selected").attr("charge"));
		console.log(payment_id);
		console.log(charge);

		// 手数料
		$("input[name='charge']").val(charge);

		// 合計金額計算
		var deliv_fee = Number($("input[name='deliv_fee']").val());
		var discount = Number($("input[name='discount']").val());
		var subtotal = Number($("input[name='subtotal']").val());
		var total = (subtotal + deliv_fee + charge) - discount;

		$("#payment_total").text(total.toLocaleString());
		$("input[name='payment_total']").val(total);
		$("input[name='total']").val(total);

	}

</script>