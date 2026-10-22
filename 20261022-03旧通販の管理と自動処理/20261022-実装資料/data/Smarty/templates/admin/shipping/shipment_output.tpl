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
<style>select {width:auto;height:25px;}</style>
<div id="order" class="contents-main">
<form name="form1" id="form1" method="post" action="./shipment_output.php">
	<input type="hidden" name="<!--{$smarty.const.TRANSACTION_ID_NAME}-->" value="<!--{$transactionid}-->" />
	<input type="hidden" name="mode" value="output" />
	<input type="hidden" name="pageno" value="<!--{$tpl_pageno|h}-->" />
	<input type="hidden" name="brand_id" id="brand_id" value="<!--{$smarty.session.brand_id}-->" />

	<input type="hidden" name="shippingnum_new" id="shippingnum_new" value="<!--{$shippingnum_new}-->" />
	<input type="hidden" name="shippingnum_repeate" id="shippingnum_repeate" value="<!--{$shippingnum_repeate}-->" />
	<input type="hidden" name="shippingdate_new" id="shippingdate_new" value="<!--{$shippingdate_new}-->" />
	<input type="hidden" name="shippingdate_repeate" id="shippingdate_repeate" value="<!--{$shippingdate_repeate}-->" />

	<h2>出荷条件設定</h2>
	<table>
		<colgroup width="27%"></colgroup>
		<colgroup width="23%"></colgroup>
		<colgroup width="23%"></colgroup>
		<colgroup width="27%"></colgroup>
		
		<tr>
			<th rowspan=2>お届け対象日数設定ルール</th>
			<th>新規</th>
			<td colspan=2>
			月曜〜水曜：４日前<br>
			木曜　　　：５日前<br>
			金曜　　　：６日前<br>
			祝日前　　：各曜日のルール＋祝日の日数<br>
			　　　　　　（休日の祝日はカウントしない）
			</td>
		</tr>
		<tr>
			<th>既存</th>
			<td colspan=2>
			月曜〜火曜：５日前<br>
			水曜　　　：６日前<br>
			木曜〜金曜：７日前<br>
			祝日前　　：各曜日のルール＋祝日の日数<br>
			　　　　　　（休日の祝日はカウントしない）
			</td>
		</tr>
	</table>
	<br />
	<p class="attention" id="output_message" style="font-size:15px;font-weight:bold"><!--{$arrErr.output|h}--></p>
	<br />
	<h2>出荷データ出力</h2>
	<table>
		<col width="25%" />
		<col width="75%" />
		<tr>
			<th>出力リスト<span class="attention">※</span></th>
			<td>
				<select name="list_type" id="list_type" onchange="changeListType();">
					<option value="">選択してください</option>
					<!--{html_options options=$arrListType selected=$arrForm.list_type}-->
				</select>
			</td>
		</tr>
		<tr class="is_all is_wms">
			<th>出力対象<span class="attention">※</span></th>
			<td>
				<select name="output_type" id="output_type" onchange="changeOutputType();">
					<option value="">選択してください</option>
					<!--{html_options options=$arrOutputType selected=$arrForm.output_type}-->
				</select>
			</td>
		</tr>
		<tr class="is_all">
			<th>お届け対象日数<span class="attention">※</span></th>
			<td>
				<select name="shipping_num" id="shipping_num">
					<option value="">選択してください</option>
					<!--{html_options options=$arrOffsetDate selected=$arrForm.shipping_num}-->
				</select>
			</td>
		</tr>
		<tr class="is_all is_wms is_vamos">
			<th>出荷日</th>
			<td>
				<div style="display:flex;align-items:center;">
					<div class="input-group calender-group">
						<!--{assign var=key value="shipping_date"}-->
						<input type="text" name="<!--{$key}-->" id="<!--{$key}-->" value="<!--{$arrForm[$key]|date_format:"%Y年%-m月%-d日"}-->"size="15" class="box15" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" data-input />
						<a class="calender-icon" title="toggle" data-toggle>
							<i class="material-icons">date_range</i>
						</a>
					</div>
				</div>
			</td>
		</tr>
		<tr class="is_all is_vamos">
			<th>出力日</th>
			<td>
				<div style="display:flex;align-items:center;">
					<div class="input-group calender-group">
						<!--{assign var=key value="output_date"}-->
						<input type="text" name="<!--{$key}-->" id="<!--{$key}-->" value="<!--{$arrForm[$key]|default:$smarty.now|date_format:"%Y年%-m月%-d日"}-->"size="15" class="box15" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" data-input />
						<a class="calender-icon" title="toggle" data-toggle>
							<i class="material-icons">date_range</i>
						</a>
					</div>
				</div>
			</td>
		</tr>
	</table>
	<div class="btn-area">
		<ul>
			<li><a class="btn-action" href="javascript:;" onclick="eccube.changeAction('./shipment_renew.php'); eccube.setModeAndSubmit('back', '', );"><span class="btn-prev">前のページに戻る</span></a></li>
			<li><a class="btn-action" href="javascript:;" onclick="if (!outputConfirm()) return false;"><span class="btn-next">このデータを出力</span></a></li>
		</ul>
	</div>
</form>
</div>

<form name="csvDownloadForm" id="csvDownloadForm" method="POST" action="./shipment_download.php" target="downloadFrame">
<input type="hidden" name="download_token" id="downloadToken">
<input type="hidden" name="list_type" id="list_type">
<input type="hidden" name="output_type" id="output_type">
<input type="hidden" name="shipping_num" id="shipping_num">
<input type="hidden" name="shipping_date" id="shipping_date">
<input type="hidden" name="output_date" id="output_date">
</form>
<iframe name="downloadFrame" id="downloadFrame" style="display:none;"></iframe>

<script>
	/* 【日別】カレンダー設定 */
	const calender_conf = {
		wrap: true,
		locale:"ja",
		dateFormat: "Y-m-d",
		maxDate: new Date().fp_incr(365)
	}
	flatpickr(".calender-group", calender_conf);

	let = is_send = false;

	// 出力確認
	function outputConfirm() {
		// 出力中確認
		if (is_send) {
			alert("現在CSV出力中です。しばらくお待ちください。");
			return false;
		}
		// 入力項目チェック
		let list_type = document.form1.list_type.value;
		let output_type = document.form1.output_type.value;
		let shipping_num = document.form1.shipping_num.value;
		let shipping_date = document.form1.shipping_date.value;
		let output_date = document.form1.output_date.value;
		if (list_type == "") {
			alert("出力リストを選択してください。");
			return false;
		}
		if (list_type == "1" && output_type == "") {
			alert("出力対象を選択してください。");
			return false;
		}
		if (list_type != "2" && shipping_num == "") {
			alert("お届け対象日数を選択してください。");
			return false;
		}

		// 最終確認
		is_send = false;
		if(window.confirm('この内容でデータ出力しますか？')){
			document.body.style.cursor = 'wait';
			if (list_type == "0") {
				document.getElementById('output_message').innerHTML = "※ ピッキングリストCSV出力中です！！";
			} else if (list_type == "1") {
				document.getElementById('output_message').innerHTML = "※ WMS出荷データCSV出力中です！！";
			} else if (list_type == "2") {
				document.getElementById('output_message').innerHTML = "※ バモス請求書データCSV出力中です！！";
			}
			is_send = true;
			
			const token = String(Date.now());
			deleteCookie('csv_download_token');
			// Cookie監視開始
    		watchDownloadCookie(token);

			// ダウンロードフォームをsubmit
			document.csvDownloadForm.downloadToken.value = token;
			document.csvDownloadForm.list_type.value = list_type;
			document.csvDownloadForm.output_type.value = output_type;
			document.csvDownloadForm.shipping_num.value = shipping_num;
			document.csvDownloadForm.shipping_date.value = shipping_date;
			document.csvDownloadForm.output_date.value = output_date;
			document.csvDownloadForm.submit();

		} else {
			return false;
		}
	}
	function watchDownloadCookie(token) {
		const timeoutMs = 180000; // 60秒
		const intervalMs = 500;
		const startTime = Date.now();

		const timer = setInterval(function () {
			const cookieValue = getCookie('csv_download_token');

			// PHP側でセットされたCookieと一致したら成功扱い
			if (cookieValue === token) {
				clearInterval(timer);
				deleteCookie('csv_download_token');
				hideLoading();

				console.log('CSVダウンロード開始検知');
				document.body.style.cursor = 'auto';
				document.getElementById('output_message').innerHTML = "";
				is_send = false;
				alert('CSVダウンロード完了しました！！');
				return;
			}

			// タイムアウト
			// if (Date.now() - startTime > timeoutMs) {
			//	clearInterval(timer);
			//	hideLoading();
			//	alert('CSVダウンロードに失敗しました。時間をおいて再度お試しください。');
			// }
		}, intervalMs);
	}
	function getCookie(name) {
		const cookies = document.cookie.split(';');
		for (let i = 0; i < cookies.length; i++) {
			const cookie = cookies[i].trim();
			const parts = cookie.split('=');
			const key = parts[0];
			const value = parts.slice(1).join('=');
			if (key === name) {
				return decodeURIComponent(value);
			}
		}
		return null;
	}
	function deleteCookie(name) {document.cookie = name + '=; Max-Age=0; path=/';}
	function showLoading() {console.log('loading...');}
	function hideLoading() {console.log('done');}

	// リスト表示切替
	function changeListType() {
		// ALL表示
		$(".is_all").show();
		document.body.style.cursor = 'auto';

		// 変更値
		let list_type = document.form1.list_type.value;
		if (list_type == "0") {
			// ピッキングリスト
			$(".is_vamos").hide();
			$(".is_wms").hide();
			// お届け日数をリピート値をデフォルトに
			document.form1.shipping_num.value = document.form1.shippingnum_repeate.value;
		} else if (list_type == "1") {
			// WMS出荷データ
			$(".is_vamos").hide();
			$(".is_wms").show();
		}  else if (list_type == "2") {
			// バモスデータ
			$(".is_all").hide();
			$(".is_vamos").show();
		} else {
			$(".is_all").hide();
		}
	}

	// 出力対象切替
	function changeOutputType() {
		// デフォルト値
		const shippingnum_new = document.form1.shippingnum_new.value;
		const shippingnum_repeate = document.form1.shippingnum_repeate.value;
		const shippingdate_new = document.form1.shippingdate_new.value;
		const shippingdate_repeate = document.form1.shippingdate_repeate.value;

		// 変更値
		let output_type = document.form1.output_type.value;
		if (output_type == 0 || output_type == 1) {
			// 全て or 新規
			document.form1.shipping_num.value = shippingnum_new;
			document.form1.shipping_date.value = shippingdate_new;
		} else if (output_type == 2) {
			// リピート
			document.form1.shipping_num.value = shippingnum_repeate;
			document.form1.shipping_date.value = shippingdate_repeate;
		}
	}
	// 初期表示
	changeListType();
</script>
