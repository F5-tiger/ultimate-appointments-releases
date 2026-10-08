<?php
/**
 * 通知卡片：一份內容，三個渲染器。
 *
 * ⚠️ **這支存在的理由就是「不要讓三個管道各寫一份」。**
 * 同一則通知會出現在三個地方：
 *
 *   1. LINE —— Flex 卡片（UAPPT_Line::push_flex）
 *   2. Email —— 純文字（LINE 不可用時降級，見 UAPPT_Reminders::send_customer_message）
 *   3. 設定頁 —— 給店家看的預覽
 *
 * 三者若各自組內容，改一個欄位就要記得改三個地方，遲早有一邊漏掉——而且漏掉
 * 的那一邊通常是 Email，因為平常測試都在看 LINE。所以內容只在 build() 組一次，
 * 底下三支 render_* 各自把同一份結構畫成自己的樣子。
 *
 * 結構刻意做得很窄（標題／問候語／若干列／一顆按鈕），不是通用的 Flex 產生器。
 * 通用化會讓呼叫端有太多自由度，最後又變回「每則通知長得不一樣」。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Card {

	/**
	 * 卡片外觀設定的 option 鍵。
	 */
	const OPTION = 'uappt_card_style';

	/**
	 * 三種客人向通知。店家每日摘要刻意不在此列——它是推給店家自己的清單，
	 * 是多筆資料的彙整，硬塞進「一張卡片配一組欄位」的結構只會變形。
	 */
	const KIND_DAY          = 'day';
	const KIND_HOUR         = 'hour';
	const KIND_STAFF_CHANGE = 'staff_change';

	/**
	 * 外觀設定的預設值。
	 *
	 * 表頭色預設用 LINE 的品牌綠（#06C755）而不是外掛的主色：這張卡片出現在
	 * LINE 的對話串裡，跟著 LINE 的視覺走比較不突兀，店家想換再自己換。
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'header_color'   => '#06C755',
			'greeting'       => __( '{customer_name} 您好', 'ultimate-appointments' ),
			'button_text'    => __( '加入行事曆', 'ultimate-appointments' ),
			'pay_button_text' => __( '前往付款', 'ultimate-appointments' ),
			'title_day'      => __( '預約提醒', 'ultimate-appointments' ),
			'title_hour'     => __( '即將開始', 'ultimate-appointments' ),
			'title_staff'    => __( '服務人員異動', 'ultimate-appointments' ),
		);
	}

	/**
	 * 讀外觀設定（補齊預設值）。
	 *
	 * @return array
	 */
	public static function style() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * 清洗外觀設定。
	 *
	 * @param mixed $raw 表單送上來的值。
	 * @return array
	 */
	public static function sanitize_style( $raw ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$clean = self::defaults();

		$color = isset( $raw['header_color'] ) ? sanitize_hex_color( $raw['header_color'] ) : '';
		if ( $color ) {
			$clean['header_color'] = $color;
		}

		foreach ( array( 'greeting', 'button_text', 'pay_button_text', 'title_day', 'title_hour', 'title_staff' ) as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $raw[ $key ] ) );
			// 留空＝回到預設值，不要讓卡片出現空白標題。
			if ( '' !== $value ) {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * 組出一則通知的內容結構。這是三個渲染器唯一的資料來源。
	 *
	 * @param array  $booking    預約紀錄。
	 * @param string $kind       KIND_* 之一。
	 * @param string $extra      人員異動時傳新人員姓名；其餘不用。
	 * @return array {
	 *     @type string $title    卡片標題。
	 *     @type string $greeting 問候語（已代入變數）。
	 *     @type array  $rows     [['label'=>, 'value'=>], …]。
	 *     @type array  $button   ['label'=>, 'url'=>]；沒有按鈕時為空陣列。
	 *     @type string $alt      LINE 推播通知列顯示的摘要（Flex 必填）。
	 * }
	 */
	public static function build( $booking, $kind = self::KIND_DAY, $extra = '' ) {
		$style = self::style();

		$title_map = array(
			self::KIND_DAY          => $style['title_day'],
			self::KIND_HOUR         => $style['title_hour'],
			self::KIND_STAFF_CHANGE => $style['title_staff'],
		);
		$title = isset( $title_map[ $kind ] ) ? $title_map[ $kind ] : $style['title_day'];

		$service  = UAPPT_Booking::get_booking_display_name( $booking );
		$datetime = UAPPT_Admin::format_booking_label( $booking );

		// ⚠️ 服務名稱可能是空的（商品被刪、或資料不完整）。空字串會讓卡片出現
		// 一列只有標籤沒有值的空洞，比寫「未指定」更難看懂。
		if ( '' === trim( (string) $service ) ) {
			$service = __( '（未指定服務）', 'ultimate-appointments' );
		}

		$rows = array(
			array(
				'label' => __( '服務項目', 'ultimate-appointments' ),
				'value' => $service,
			),
			array(
				'label' => __( '預約時間', 'ultimate-appointments' ),
				'value' => $datetime,
			),
		);

		// 服務人員。
		//
		// ⚠️ **只有客人主動指定過才顯示**（staff_requested）。系統為了不超賣，
		// 即使客人選「不指定」也會在內部排定一位，但那不是對客人的承諾——把暫定
		// 的名字印給客人看，之後調度換人就變成「說好的人被換掉」的客訴。
		// 這條規則跟購物車頁的 display_cart_item_data() 是同一條，改要一起改。
		//
		// 人員異動通知是唯一的例外：那則的重點就是「誰」，一定要顯示。
		$staff_name = '';
		if ( self::KIND_STAFF_CHANGE === $kind ) {
			$staff_name = (string) $extra;
		} elseif ( ! empty( $booking['staff_requested'] ) && ! empty( $booking['staff_id'] ) ) {
			$staff = UAPPT_Staff::get( (int) $booking['staff_id'] );
			$staff_name = $staff ? (string) $staff['name'] : '';
		}
		if ( '' !== trim( $staff_name ) ) {
			$rows[] = array(
				'label' => __( '服務人員', 'ultimate-appointments' ),
				'value' => $staff_name,
			);
		}

		// 金額與付款。待付款與已付款要講不一樣的話——對還沒付錢的人印
		// 「金額 NT$1,340　付款方式 刷卡」，他看不出來是已經付了還是要付這麼多。
		$awaiting = UAPPT_Admin::is_awaiting_payment( $booking );
		$amount   = isset( $booking['amount'] ) ? (float) $booking['amount'] : 0.0;

		if ( $amount > 0 ) {
			$rows[] = array(
				'label' => $awaiting
					? __( '應付金額', 'ultimate-appointments' )
					: __( '金額', 'ultimate-appointments' ),
				'value' => html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ),
			);
		}

		if ( $awaiting ) {
			$rows[] = array(
				'label' => __( '付款狀態', 'ultimate-appointments' ),
				'value' => __( '待付款', 'ultimate-appointments' ),
			);
		} else {
			// 收款方式沒填就整列不印。站上實測有 64 筆是「未指定」，那三個字
			// 出現在客人的卡片上像是系統壞了，不如不要那一列。
			//
			// ⚠️ **用 get() 而不是 label()。** label() 對找不到的 slug 會回
			// 「card3（已刪除）」——那是給後台看的，店家需要知道這筆用的方式
			// 已經被刪掉。但客人看到「付款方式：card3（已刪除）」只會覺得壞了。
			// 店家一旦刪掉某個收款方式，引用它的舊預約就會走到這裡（站上光是
			// 「刷卡－分期 3 期」就有 87 筆）。查不到就跟沒填一樣，整列不印。
			$slug   = isset( $booking['payment_method'] ) ? (string) $booking['payment_method'] : '';
			$method = '' !== $slug ? UAPPT_Payment::get( $slug ) : null;
			if ( $method ) {
				$rows[] = array(
					'label' => __( '付款方式', 'ultimate-appointments' ),
					'value' => $method['label'],
				);
			}
		}

		return array(
			'title'    => $title,
			'greeting' => self::render_greeting( $style['greeting'], $booking ),
			'rows'     => $rows,
			'button'   => self::build_button( $booking, $style, $awaiting ),
			'alt'      => sprintf( '【%1$s】%2$s %3$s', $title, $datetime, $service ),
		);
	}

	/**
	 * 問候語的變數代換。
	 *
	 * @param string $template 樣板。
	 * @param array  $booking  預約紀錄。
	 * @return string
	 */
	protected static function render_greeting( $template, $booking ) {
		$name = trim( (string) ( isset( $booking['customer_name'] ) ? $booking['customer_name'] : '' ) );
		if ( '' === $name && ! empty( $booking['customer_id'] ) ) {
			$user = get_user_by( 'id', (int) $booking['customer_id'] );
			$name = $user ? $user->display_name : '';
		}

		return trim(
			strtr(
				(string) $template,
				array(
					'{customer_name}' => $name,
					'{shop_name}'     => get_bloginfo( 'name' ),
				)
			)
		);
	}

	/**
	 * 決定按鈕。
	 *
	 * ⚠️ **待付款時換成「前往付款」，但收現金的除外。**
	 *
	 * is_awaiting_payment() 是「status = held 且掛了訂單」。線上結帳走 ATM、
	 * 超商代碼、轉帳這類非即時付款時會落在這一格，那些人確實該看到付款連結。
	 *
	 * 但「錢在店裡收」的情況不該叫他去線上付款。目前有兩層保護：
	 *
	 * 1. 店員手動建單沒有 order_id，根本不會被判定成待付款（站上 761 筆手動
	 *    建單中有 223 筆是現金）。
	 * 2. 萬一哪天啟用了貨到付款之類的金流，狀態可能卡在 held——所以這裡**再問
	 *    一次收款方式**，是現金就維持行事曆按鈕。靠狀態剛好對是運氣，靠語意
	 *    才是保證。
	 *
	 * @param array $booking  預約紀錄。
	 * @param array $style    外觀設定。
	 * @param bool  $awaiting 是否待付款。
	 * @return array
	 */
	protected static function build_button( $booking, $style, $awaiting ) {
		if ( $awaiting && ! self::is_pay_on_site( $booking ) ) {
			$order = ! empty( $booking['order_id'] ) ? wc_get_order( (int) $booking['order_id'] ) : null;
			if ( $order ) {
				return array(
					'label' => $style['pay_button_text'],
					'url'   => $order->get_checkout_payment_url(),
				);
			}
		}

		$url = UAPPT_Calendar::get_ics_url( $booking );
		if ( '' === $url ) {
			return array();
		}

		return array(
			'label' => $style['button_text'],
			'url'   => $url,
		);
	}

	/**
	 * 這筆預約的錢是不是在店裡收的。
	 *
	 * 比對收款方式的費率設定——現金類（線上收不到的）一律不導去付款頁。
	 * 判斷依據是 slug 而不是顯示名稱：店家可以把「現金」改名成「到店付款」，
	 * 改名不該讓這個判斷失效。
	 *
	 * @param array $booking 預約紀錄。
	 * @return bool
	 */
	protected static function is_pay_on_site( $booking ) {
		$slug = isset( $booking['payment_method'] ) ? (string) $booking['payment_method'] : '';

		/**
		 * 哪些收款方式代表「錢在店裡收」。
		 *
		 * @param string[] $slugs 收款方式 slug。
		 */
		$on_site = apply_filters( 'uappt_pay_on_site_methods', array( 'cash' ) );

		return in_array( $slug, (array) $on_site, true );
	}

	/* ---------------------------------------------------------------------
	 * 渲染器：同一份 build() 的結果，三種畫法
	 * ------------------------------------------------------------------- */

	/**
	 * 畫成 LINE 的 Flex 卡片。
	 *
	 * altText 是必填的：它是手機通知列上顯示的那一行，也是舊版 LINE 或不支援
	 * Flex 的環境唯一看得到的內容。省略會被 API 退回。
	 *
	 * @param array $content build() 的結果。
	 * @return array 可直接放進 messages 陣列的訊息物件。
	 */
	public static function render_flex( array $content ) {
		$style = self::style();
		$color = $style['header_color'];

		$body = array();

		if ( '' !== $content['greeting'] ) {
			$body[] = array(
				'type' => 'text',
				'text' => $content['greeting'],
				'size' => 'sm',
				'wrap' => true,
			);
			$body[] = array(
				'type'   => 'separator',
				'margin' => 'md',
			);
		}

		foreach ( $content['rows'] as $i => $row ) {
			$body[] = array(
				'type'     => 'box',
				'layout'   => 'baseline',
				'margin'   => 0 === $i && '' === $content['greeting'] ? 'none' : 'md',
				'contents' => array(
					array(
						'type'  => 'text',
						'text'  => $row['label'],
						'size'  => 'sm',
						'color' => '#8c8c8c',
						// flex 固定成 2：標籤欄寬一致，值才會對齊成一直行。
						// 讓它自動撐開的話，「金額」與「服務項目」的值會左右參差。
						'flex'  => 2,
					),
					array(
						'type'  => 'text',
						'text'  => $row['value'],
						'size'  => 'sm',
						'color' => '#111111',
						'flex'  => 4,
						'wrap'  => true,
					),
				),
			);
		}

		$bubble = array(
			'type'   => 'bubble',
			'header' => array(
				'type'            => 'box',
				'layout'          => 'vertical',
				'backgroundColor' => $color,
				'paddingAll'      => '16px',
				'contents'        => array(
					array(
						'type'   => 'text',
						'text'   => get_bloginfo( 'name' ),
						'color'  => '#ffffff',
						'size'   => 'sm',
						'weight' => 'bold',
					),
					array(
						'type'   => 'text',
						'text'   => $content['title'],
						'color'  => '#ffffff',
						'size'   => 'xl',
						'weight' => 'bold',
						'margin' => 'sm',
						'wrap'   => true,
					),
				),
			),
			'body'   => array(
				'type'     => 'box',
				'layout'   => 'vertical',
				'contents' => $body,
			),
		);

		if ( ! empty( $content['button'] ) ) {
			$bubble['footer'] = array(
				'type'     => 'box',
				'layout'   => 'vertical',
				'contents' => array(
					array(
						'type'   => 'button',
						'style'  => 'primary',
						'color'  => $color,
						'height' => 'sm',
						'action' => array(
							'type'  => 'uri',
							'label' => $content['button']['label'],
							'uri'   => $content['button']['url'],
						),
					),
				),
			);
		}

		return array(
			'type'     => 'flex',
			'altText'  => self::trim_alt( $content['alt'] ),
			'contents' => $bubble,
		);
	}

	/**
	 * 畫成純文字（Email 降級用）。
	 *
	 * ⚠️ 這一支存在的意義是「跟卡片同步」。Email 收到的內容必須是同一份資料，
	 * 只是換一種排版——所以它吃的是 build() 的結果，不是另一套樣板。
	 *
	 * 按鈕在純文字裡沒有對應物，只能把網址攤開來放在最後一行。
	 *
	 * @param array $content build() 的結果。
	 * @return string
	 */
	public static function render_text( array $content ) {
		$lines = array();

		if ( '' !== $content['greeting'] ) {
			$lines[] = $content['greeting'];
			$lines[] = '';
		}

		foreach ( $content['rows'] as $row ) {
			$lines[] = $row['label'] . '：' . $row['value'];
		}

		if ( ! empty( $content['button'] ) ) {
			$lines[] = '';
			$lines[] = $content['button']['label'] . '：' . $content['button']['url'];
		}

		$lines[] = '';
		$lines[] = get_bloginfo( 'name' );

		return implode( "\n", $lines );
	}

	/**
	 * altText 的長度上限。
	 *
	 * LINE 的規格是 400 字元，超過直接被 API 退回整則訊息——而 altText 是我們
	 * 自己用標題＋時間＋服務名稱組出來的，服務名稱可以很長，所以一定要截。
	 *
	 * @param string $alt 原始摘要。
	 * @return string
	 */
	protected static function trim_alt( $alt ) {
		$alt = trim( preg_replace( '/\s+/u', ' ', (string) $alt ) );
		return mb_strlen( $alt ) > 400 ? mb_substr( $alt, 0, 399 ) . '…' : $alt;
	}

	/**
	 * 給設定頁預覽用的範例資料。
	 *
	 * ⚠️ 只回傳「跟外觀設定無關」的部分（資料列與是否待付款）。標題、問候語、
	 * 按鈕文字與表頭色由前端直接讀表單當下的值——那才是「即時預覽」的意思。
	 * 若連那些也在這裡算好，使用者就得存檔才看得到變化。
	 *
	 * 列的組成刻意跟 build() 走同一套條件（有指定人員才顯示、未指定收款方式
	 * 不印、待付款講不一樣的話），否則預覽會比實際樂觀。
	 *
	 * ⚠️ 有兩個使用者：設定頁右側的即時預覽（經 window.UAPPT_CardPreview 給 JS），
	 * 以及後台「測試推播」的 build_sample()。改這裡兩邊都會動——這正是我們要的，
	 * 它們的職責就是「讓店家看到同一張卡片」，資料不該有兩份。
	 *
	 * @return array
	 */
	public static function preview_samples() {
		$service  = __( '臉部護理 – 深層保濕導入', 'ultimate-appointments' );
		$datetime = __( '2026-09-25 (週五) 14:00–15:15', 'ultimate-appointments' );
		$money    = html_entity_decode( wp_strip_all_tags( wc_price( 1800 ) ), ENT_QUOTES, 'UTF-8' );

		$base = array(
			array( 'label' => __( '服務項目', 'ultimate-appointments' ), 'value' => $service ),
			array( 'label' => __( '預約時間', 'ultimate-appointments' ), 'value' => $datetime ),
		);
		$staff = array( 'label' => __( '服務人員', 'ultimate-appointments' ), 'value' => __( '王小美', 'ultimate-appointments' ) );
		$paid  = array(
			array( 'label' => __( '金額', 'ultimate-appointments' ), 'value' => $money ),
			array( 'label' => __( '付款方式', 'ultimate-appointments' ), 'value' => __( '刷卡', 'ultimate-appointments' ) ),
		);

		return array(
			'day'          => array( 'rows' => array_merge( $base, array( $staff ), $paid ), 'awaiting' => false ),
			'hour'         => array( 'rows' => array_merge( $base, array( $staff ), $paid ), 'awaiting' => false ),
			'staff_change' => array( 'rows' => array_merge( $base, array( $staff ), $paid ), 'awaiting' => false ),
			// 待付款這一格只示範「付款那一段與按鈕會變」，其餘欄位必須跟其他
			// 三種一致——少一列服務人員的話，看的人會以為沒付款就不顯示人員。
			'awaiting'     => array(
				'rows' => array_merge(
					$base,
					array( $staff ),
					array(
						array( 'label' => __( '應付金額', 'ultimate-appointments' ), 'value' => $money ),
						array( 'label' => __( '付款狀態', 'ultimate-appointments' ), 'value' => __( '待付款', 'ultimate-appointments' ) ),
					)
				),
				'awaiting' => true,
			),
		);
	}

	/**
	 * 用範例資料組一張完整的卡片，給後台「測試推播」用。
	 *
	 * 資料列直接取自 preview_samples()，跟右側即時預覽是同一份——分成兩份的話，
	 * 改了預覽忘了改推播（或反過來）兩邊就會對不起來，而這兩個功能存在的理由
	 * 就是「讓店家確認卡片長什麼樣」，對不起來等於功能失效。
	 *
	 * ⚠️ **標題會多一個「（測試）」、altText 會多「【測試】」。** 這不是裝飾：
	 * 收件 ID 欄位明講可以填 C 開頭的**群組** ID，群組裡是真的員工。少了這個
	 * 標記，他們手機上會跳出一張跟真預約一模一樣的卡片，然後去找一筆不存在的單。
	 *
	 * ⚠️ 這裡讀的是**已儲存**的 style()，不是表單當下的值——測試推播與樣式設定
	 * 是兩個獨立的 <form>。設定頁的說明文字有寫「要先儲存」，改這裡要一起改。
	 *
	 * @param string $kind day / hour / staff_change / awaiting。
	 *                     awaiting 是預覽專用的假類型，不是 KIND_* 常數，
	 *                     它示範的是「按鈕與付款那兩列會變」。
	 * @return array 可直接餵給 render_flex() 或 render_text()。
	 */
	public static function build_sample( $kind = self::KIND_DAY ) {
		$style   = self::style();
		$samples = self::preview_samples();
		$sample  = isset( $samples[ $kind ] ) ? $samples[ $kind ] : $samples[ self::KIND_DAY ];

		$title_map = array(
			self::KIND_DAY          => $style['title_day'],
			self::KIND_HOUR         => $style['title_hour'],
			self::KIND_STAFF_CHANGE => $style['title_staff'],
			// 待付款沿用「前一天提醒」的標題，跟 admin-settings.js 的 titleFor() 一致。
			'awaiting'              => $style['title_day'],
		);
		$title = isset( $title_map[ $kind ] ) ? $title_map[ $kind ] : $style['title_day'];

		// 問候語的假名字要跟 admin-settings.js 的 greeting() 一樣是「王小美」，
		// 否則店家會發現預覽寫王小美、手機上寫別的，開始懷疑哪個才是真的。
		$greeting = trim(
			strtr(
				(string) $style['greeting'],
				array(
					'{customer_name}' => __( '王小美', 'ultimate-appointments' ),
					'{shop_name}'     => get_bloginfo( 'name' ),
				)
			)
		);

		// 範例沒有真的預約，開不出 .ics，也沒有訂單可以付款。按鈕一律指向會員的
		// 「我的預約」——這個測試要驗的是「URI 按鈕在這個頻道點得開」，指到一個
		// 會 404 的網址反而會讓店家以為設定壞了。
		$url = wc_get_page_permalink( 'myaccount' );
		if ( ! $url ) {
			$url = home_url( '/' );
		}

		return array(
			'title'    => sprintf(
				/* translators: %s: 卡片標題 */
				__( '%s（測試）', 'ultimate-appointments' ),
				$title
			),
			'greeting' => $greeting,
			'rows'     => $sample['rows'],
			'button'   => array(
				'label' => ! empty( $sample['awaiting'] ) ? $style['pay_button_text'] : $style['button_text'],
				'url'   => $url,
			),
			'alt'      => sprintf(
				/* translators: %s: 卡片標題 */
				__( '【測試】%s', 'ultimate-appointments' ),
				$title
			),
		);
	}

	/**
	 * 測試推播可以送的類型，含預覽專用的 awaiting。
	 *
	 * @return array
	 */
	public static function sample_kinds() {
		return array(
			self::KIND_DAY          => __( '前一天提醒', 'ultimate-appointments' ),
			self::KIND_HOUR         => __( '服務前提醒', 'ultimate-appointments' ),
			self::KIND_STAFF_CHANGE => __( '服務人員異動', 'ultimate-appointments' ),
			'awaiting'              => __( '待付款（按鈕會變）', 'ultimate-appointments' ),
		);
	}
}
