<!--{*
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
 *}-->
<!--{$arrOrder.order_name01}--> 様

<!--{$tpl_header}-->

<!--{if $smarty.session.is_cross_include}-->
【重要】
※先ほどご注文いただきました商品はキャンセルさせていただきました。
重複はございませんので、ご安心くださいませ。

<!--{/if}-->
************************************************
　配送情報
************************************************
<!--{foreach item=shipping name=shipping from=$arrShipping}-->
◎お届け先<!--{if count($arrShipping) > 1}--><!--{$smarty.foreach.shipping.iteration}--><!--{/if}-->

お名前　：<!--{$shipping.shipping_name01}--> 様
郵便番号：〒<!--{$shipping.shipping_zip01}-->
住所　　：<!--{$arrPref[$shipping.shipping_pref]}--><!--{$shipping.shipping_addr01}--><!--{$shipping.shipping_addr02}-->
電話番号：<!--{$shipping.shipping_tel01}-->
お届け予定日：<!--{$shipping.shipping_date|date_format:"%Y年%-m月%-d日"|default:"指定なし"}-->
お届け時間：<!--{$shipping.shipping_time|default:"指定なし"}-->

<!--{/foreach}-->

************************************************
　ご請求金額
************************************************
ご注文番号：<!--{$arrOrder.order_id}-->
お支払合計：￥ <!--{$arrOrder.payment_total|number_format|default:0}-->
ご決済方法：<!--{$arrOrder.payment_method}-->
メッセージ：<!--{$Message_tmp}-->

************************************************
　ご注文商品明細
************************************************
<!--{section name=cnt loop=$arrOrderDetail}-->
<!--{math equation="x/y" x="`$arrOrderDetail[cnt].tax_rate`" y='100' assign='tax'}--><!--{math equation="a+(a*b)" a=$arrOrderDetail[cnt].price b=$tax assign=price}-->
<!--{if $cnt == 0}-->コース名：<!--{$arrOrderDetail[cnt].name_official}--><!--{/if}-->

商品名: <!--{$arrOrderDetail[cnt].product_name}--> <!--{if $arrOrderDetail[cnt].option_name != ""}--><!--{$arrOrderDetail[cnt].option_name}--><!--{/if}-->

単価：￥ <!--{$price|number_format}-->
数量：<!--{$arrOrderDetail[cnt].quantity}-->
<!--{if $arrOrderDetail[cnt].shibari_num > 0}-->
※本コースは<!--{$arrOrderDetail[cnt].shibari_num}-->個分の商品お受け取りをお願いしております。
規定本数（<!--{$arrOrderDetail[cnt].shibari_num}-->個）未満でのご解約の場合、
キャンセル料金が5,500円発生いたします。
<!--{/if}-->
<!--{/section}-->
-------------------------------------------------
小　計 ￥ <!--{$arrOrder.subtotal|number_format|default:0}--> (うち消費税 ￥<!--{$arrOrder.tax|number_format|default:0}-->）
値引き ￥ <!--{$arrOrder.discount|number_format|default:0}-->
送　料 ￥ <!--{$arrOrder.deliv_fee|number_format|default:0}-->
手数料 ￥ <!--{$arrOrder.charge|number_format|default:0}-->
============================================
合　計 ￥ <!--{$arrOrder.payment_total|number_format|default:0}-->
============================================

<!--{$tpl_footer}-->