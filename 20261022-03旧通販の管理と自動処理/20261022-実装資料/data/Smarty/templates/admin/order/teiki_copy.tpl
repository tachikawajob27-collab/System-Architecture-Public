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
<!--{include file="`$smarty.const.TEMPLATE_ADMIN_REALDIR`admin_popup_header.tpl"}-->

<style>select {height: 25px;}</style>

<script type="text/javascript">
<!--
//self.moveTo(20,20);self.focus();
	// 画面情報の初期化
	function frontRefresh(){
		alert("定期複製処理が完了しました！");

		// 検索情報
		var sfm = window.opener.document.form1;
		sfm.mode.value = "refresh";
		sfm.submit();
		window.close();
	}

//-->
</script>

<form name="form1" id="form1" method="post" action="">
	<input type="hidden" name="<!--{$smarty.const.TRANSACTION_ID_NAME}-->" value="<!--{$transactionid}-->" />
	<input type="hidden" name="mode" value="copy" />
	<input type="hidden" name="pageno" value="<!--{$tpl_pageno|h}-->" />
	<input type="hidden" name="brand_id" id="brand_id" value="<!--{$smarty.session.brand_id}-->" />

	<input type="hidden" name="teiki_id" id="teiki_id" value="<!--{$arrForm.teiki_id}-->" />
	<input type="hidden" name="detail_id" id="detail_id" value="<!--{$arrForm.detail_id}-->" />
	<input type="hidden" name="option_id" id="option_id" value="<!--{$arrTeiki.option_id}-->" />

	<h2>定期複製設定</h2>
	<table>
		<col width="20%" />
		<col width="80%" />
		<tr>
			<th>コース名</th>
			<td><!--{$arrTeiki.course_name|h}--></td>
		</tr>
		<tr>
			<th>商品名</th>
			<td><!--{$arrTeiki.product_name|h}--></td>
		</tr>
		<!--{if $arrTeiki.option_name != ""}-->
			<tr>
				<th><!--{$arrTeiki.option_name}--><span class="attention">※</span></th>
				<td>
					<select name="option_detail_id" style="<!--{$arrErr.option_detail_id|sfGetErrorColor}-->">
						<!--{html_options options=$arrProductOption}-->
					</select>
				</td>
			</tr>
		<!--{/if}-->
		<tr>
			<th>数量<span class="attention">※</span></th>
			<td>
				<!--{if empty($arrContainer[$arrTeiki.category_id])}-->
					<select name="quantity" style="<!--{$arrErr.quantity|sfGetErrorColor}-->">
						<!--{html_options options=$arrTeikiQuantity}-->
					</select>
				<!--{else}-->
					<table style="margin:auto;">
						<colgroup width="70%"></colgroup>
						<colgroup width="30%"></colgroup>
						<tr><th>容器名</th><th>個数</th></tr>
						<!--{foreach key=id item=name from=$arrContainer[$arrTeiki.category_id]}-->
						<tr>
							<td><!--{$name}--></td>
							<td>
								<select name="quantity[<!--{$id}-->]">
								<!--{html_options options=$arrTeikiQuantity2}-->
								</select>
							</td>
						</tr>
						<!--{/foreach}-->
					</table>
				<!--{/if}-->
			</td>
		</tr>
		<tr>
			<th>次回お届け日<span class="attention">※</span></th>
			<td>
				<div class="input-group calender-group">
					<input type="text" name="next_shipping_date"  id="next_shipping_date" value="<!--{$arrTeiki.next_shipping_date}-->"size="15" class="box15" data-input />
					<a class="calender-icon" title="toggle" data-toggle>
						<i class="material-icons">date_range</i>
					</a>
				</div>
			</td>
		</tr>
		<tr>
			<th>お届け間隔<span class="attention">※</span></th>
			<td>
				<select name="interval">
					<!--{html_options options=$arrTeikiInterval selected=$arrTeiki.interval}-->
				</select>
				<select name="interval_type">
					<!--{html_options options=$arrTeikiIntervalType selected=$arrTeiki.interval_type}-->
				</select>
			</td>
		</tr>
	</table>

	<div class="btn-area">
		<ul>
			<li><a class="btn-action" href="javascript:;" onclick="if (!eccube.doConfirm()) return false; eccube.fnFormModeSubmit('form1', 'copy', '', ''); return false;"><span class="btn-next">定期複製</span></a></li>
		</ul>
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

</script>

<!--{include file="`$smarty.const.TEMPLATE_ADMIN_REALDIR`admin_popup_footer.tpl"}-->
