<div class="mypage_main">
    <div class="navi_path">
        <ul>
            <li><a href="<!--{$smarty.const.MYPAGE_ROOT_PATH}-->index.php?login=success">マイページ</a></li>
            <li><!--{$tpl_subtitle}--></li>
        </ul>
    </div>

    <h2 class="mypage_subtitle alignC"><!--{$tpl_subtitle}--></h2>

    <!-- ▼ 香りチャートへの導線バナー（PCでグレー枠に入らないようにコンテンツ外へ配置） -->
    <!--{if $smarty.session.category_group_id != 2}-->
    <div class="fragrance_banner">
        <a href="<!--{$smarty.const.ROOT_URLPATH}-->mypage/fragrance_chart.php" style="display: block; transition: opacity 0.3s; /* hoverアクション用 */" onmouseover="this.style.opacity=0.8" onmouseout="this.style.opacity=1">
            <img src="<!--{$smarty.const.ROOT_URLPATH}-->user_data/packages/img/mypage/fragrance_chart_link.jpg" alt="香りチャートを見る">
        </a>
    </div>
    <!--{/if}-->
    <!-- ▲ 香りチャートへの導線バナー -->

    <div class="mypage_contents">
        <!--{if $tpl_no_data}-->
            <p style="text-align: center; margin: 30px 0;">香りを変更可能な定期コースが見つかりませんでした。</p>
            <div class="info_btn_area">
                <a class="back_btn" href="<!--{$smarty.const.ROOT_URLPATH}-->mypage/index.php?login=success" rel="external"><span class="material-icons">keyboard_double_arrow_left</span>ホームへ戻る</a>
            </div>
        <!--{else}-->
            <p style="text-align: center; margin-bottom: 20px;">香りを変更する定期コースを選択してください。</p>

            <!--{foreach item=teiki from=$arrTeikiList}-->
                <table class="teiki_course_table" style="margin-bottom: 10px;">
                    <colgroup>
                        <col width="30%">
                        <col width="70%">
                    </colgroup>
                    <tbody>
                        <tr>
                            <th>コース名</th>
                            <td><!--{$teiki.course_name|h}--></td>
                        </tr>
                        <tr>
                            <th>商品名</th>
                            <td><!--{$teiki.product_name|h}--><!--{$teiki.option_name}--></td>
                        </tr>
                        <tr>
                            <th>次回お届け予定日</th>
                            <td class="next_shipping_date">
                                <!--{$teiki.next_shipping_date|date_format:"%Y/%m/%d"}-->
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="btn_area" style="text-align: center; margin-bottom: 40px; margin-top: 10px;">
                    <a href="<!--{$smarty.const.MYPAGE_ROOT_PATH}-->teiki_fragrance.php?teiki_id=<!--{$teiki.teiki_id}-->&teiki_detail_id=<!--{$teiki.detail_id}-->&from_top=1" style="background-color: <!--{if $smarty.session.category_group_id == 2}-->#5F93CC<!--{else}-->#cd6872<!--{/if}-->; color: white; display: inline-block; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: bold;">こちらの香りを変更する</a>
                </div>
            <!--{/foreach}-->

            <div class="info_btn_area">
                <a class="back_btn" href="<!--{$smarty.const.ROOT_URLPATH}-->mypage/index.php?login=success" rel="external"><span class="material-icons">keyboard_double_arrow_left</span>ホームへ戻る</a>
            </div>
        <!--{/if}-->
    </div>
</div>
