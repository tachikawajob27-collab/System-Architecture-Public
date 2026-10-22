<script type="text/javascript">
	var send = true;
	//入力されたキーを保存する
	var inputKey = [];

	function fnFormCheckSubmit(form, mode, keyname, keyid) {
		if(send) {
			send = false;
			document.forms[form]['mode'].value = mode;
			if(keyname != "" && keyid != "") {
					document.forms[form][keyname].value = keyid;
			}
			document.forms[form].submit();

			return true;
		} else {
			alert("只今、処理中です。しばらくお待ち下さい。");
			return false;
		}
	}
	function fnModelEntry() {
		// 切り替え
		document.search_form.action = "./shipment_output.php";
		document.search_form.submit();
	}
	function fnShippingReadyCancel(id) {
		if(!confirm('出荷準備中状態を解除します。よろしいですか？')){
			return false;
		} else {
			document.search_form.shipping_ready_id.value = id;
			document.search_form.mode.value = "restore";
			document.search_form.submit();
		}
	}
</script>

<style>
	.sub-title{
		margin-left : -15px;
	}
	.ship_prepar{
		width: 290px;
		color:red;
		font-size:20px;
		margin:15px 0px;
		border-bottom: solid 2px red;
	}
	.ship_prepar span{
		font-size:25px;
		font-weight:bold;
	}
	.ship_cnt{
		color:red;
		font-size:15px;
		margin:15px 0px;
	}
	.ship_cnt span{
		font-size:20px;
		font-weight:bold;
	}
	.uplode_height {
		height: 70px;
	}
</style>

<div id="order" class="contents-main">
	<form name="search_form" id="search_form" method="post" action="?" enctype="multipart/form-data">
		<input type="hidden" name="<!--{$smarty.const.TRANSACTION_ID_NAME}-->" value="<!--{$transactionid}-->" />
		<input type="hidden" name="mode" value="search" />
		<input type="hidden" name="shipping_ready_id" id="shipping_ready_id" />

		<div style="margin-bottom:20px; padding: 10px; border: 1px solid #ccc; text-align: center;">
			<a class="btn-action" href="javascript:;" style="width:250px;height:20px;padding-top:10px;font-size:110%;" name="subm2" onclick="fnModelEntry(); return false;">
				<span class="btn-next">データ出力</span>
			</a>
		</div>

		<p class="ship_prepar">本日の出荷準備件数：
			<span><!--{$intShipPreparCount}--></span>件
		</p>
		<p class="ship_prepar">準備金額：
			<span><!--{$intShipPreparTotalAmount|number_format}--></span>円
		</p>

		<h2>出荷準備中データ一覧</h2>
		<table>
			<col width="20%" />
			<col width="20%" />
			<col width="25%" />
			<col width="25%" />
			<col width="10%" />
			<tr>
				<th style="text-align:center">出荷日</th>
				<th style="text-align:center">出力区分</th>
				<th style="text-align:center">出荷予定件数</th>
				<th style="text-align:center">売上予定金額</th>
				<th style="text-align:center">出荷準備中解除</th>
			</tr>
			<!--{foreach from=$arrShippingReady key="key" item="item" name=loop}-->
				<tr>
				<td style="text-align:center"><!--{$item.shipping_date}--></td>
				<td style="text-align:center"><!--{$arrOutputType[$item.output_type]}--></td>
				<td style="text-align:right"><!--{$item.shipping_cnt}-->件</td>
				<td style="text-align:right"><!--{$item.shipping_total|number_format}-->円</td>
				<td style="text-align:center"><a class="btn-normal" href="javascript:;" onclick="fnShippingReadyCancel(<!--{$item.shipping_ready_id}-->);">準備中解除</a></td>
				</tr>
			<!--{/foreach}-->
		</table>
		
		<h2>本日から15日間の出荷予定数（出荷準備中は含めない）</h2>
		<table>
			<col width="40%" />
			<col width="60%" />
			<tr>
				<th style="text-align:center">出荷日</th>
				<th style="text-align:center">出荷件数</th>
			</tr>
			<!--{foreach from=$arrShipYtiData key="key" item="item" name=loop}-->
				<tr>
				<td style="text-align:center"><!--{$key}--></td>
				<td style="text-align:right"><!--{$item|h}--> 件</td>
				</tr>
			<!--{/foreach}-->
		</table>

		<h2>アップロード</h2>
		<table>
			<tr class="uplode_height">
				<th>出荷報告データアップロード</th>
				<td>
					<input type="file" name="csv_upload_wms" size="40" />
					<a class="btn-normal" href="javascript:;" onclick="fnFormCheckSubmit('search_form', 'csv_upload_wms', '', ''); return false;">アップロード</a>
					<!--{assign var=key value="csv_upload_wms"}-->
					<span class="attention"><!--{$arrErr[$key]}--></span>
					<font color="blue"><!--{$arrUploadMsg[$key]}--></font>
				</td>
			</tr>
			<tr class="uplode_height">
				<th>倉庫返品データアップロード</th>
				<td>
					<input type="file" name="csv_upload_returns" size="40" />
					<a class="btn-normal" href="javascript:;" onclick="eccube.fnFormModeSubmit('search_form','csv_upload_returns','', ''); return false;">アップロード</a>
					<!--{assign var=key value="csv_upload_returns"}-->
					<span class="attention"><!--{$arrErr[$key]}--></span>
					<font color="blue"><!--{$arrUploadMsg[$key]}--></font>
				</td>
			</tr>
		</table>

		<h2>過去7日間の出荷履歴</h2>
		<table class="list">
			<col width="33%" />
			<col width="33%" />
			<col width="33%" />
			<tr>
				<th>出荷日</th>
				<th>配送業者</th>
				<th>出荷件数</th>
			</tr>
			<!--{section name=cnt loop=$arrCommitData}-->
				<tr>
				<td align="center"><!--{$arrCommitData[cnt].commit_date|sfDispDBDate:false}--></td>
				<td align="center"><!--{$arrCommitData[cnt].deliv_name|h}--></td>
				<td align="center"><!--{$arrCommitData[cnt].count|h}--> 件</td>
				</tr>
			<!--{/section}-->
		</table>

		<h2>出荷準備データ検索</h1>
		<table>
			<tr>
				<th>注文番号</th>
				<td>
					<!--{assign var=key1 value="search_order_id"}-->
					<input type="text" name="<!--{$key1}-->" value="<!--{$arrForm[$key1].value|h}-->" maxlength="<!--{$arrForm[$key1].length}-->" style="<!--{$arrErr[$key1]|sfGetErrorColor}-->" size="15" class="box15" />
				</td>
				<th>顧客名</th>
				<td>
					<!--{assign var=key value="search_order_name"}-->
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
					<input type="text" name="<!--{$key}-->" value="<!--{$arrForm[$key].value|h}-->" maxlength="<!--{$arrForm[$key].length}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->" size="30" class="box30" />
				</td>
			</tr>
			<tr>
				<th>検索対象</th>
				<td colspan=3>
					<!--{assign var=key value="search_pedding"}-->
					<select name="<!--{$key}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->">
					<option value="">選択してください</option>
					<!--{html_options options=$arrReadyList selected=$arrForm[$key].value}-->
					</select>
				</td>
			</tr>
			<tr>
				<td colspan=4>
					<input type="hidden" name="search_todays_shipping" value="1">
					<div class="btn">
						<p class="page_rows">検索結果表示件数
							<!--{assign var=key value="search_page_max"}-->
							<span class="attention"><!--{$arrErr[$key]}--></span>
							<select name="<!--{$arrForm[$key].keyname}-->" style="<!--{$arrErr[$key]|sfGetErrorColor}-->">
								<!--{html_options options=$arrPageMax selected=$arrForm[$key].value|default:$smarty.const.SEARCH_PMAX}-->
							</select> 件
						</p>
						<div class="btn-area">
							<ul><li><a class="btn-action" href="javascript:;" onclick="eccube.fnFormModeSubmit('search_form', 'search', '', ''); return false;"><span class="btn-next">この条件で検索する</span></a></li></ul>
						</div>
					</div>
				</td>
			</tr>
		</table>
		<!--検索条件設定テーブルここまで-->
	</form>

	<!--{*if count($arrErr) == 0 and ($smarty.post.mode == 'search' or $smarty.post.mode == 'delete')*}-->
	<!--{if ($smarty.post.mode == 'search' or $smarty.post.mode == 'delete')}-->
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

			<!-- <h2>本日出荷対象一覧（未出荷分）</h2> -->
			<h1 class="sub-title">●出荷準備中一覧</h1>
			<br />
			
			<div class="btn">
				<span class="attention"><!--検索結果数--><!--{$tpl_linemax}-->件</span>&nbsp;が該当しました。
			</div>
			
			<!--{if count($arrResults) > 0}-->
				<!--{include file=$tpl_pager}-->

				<!--{* 検索結果表示テーブル *}-->
				<table class="list">
					<col width="10%" />
					<col width="10%" />
					<col width="15%" />
					<col width="40%" />
					<col width="15%" />
					<col width="10%" />
					<tr>
						<th>注文番号</th>
						<th>顧客ID</th>
						<th>お名前</th>
						<th>住所</th>
						<th>支払方法</th>
						<th>購入金額(円)</th>
					</tr>

					<!--{section name=cnt loop=$arrResults}-->
						<tr style="background:<!--{$arrORDERSTATUS_COLOR[$status]}-->;">
							<td><!--{$arrResults[cnt].order_id|h}--></td>
							<td><!--{$arrResults[cnt].customer_id|h}--></td>
							<td><!--{$arrResults[cnt].order_name01|h}--></td>
							<td><!--{$arrPref[$arrResults[cnt].order_pref]|h}--><!--{$arrResults[cnt].order_addr01|h}--><!--{$arrResults[cnt].order_addr02|h}--></td>
							<td><!--{$arrResults[cnt].payment_method}--></td>
							<td class="right"><!--{$arrResults[cnt].payment_total|number_format}--></td>
						</tr>
					<!--{/section}-->
				</table>
				
				<!--{* 検索結果表示テーブル *}-->
			<!--{/if}-->
		</form>
	<!--{/if}-->
</div>
