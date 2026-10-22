<!--{*
/*
 * 定期コース香り・数量変更完了ページ
 */
*}-->
<div class="mypage_main">
    <div class="navi_path">
        <ul>
            <li><a href="./index.php?login=success" rel="external">マイページ</a></li>
            <li>
                <!--{$tpl_subtitle}-->
            </li>
        </ul>
    </div>

    <h2 class="mypage_subtitle alignC">
        <!--{$tpl_subtitle}-->
    </h2>

    <div class="mypage_contents">
        <div class="complete_area">
            <p class="complete_message">
                定期コースの香り・数量の変更を承りました。<br>
                今後ともよろしくお願いいたします。
            </p>
            <p class="redirect_message">
                <span id="countdown">5</span>秒後にマイページへ自動的に移動します。
            </p>

            <div class="btn_area">
                <p class="back_link"><a href="javascript:void(0);"
                        onclick="window.location.href='./index.php'; return false;">マイページに戻る</a></p>
            </div>
        </div>

        <script>
            var seconds = 5;
            var countdownElement = document.getElementById('countdown');

            var interval = setInterval(function() {
                seconds--;
                countdownElement.textContent = seconds;
                if (seconds <= 0) {
                    clearInterval(interval);
                    window.location.href = './index.php';
                }
            }, 1000);
        </script>
    </div>
</div>

<script type="text/javascript">
    if (typeof gtag === 'function') {
        gtag('event', 'teiki_fragrance_change_complete', {
            'from_source': '<!--{$tpl_from}-->'
        });
    }
</script>

<style>
    .navi_path ul li:first-child::after {
        content: "/";
        color: #959595;
        margin: 0 5px;
    }

    .complete_area {
        padding: 40px 20px;
        text-align: center;
        background: #f9f9f9;
        border-radius: 8px;
    }

    .complete_message {
        font-size: 1.2em;
        font-weight: bold;
        margin-bottom: 30px;
        color: #333;
    }

    .back_link a {
        display: inline-block;
        padding: 10px 20px;
        background-color: #666;
        color: #fff;
        text-decoration: none;
        border-radius: 4px;
    }

    .back_link a:hover {
        opacity: 0.9;
    }
</style>
