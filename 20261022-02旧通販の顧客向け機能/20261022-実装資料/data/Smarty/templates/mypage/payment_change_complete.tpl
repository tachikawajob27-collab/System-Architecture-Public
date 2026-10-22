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

<link rel="stylesheet" href="../css/payment_change.css?v=<!--{$smarty.now}-->" type="text/css" />
<style>
	body {
		font-family: "Segoe UI", sans-serif;
		background: #f9fafb;
		margin: 0;
	}
	h1 {
		font-size: 20px;
		margin: 16px 0 8px;
		color: #1e3a8a;
	}
	p {
		color: #475569;
		font-size: 14px;
		line-height: 1.6;
	}
	a {
		padding: 12px;
		border: none;
		border-radius: 8px;
		font-size: 14px;
		cursor: pointer;
		transition: background 0.2s;
	}
	#main-content {
		display: flex;
		justify-content: center;
		align-items: center;
		height: 100vh;
	}
</style>

<!--{if $smarty.get.mode == 'error'}-->
	<div class="card">
		<div class="icon">⚠️</div>
		<h1>お支払い方法を変更できませんでした</h1>
		<p>入力情報や通信状況により、変更が反映されませんでした。<br>
		この操作でご注文や請求は行われていませんので、ご安心ください。</p>
		<div class="actions">
			<a class="primary" href="./payment_change.php" rel="external">別のお支払い方法を選ぶ</a>
		</div>
	</div>
<!--{else}-->
	<div class="card">
		<div class="icon">✅</div>
		<h1>お支払い方法を変更しました</h1>
		<p>新しいお支払い方法が正常に登録されました。<br>
		次回のご注文分より自動的に適用されます</p>
		<div class="actions">
			<a class="home" href="<!--{$brandsite}-->" rel="external">ホームページ</a>
		</div>
	</div>
<!--{/if}-->