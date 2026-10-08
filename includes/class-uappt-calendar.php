<?php
/**
 * 加入行事曆：產生 .ics 檔（Apple/Outlook/絕大多數行事曆）與 Google 行事曆連結。
 *
 * 兩種都提供的原因：iPhone 點 .ics 會直接進 Apple 行事曆最順；桌機與 Android
 * 使用者多半用 Google 行事曆，給它專用的 render 連結比下載檔案好操作。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Calendar {

	/**
	 * @var UAPPT_Calendar|null
	 */
	protected static $instance = null;

	/**
	 * 單例。
	 *
	 * @return UAPPT_Calendar
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 建構子：掛載 hooks。
	 */
	protected function __construct() {
		add_action( 'init', array( $this, 'maybe_output_ics' ) );

		// 結帳完成頁與訂單信：把該訂單裡每一筆預約的加入行事曆連結列出來。
		add_action( 'woocommerce_thankyou', array( $this, 'render_for_order' ), 20 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_for_order_email' ), 20, 4 );
	}

	/**
	 * 產生 .ics 下載網址。
	 *
	 * 帶一組不可猜測的 token，並且**刻意不要求登入**：客人常常是從 LINE 內建
	 * 瀏覽器或 email 裡點進來，那些情境往往沒有登入狀態，要求登入等於這個
	 * 功能大半時候都不能用。token 才是防止別人列舉他人預約的機制。
	 *
	 * @param array $booking 預約紀錄。
	 * @return string
	 */
	public static function get_ics_url( $booking ) {
		return add_query_arg(
			array(
				'uappt_ics' => (int) $booking['id'],
				'token'    => self::token( $booking['id'] ),
			),
			home_url( '/' )
		);
	}

	/**
	 * 產生 Google 行事曆的「新增活動」連結。
	 *
	 * @param array $booking 預約紀錄。
	 * @return string
	 */
	public static function get_google_url( $booking ) {
		$start = self::to_utc( $booking['service_start'] );
		$end   = self::to_utc( $booking['service_end'] );

		if ( ! $start || ! $end ) {
			return '';
		}

		return add_query_arg(
			rawurlencode_deep(
				array(
					'action'   => 'TEMPLATE',
					'text'     => self::event_title( $booking ),
					'dates'    => $start->format( 'Ymd\THis\Z' ) . '/' . $end->format( 'Ymd\THis\Z' ),
					'details'  => self::event_description( $booking ),
					'location' => self::store_address(),
				)
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	/**
	 * 輸出「加入行事曆」的兩個連結。
	 *
	 * ⚠️ **這支有五個呼叫端，其中兩個是 Email。** 改 markup 前先看清楚：
	 *
	 * | 呼叫端 | 樣式來源 | $context |
	 * | --- | --- | --- |
	 * | 會員「我的預約」表格 | frontend.css | `stacked` |
	 * | 後台預約編輯頁 | admin.css | `buttons` |
	 * | 結帳完成頁 `<li>` | frontend.css | `buttons`（橫排，跟時間同一行） |
	 * | 訂單信 HTML | **無** | `plain` |
	 * | 提醒信 HTML | **無** | `plain` |
	 *
	 * Email 吃不到外部 CSS，加 class 對它完全沒作用——所以按鈕化只能靠情境
	 * 分流，不能直接把 `.uappt-calendar-link` 本身改成按鈕。
	 *
	 * **不要在這裡塞 SVG 圖示**：五個呼叫端有四個把回傳值包在 `wp_kses_post()`
	 * 裡，而它預設不允許 `<svg>`，圖示會被整段吃掉。要圖示只能走 CSS。
	 *
	 * 標籤 v2.75.0 從「加入行事曆（Apple / Outlook）」「加入 Google 行事曆」
	 * 改成「Google 行事曆」「Apple / Outlook」。原本那 17 個字配上
	 * `white-space: nowrap`，等於把我的預約那一欄的最小寬度綁死——實測它吃掉
	 * 整張表 32% 的寬度（1168px 中的 379px），比「服務項目」和「時段」都寬，
	 * 但資訊價值最低。
	 *
	 * 兩顆一律用**對稱的產品名**，不要只寫「加入行事曆」：兩顆並排時，沒有
	 * 產品名的那顆會讓人看不懂到底是什麼行事曆。動詞「加入」不重複，因為
	 * 欄位標題與這一區的標籤已經是「行事曆」，而且它們長得就是按鈕。
	 *
	 * @param array  $booking 預約紀錄。
	 * @param string $context plain（預設，純連結）／buttons（外框按鈕，橫排）／
	 *                        stacked（外框按鈕，縱向等寬，給窄欄用）。
	 * @return string HTML。
	 */
	public static function render_links( $booking, $context = 'plain' ) {
		$ics    = self::get_ics_url( $booking );
		$google = self::get_google_url( $booking );

		if ( ! $ics && ! $google ) {
			return '';
		}

		$classes = array( 'uappt-calendar-links' );
		if ( 'buttons' === $context || 'stacked' === $context ) {
			$classes[] = 'uappt-calendar-links--buttons';
		}
		if ( 'stacked' === $context ) {
			$classes[] = 'uappt-calendar-links--stacked';
		}

		$html = sprintf( '<span class="%s">', esc_attr( implode( ' ', $classes ) ) );

		// Google 排在前面：台灣的 Android 與 Gmail 使用者是多數，最常按的那顆
		// 該在最上面。.ics 是涵蓋面最廣的那顆（Apple 行事曆、Outlook、
		// Thunderbird），但它是「其他人用的」，排第二。
		if ( $google ) {
			$html .= sprintf(
				'<a class="uappt-calendar-link" href="%1$s" target="_blank" rel="noopener">%2$s</a>',
				esc_url( $google ),
				esc_html__( 'Google 行事曆', 'ultimate-appointments' )
			);
		}
		if ( $ics ) {
			// 兩顆都直接講「是哪一家的行事曆」。只寫「加入行事曆」的話，旁邊
			// 擺著「Google 行事曆」會讓人看不懂這顆到底是什麼——對稱的產品名
			// 才有辦法讓人一眼選對。動詞「加入」不必重複：欄位標題與這一區的
			// 標籤已經是「行事曆」，而且它們現在長得就是按鈕。
			$html .= sprintf(
				'%1$s<a class="uappt-calendar-link" href="%2$s">%3$s</a>',
				$google ? ' ' : '',
				esc_url( $ics ),
				esc_html__( 'Apple / Outlook', 'ultimate-appointments' )
			);
		}

		$html .= '</span>';

		return $html;
	}

	/**
	 * 結帳完成頁輸出。
	 *
	 * @param int $order_id 訂單 ID。
	 */
	public function render_for_order( $order_id ) {
		$bookings = UAPPT_Booking::get_by_order( $order_id );
		if ( empty( $bookings ) ) {
			return;
		}

		echo '<section class="uappt-thankyou-calendar"><h2>' . esc_html__( '預約時段', 'ultimate-appointments' ) . '</h2><ul>';
		foreach ( $bookings as $booking ) {
			if ( in_array( $booking['status'], array( UAPPT_Booking::STATUS_CANCELLED, UAPPT_Booking::STATUS_EXPIRED ), true ) ) {
				continue;
			}
			// 結帳完成頁用 buttons（橫排）不用 stacked：這裡是 `<li>` 的行內情境，
			// 寬度充足，而且這一頁是客人最可能真的去加行事曆的時刻——剛訂完、
			// 時間還記得。做成按鈕比純文字連結更容易被按到。
			printf(
				'<li><span class="uappt-thankyou-time">%1$s</span>%2$s</li>',
				esc_html( UAPPT_Admin::format_booking_label( $booking ) ),
				wp_kses_post( self::render_links( $booking, 'buttons' ) )
			);
		}
		echo '</ul></section>';
	}

	/**
	 * 訂單信輸出（只寄給客人的信才附，管理員信不需要）。
	 *
	 * @param WC_Order $order         訂單。
	 * @param bool     $sent_to_admin 是否寄給管理員。
	 * @param bool     $plain_text    是否純文字信。
	 * @param WC_Email $email         信件物件。
	 */
	public function render_for_order_email( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}

		$bookings = UAPPT_Booking::get_by_order( $order->get_id() );
		if ( empty( $bookings ) ) {
			return;
		}

		foreach ( $bookings as $booking ) {
			if ( in_array( $booking['status'], array( UAPPT_Booking::STATUS_CANCELLED, UAPPT_Booking::STATUS_EXPIRED ), true ) ) {
				continue;
			}

			$label = UAPPT_Admin::format_booking_label( $booking );

			if ( $plain_text ) {
				printf(
					"\n%s\n%s\n%s\n",
					esc_html__( '預約時段', 'ultimate-appointments' ),
					esc_html( $label ),
					esc_url_raw( self::get_ics_url( $booking ) )
				);
				continue;
			}

			printf(
				'<p><strong>%1$s</strong>：%2$s<br />%3$s</p>',
				esc_html__( '預約時段', 'ultimate-appointments' ),
				esc_html( $label ),
				wp_kses_post( self::render_links( $booking ) )
			);
		}
	}

	/**
	 * 攔截 .ics 下載請求並輸出檔案。
	 */
	public function maybe_output_ics() {
		if ( empty( $_GET['uappt_ics'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$booking_id = absint( $_GET['uappt_ics'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token      = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// hash_equals() 而非 ===：避免用回應時間差一個字元一個字元試出正確 token。
		if ( ! $booking_id || ! hash_equals( self::token( $booking_id ), $token ) ) {
			wp_die( esc_html__( '連結無效或已失效。', 'ultimate-appointments' ), '', array( 'response' => 403 ) );
		}

		$booking = UAPPT_Booking::get( $booking_id );
		if ( ! $booking ) {
			wp_die( esc_html__( '找不到這筆預約。', 'ultimate-appointments' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="booking-' . $booking_id . '.ics"' );

		echo self::build_ics( $booking ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * 組出 .ics 內容。
	 *
	 * 時間一律輸出 UTC（Z 結尾）而不是帶 VTIMEZONE：各家行事曆對 VTIMEZONE 的
	 * 支援程度不一，UTC 是所有實作都吃得下的最小公分母，也不會有夏令時間問題。
	 *
	 * @param array $booking 預約紀錄。
	 * @return string
	 */
	protected static function build_ics( $booking ) {
		$start = self::to_utc( $booking['service_start'] );
		$end   = self::to_utc( $booking['service_end'] );
		$now   = new DateTime( 'now', new DateTimeZone( 'UTC' ) );

		if ( ! $start || ! $end ) {
			return '';
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			// PRODID 用英文名（v3.0.0）：規格允許 UTF-8，但有些行事曆 app 解析中文的
			// PRODID 會出錯，而且它是給程式看的識別，不是給人看的。
			'PRODID:-//Ultimate Appointments//TW',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			// UID 用預約 ID 組成且固定不變：改期後客人重新加入時，行事曆會辨識成
			// 「更新同一個活動」而不是多出一筆重複的預約。
			'UID:uappt-' . (int) $booking['id'] . '@' . $host,
			'DTSTAMP:' . $now->format( 'Ymd\THis\Z' ),
			'DTSTART:' . $start->format( 'Ymd\THis\Z' ),
			'DTEND:' . $end->format( 'Ymd\THis\Z' ),
			'SEQUENCE:' . self::sequence( $booking ),
			'SUMMARY:' . self::escape_ics( self::event_title( $booking ) ),
			'DESCRIPTION:' . self::escape_ics( self::event_description( $booking ) ),
			'LOCATION:' . self::escape_ics( self::store_address() ),
			'STATUS:CONFIRMED',
			// 讓客人的手機行事曆自己也在前一天提醒一次，不用完全依賴我們發的通知。
			'BEGIN:VALARM',
			'TRIGGER:-P1D',
			'ACTION:DISPLAY',
			'DESCRIPTION:' . self::escape_ics( self::event_title( $booking ) ),
			'END:VALARM',
			'END:VEVENT',
			'END:VCALENDAR',
		);

		// iCalendar 規格的換行是 CRLF。
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * SEQUENCE：每次改期都要比上次大，行事曆才會認定這是更新版本。
	 * 用 updated_at 的時間戳（除以 60 壓小數值）當版本號，天然遞增。
	 *
	 * @param array $booking 預約紀錄。
	 * @return int
	 */
	protected static function sequence( $booking ) {
		if ( empty( $booking['updated_at'] ) ) {
			return 0;
		}
		$dt = date_create( $booking['updated_at'], wp_timezone() );
		return $dt ? (int) floor( $dt->getTimestamp() / 60 ) : 0;
	}

	/**
	 * 活動標題。
	 *
	 * @param array $booking 預約紀錄。
	 * @return string
	 */
	protected static function event_title( $booking ) {
		$service = UAPPT_Booking::get_booking_display_name( $booking );

		return sprintf(
			/* translators: 1: 服務名稱 2: 店名 */
			__( '%1$s（%2$s）', 'ultimate-appointments' ),
			$service,
			get_bloginfo( 'name' )
		);
	}

	/**
	 * 活動描述。
	 *
	 * @param array $booking 預約紀錄。
	 * @return string
	 */
	protected static function event_description( $booking ) {
		$parts = array();

		$parts[] = sprintf(
			/* translators: %s: 服務名稱 */
			__( '服務項目：%s', 'ultimate-appointments' ),
			UAPPT_Booking::get_booking_display_name( $booking )
		);

		if ( ! empty( $booking['order_id'] ) ) {
			$parts[] = sprintf(
				/* translators: %d: 訂單編號 */
				__( '訂單編號：#%d', 'ultimate-appointments' ),
				(int) $booking['order_id']
			);
		}

		return implode( "\n", $parts );
	}

	/**
	 * 店家地址（取 WooCommerce 商店設定）。
	 *
	 * @return string
	 */
	protected static function store_address() {
		$parts = array_filter(
			array(
				get_option( 'woocommerce_store_address', '' ),
				get_option( 'woocommerce_store_address_2', '' ),
				get_option( 'woocommerce_store_city', '' ),
				get_option( 'woocommerce_store_postcode', '' ),
			)
		);

		return implode( ' ', $parts );
	}

	/**
	 * 把站台本地時間字串轉成 UTC 的 DateTime。
	 *
	 * @param string $local_datetime 'Y-m-d H:i:s' 本地時間字串。
	 * @return DateTime|null
	 */
	protected static function to_utc( $local_datetime ) {
		$dt = date_create( $local_datetime, wp_timezone() );
		if ( ! $dt ) {
			return null;
		}
		$dt->setTimezone( new DateTimeZone( 'UTC' ) );
		return $dt;
	}

	/**
	 * iCalendar 的文字跳脫規則（逗號、分號、反斜線要跳脫，換行寫成 \n）。
	 *
	 * @param string $text 原始文字。
	 * @return string
	 */
	protected static function escape_ics( $text ) {
		$text = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\\;', '\\,' ), (string) $text );
		return str_replace( array( "\r\n", "\n", "\r" ), '\\n', $text );
	}

	/**
	 * 產生該筆預約的下載 token。
	 *
	 * @param int $booking_id 預約 ID。
	 * @return string
	 */
	protected static function token( $booking_id ) {
		return wp_hash( 'uappt_ics_' . (int) $booking_id );
	}
}
