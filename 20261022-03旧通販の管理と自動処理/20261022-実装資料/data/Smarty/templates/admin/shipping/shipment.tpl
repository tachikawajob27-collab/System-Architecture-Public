<script type="text/javascript">
<!--
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
		win02("about:blank", "pdf_input", "620","650");

		// 退避
		tmpTarget = fm.target;
		tmpMode = fm.mode.value;
		tmpAction = fm.action;

		fm.target = "pdf_input";
		fm.mode.value = 'pdf';
		fm.action = action;
		fm.submit();
		WIN.focus();

		// 復元
		fm.target = tmpTarget;
		fm.mode.value = tmpMode;
		fm.action = tmpAction;
	}
	$(document).ready(function(){
		// 出荷準備解除時に確認を一度挟む
		$('.btn_restore').click(function(){
			if(!confirm('出荷準備中状態を解除します。よろしいですか？')){
				return false;
			} else {
				eccube.fnFormModeSubmit('search_form',$(this).attr("id"),'','');
				//return false;
			}
		});

		//画面上のキー入力イベントリスナ
		$(window).keyup(function(e) {
			//キー入力を配列に追加
			inputKey.push(e.keyCode);
			var keys = inputKey.join(',');
			if (keys == $("#back_key").val()) {
				$("#irregular").show();
			}
		});
	});
	
//-->
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
</style>

<div id="order" class="contents-main">
	<form name="search_form" id="search_form" method="post" action="?" enctype="multipart/form-data">

		<input type="hidden" name="<!--{$smarty.const.TRANSACTION_ID_NAME}-->" value="<!--{$transactionid}-->" />
		<input type="hidden" name="mode" value="search" />
		<input type="hidden" id="pushKey" value="" />
		<input type="hidden" id="back_key" value="<!--{$background_id}-->" />

		<h2>出荷条件設定</h2>
		<table>
			<colgroup width="23%"></colgroup>
			<colgroup width="27%"></colgroup>
			<colgroup width="23%"></colgroup>
			<colgroup width="27%"></colgroup>
			
			<tr>
				<th>ゆうパケ・ゆうパック<br>お届け日設定ルール</th>
				<td colspan=3>
				月曜〜水曜：４日前<br>
				木曜　　　：５日前<br>
				金曜　　　：６日前<br>
				祝日前　　：各曜日のルール＋祝日の日数<br>
				　　　　　　（休日の祝日はカウントしない）
				</td>
			</tr>
			<tr>
				<th>お届け日(ゆうパケ・ゆうパック)</th>
				<td colspan=3>
					お届け日の
					<!--{assign var=key value="search_offset_date_packet"}-->
					<span class="attention"><!--{$arrErr[$key]}--></span>
					<select name="<!--{$key}-->">
						<!--{html_options options=$arrOffsetDate selected=$arrForm[$key].value}-->
					</select> 日前までを出荷する
				</td>
			</tr>
			
			<!--{* 機能♯5　出荷管理画面の商品名のセレクトボックスを商品カテゴリに変更：開始 *}-->
			<tr>
				<th>商品カテゴリ</th>
				<td colspan=3>
					<!--{assign var=key value="search_category_id"}-->
					<!--{html_options name="search_category_id[]" options=$search_category_ids selected=$arrForm[$key].value size=3 multiple="multiple" style="width:200px;"}-->
					<font color="blue">←Ctrlキーを押しながら複数選択できます。</font>
				</td>
			</tr>
			<!--{* 機能♯2　出荷管理画面の商品名のセレクトボックスを商品カテゴリに変更：終了 *}-->
			<tr>
				<th>出荷対象</th>
				<td colspan=3>
					<!--{assign var=key value="search_saisou"}-->
					<span class="attention"><!--{$arrErr[$key]}--></span>
					<select name="<!--{$key}-->">
					<!--{html_options options=$arrSAISOU selected=$arrForm[$key].value}-->
					</select>
					<span style="margin-left:25px">
						<input type="checkbox" id="pedding_check" name="pedding_check" value="1">
						<label for="pedding_check" style="vertical-align: text-bottom;">出荷準備中は含めない</label>
					</span>
				</td>
			</tr>
		</table>

		<p class="ship_prepar">本日の出荷準備件数：
			<span><!--{$intShipPreparCount}--></span>件
		</p>
		<p class="ship_prepar">準備金額：
			<span><!--{$intShipPreparTotalAmount|number_format}--></span>円
		</p>
		<h2>本日から15日間の出荷予定数（出荷準備中は含めない）</h2>
		<table>
			<tr>
				<td style="padding:0px;">
					<table summary="出荷予定件数一覧" style="margin:auto;">
						<col width="25%" />
						<col width="25%" />
						<col width="25%" />
						<col width="25%" />

						<tr>
							<th></th>
							<th style="text-align:center">出荷日</th>
							<th colspan="2" style="text-align:center">出荷件数</th>
						</tr>

						<!--{foreach from=$arrShipYtiData key="key" item="item" name=loop}-->
							<tr>
								<!--{if $smarty.foreach.loop.iteration == 1}-->
									<td style="text-align:center" rowspan="<!--{$count}-->">合計</td>
								<!--{/if}-->
								<td style="text-align:center"><!--{$key}--></td>
								<td colspan="2" style="text-align:right"><!--{$item|h}--> 件</td>
							</tr>
						<!--{/foreach}-->
					</table>
				</td>
			</tr>
		</table>

		<h2>1. ピッキングリスト</h2>

		<table>
			<tr>
				<th>ピッキングリスト</th>
				<td>
					<a class="btn-normal" href="javascript:;" onclick="eccube.fnFormModeSubmit('search_form','csv_pickinglist','',''); return false;">本日出荷分を出力</a>
				</td>
			</tr>
		</table>

		<h2>2. WMS 出荷データ連携</h2>
		
		<table>
			<tr>
				<th>出荷データ出力</th>
				<td>
					<div style="float:left;">
						<a class="btn-normal" href="javascript:;" onclick="eccube.fnFormModeSubmit('search_form','csv_wms','',''); return false;">本日出荷分を出力(出荷準備)</a>
						<a class="btn-normal" href="javascript:;" onclick="eccube.fnFormModeSubmit('search_form','csv_wms_re','',''); return false;">本日出荷分を出力(再出力)</a>
					</div>
					<div style="float:right;">
						<a class="btn-normal btn_restore" id="csv_wms_restore">出荷準備解除</a>
					</div>
				</td>
			</tr>
<!--{*
			<tr>
				<th>出荷データ出力(リアルタイム与信のみ)</th>
				<td>
					<div style="float:left;">
						<a class="btn-normal" href="javascript:;" onclick="eccube.fnFormModeSubmit('search_form','csv_wms_rest','',''); return false;">本日出荷分を出力(出荷準備)</a>
					</div>
				</td>
			</tr>
*}-->
			<tr>
				<th>請求書印字データ出力(バモス後払い用)</th>
				<td>
					<div style="float:left;">
						<a class="btn-normal" href="javascript:;" onclick="eccube.fnFormModeSubmit('search_form','csv_invoice_vamos','',''); return false;">本日出荷分を出力(出荷準備)</a>
					</div>
				</td>
			</tr>
			<tr><th>出荷履歴データ登録</th>
				<td>
					<input type="file" name="csv_upload_wms" size="40" />
					<a class="btn-normal" href="javascript:;" onclick="fnFormCheckSubmit('search_form', 'csv_upload_wms', '', ''); return false;">アップロード</a>
					<!--{assign var=key value="csv_upload_wms"}-->
					<span class="attention"><!--{$arrErr[$key]}--></span>
					<font color="blue"><!--{$arrUploadMsg[$key]}--></font>
				</td>
			</tr>
		</table>

		<h2>3. 倉庫返品登録</h2>
		<table>
			<tr>
				<th>倉庫返品登録</th>
				<td>
					<input type="file" name="csv_upload_returns" size="40" />
					<a class="btn-normal" href="javascript:;" onclick="eccube.fnFormModeSubmit('search_form','csv_upload_returns','', ''); return false;">アップロード</a>
					<!--{assign var=key value="csv_upload_returns"}-->
					<span class="attention"><!--{$arrErr[$key]}--></span>
					<font color="blue"><!--{$arrUploadMsg[$key]}--></font>
				</td>
			</tr>
		</table>

		<div id="irregular" style="display:none;">
			<h2>99. WMS 出荷データ連携（管理者用）</h2>
			<table>
				<tr>
					<th>出荷データ出力</th>
					<td>
						<div style="float:left;">
							<a class="btn-normal" href="javascript:;" onclick="eccube.fnFormModeSubmit('search_form','csv_wms_irregular','',''); return false;">本日出荷分を出力(イレギュラー)</a>
						</div>
					</td>
				</tr>
			</table>
		</div>
		
		<br />
		<h1 class="sub-title">●過去7日間の出荷履歴</h1>
		<br />
		
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

		<br />
		<h1 class="sub-title">●出荷準備データ検索</h1>
		<br />
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
					<input type="checkbox" id="<!--{$key}-->" name="<!--{$key}-->" value="1" <!--{if $arrForm[$key].value != ""}-->checked<!--{/if}-->>
					<label for="<!--{$key}-->" style="vertical-align: text-bottom;">出荷準備中のみ検索</label>
				</td>
			</tr>
		</table>

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
				<ul>
					<li><a class="btn-action" href="javascript:;" onclick="eccube.fnFormModeSubmit('search_form', 'search', '', ''); return false;"><span class="btn-next">この条件で検索する</span></a></li>
				</ul>
			</div>
		</div>
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
