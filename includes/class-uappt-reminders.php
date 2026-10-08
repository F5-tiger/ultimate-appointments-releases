<?php
/**
 * 預約提醒：前一天自動提醒客人，並發一份明日預約清單給店家 LINE 群組。
 *
 * 設計成「先算出該提醒誰，再決定用哪個管道送」，LINE 與 Email 只是可抽換的
 * 送信方式。沒設定 LINE、客人沒綁定、或推播失敗時一律降級寄 email，提醒不會
 * 整組消失。
 *
 * 去重策略：send_one() 呼叫一次 send_customer_message() 之後就**無條件**標記
 * 已提醒（不論送出成功、失敗、或完全沒有可用管道），單筆只嘗試一次，避免管道
 * 暫時失效時每 5 分鐘重複轟炸同一位客人；代價是「沒有可用管道」的那幾筆之後
 * 就算客人補綁定了 LINE 也不會補送，只能靠後台的「重新發送」手動補（見
 * UAPPT_Admin::handle_resend_reminder()，直接呼叫 send_one() 會重新標記一次）。
 * 這是刻意的取捨，不是待修的 bug——早期版本考慮過「沒有管道就不標記」，
 * 但那樣會讓完全沒綁定通知的客人每 5 分鐘被重新判斷一次，浪費排程資源。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Reminders {

	/**
	 * 店家清單「今天已經送過」的紀錄，值為 Y-m-d。
	 */
	const SHOP_DIGEST_OPTION = 'uappt_shop_digest_sent_date';

	/**
	 * @var UAPPT_Reminders|null
	 */
	protected static $instance = null;

	/**
	 * 單例。
	 *
	 * @return UAPPT_Reminders
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
		add_action( UAPPT_Cron::HOOK_REMINDERS, array( __CLASS__, 'run' ) );
	}

	/**
	 * 排程進入點：檢查是否到了發送時間，然後送客人提醒與店家清單。
	 */
	public static function run() {
		// 服務前提醒刻意放在 is_due() 的閘門之前：那個閘門是給「前一天固定時間
		// 發送一次」這種對應日期的提醒用的，服務前提醒是相對於服務開始時間，
		// 視窗每分每秒都在變，5 分鐘一次的排程 tick 本身就是唯一該有的節奏。
		// 放在閘門後面會變成「今天還沒到晚上 8 點，服務前提醒也一起不送」，
		// 兩種提醒的發送時機被錯誤綁在一起——這裡曾經真的這樣寫錯過。
		if ( get_option( 'uappt_reminder2_enabled', 0 ) ) {
			self::send_hour_reminders();
		}

		if ( ! self::is_due() ) {
			return;
		}

		$target_date = uappt_local_date( current_time( 'Y-m-d' ), '+1 day' );

		if ( get_option( 'uappt_reminder_customer_enabled', 1 ) ) {
			self::send_customer_reminders( $target_date );
		}

		if ( get_option( 'uappt_reminder_shop_enabled', 1 ) ) {
			self::maybe_send_shop_digest( $target_date );
		}
	}

	/**
	 * 服務前 N 小時的第二次提醒。**刻意不套用 is_due()**——那個「已過固定時間
	 * 就送」的閘門是給前一天那種一天一次、對應「日期」的提醒用的；這支是相對
	 * 於服務開始時間，視窗每分每秒都在變，5 分鐘一次的排程 tick 本身就是
	 * 唯一該有的節奏，不需要、也不能再疊一層「今天過了幾點才送」的條件。
	 *
	 * 發送邏輯直接照抄 send_customer_reminders()：只是換一支查詢
	 * （get_bookings_needing_hour_reminder()）跟一支去重標記
	 * （mark_hour_reminder_sent()），送出管道與範本挑選各自獨立。
	 */
	protected static function send_hour_reminders() {
		$hours = max( 0, (int) get_option( 'uappt_reminder2_hours', 3 ) );

		foreach ( UAPPT_Booking::get_bookings_needing_hour_reminder( $hours ) as $booking ) {
			do_action( 'uappt_before_send_hour_reminder', $booking );

			$message = UAPPT_Card::build( $booking, UAPPT_Card::KIND_HOUR );
			self::send_customer_message( $booking, $message, __( '服務前提醒', 'ultimate-appointments' ) );

			// 跟 send_one() 同一個取捨：不論送出結果如何都標記，避免管道暫時
			// 失效時每 5 分鐘重複轟炸同一位客人。
			UAPPT_Booking::mark_hour_reminder_sent( $booking['id'] );
		}
	}

	/**
	 * 現在是否已經過了「前一天的發送時間」。
	 *
	 * 用「已過發送時間就送」而不是「剛好等於那一分鐘才送」，是因為 WP-Cron 靠
	 * 訪客觸發，設定時間當下站上不一定有人。這樣寫的話 20:00 沒跑到，20:05 或
	 * 更晚有人進站時仍會補送（只是晚一點），不會整天漏掉。
	 *
	 * @return bool
	 */
	protected static function is_due() {
		$send_time = self::get_send_time();
		return current_time( 'H:i' ) >= $send_time;
	}

	/**
	 * 取得設定的發送時間（H:i），格式異常時退回 20:00。
	 *
	 * @return string
	 */
	public static function get_send_time() {
		$raw = (string) get_option( 'uappt_reminder_send_time', '20:00' );
		return preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $raw ) ? $raw : '20:00';
	}

	/**
	 * 送出指定日期所有尚未提醒過的客人提醒。
	 *
	 * @param string $date_ymd 目標日期 (Y-m-d)。
	 */
	protected static function send_customer_reminders( $date_ymd ) {
		foreach ( UAPPT_Booking::get_bookings_needing_reminder( $date_ymd ) as $booking ) {
			self::send_one( $booking );
		}
	}

	/**
	 * 對單筆預約送出提醒。
	 *
	 * 「是否已經提醒過」的判斷在 get_bookings_needing_reminder() 的查詢條件裡，
	 * 不在這裡；所以後台手動重送只要直接呼叫這支就會重新發送一次。
	 *
	 * @param array $booking 預約紀錄。
	 * @return true|WP_Error
	 */
	public static function send_one( $booking ) {
		do_action( 'uappt_before_send_reminder', $booking );

		$message = UAPPT_Card::build( $booking, UAPPT_Card::KIND_DAY );
		$result  = self::send_customer_message( $booking, $message );

		// 無條件標記（不論 $result 是成功還是 WP_Error），避免每 5 分鐘重試
		// 同一筆——見檔案開頭的去重策略說明。
		UAPPT_Booking::mark_reminder_sent( $booking['id'] );

		return $result;
	}

	/**
	 * 挑管道把一則訊息送給某筆預約的客人：LINE 可用且客人允許就推播，沒綁定或
	 * 推播失敗就降級寄 Email，兩者都不可用就回傳 WP_Error。送出結果一律記一條
	 * 訂單備註。
	 *
	 * 從 send_one() 抽出來的共用邏輯：管道挑選、失敗降級、訂單備註這套規則跟
	 * 「訊息內容是什麼」無關，換人通知（UAPPT_Admin::handle_reassign_staff()）
	 * 要送的也是同一套規則，不該各自維護一份。**這支不會標記提醒已發送**——
	 * 那是 send_one() 自己的責任，其他呼叫端不需要、也不該去動那個標記。
	 *
	 * @param array  $booking       預約紀錄。
	 * @param string $message       訊息本文。
	 * @param string $context_label 通知類別，用在信件主旨與訂單備註（例如「服務人員異動通知」）；
	 *                              留空沿用預設的「預約提醒」，維持既有提醒信的主旨與備註文字不變。
	 * @return true|WP_Error
	 */
	public static function send_customer_message( $booking, $content, $context_label = '' ) {
		$context_label = '' !== $context_label ? $context_label : __( '預約提醒', 'ultimate-appointments' );

		// ⚠️ **兩個管道拿到的是同一份內容的兩種畫法，不是兩份內容。**
		// 以前這支收一個字串、同時餵給 LINE 與 Email；改成 LINE 走 Flex 卡片
		// 之後，若讓呼叫端各準備一份，改欄位時一定會有一邊漏掉（而且多半是
		// Email，因為測試都在看 LINE）。所以這裡只收 UAPPT_Card::build() 的結果，
		// 渲染在這一層做。
		//
		// 舊呼叫端若還傳字串進來，退化成純文字送出——不讓它靜默失敗。
		$is_card = is_array( $content );
		$message = $is_card ? UAPPT_Card::render_text( $content ) : (string) $content;

		$customer_id = isset( $booking['customer_id'] ) ? (int) $booking['customer_id'] : 0;

		$line_id = '';
		if ( UAPPT_Line::is_available() && UAPPT_Line::user_allows_line( $customer_id ) ) {
			$line_id = UAPPT_Line::resolve_booking_line_id( $booking );
		}

		$email    = self::resolve_email( $booking );
		$email_ok = ( '' !== $email ) && UAPPT_Line::user_allows_email( $customer_id );

		// 兩個管道都不可用：回傳錯誤讓呼叫端知道沒送出（後台「重新發送」會顯示）。
		// 排程那邊照樣會標記已提醒，不會每 5 分鐘重試——見檔案開頭的去重策略。
		if ( '' === $line_id && ! $email_ok ) {
			return new WP_Error(
				'uappt_no_channel',
				__( '這位客人目前沒有可用的通知管道（未綁定 LINE、或已關閉通知、且沒有 email）。', 'ultimate-appointments' )
			);
		}

		$sent  = false;
		$notes = array();

		if ( '' !== $line_id ) {
			$result = $is_card
				? UAPPT_Line::push_flex( $line_id, UAPPT_Card::render_flex( $content ) )
				: UAPPT_Line::push_text( $line_id, $message );
			if ( true === $result ) {
				$sent = true;
				/* translators: %s: 通知類別，例如「預約提醒」「服務人員異動通知」 */
				$notes[] = sprintf( __( '已透過 LINE 發送%s。', 'ultimate-appointments' ), $context_label );
			} else {
				$notes[] = sprintf(
					/* translators: 1: 通知類別 2: LINE 回傳的錯誤描述 */
					__( 'LINE %1$s發送失敗：%2$s', 'ultimate-appointments' ),
					$context_label,
					$result
				);
			}
		}

		// LINE 沒送成功（沒綁定或推播失敗）就降級寄 email。
		if ( ! $sent && $email_ok ) {
			if ( self::send_email( $email, $booking, $message, $context_label ) ) {
				$sent = true;
				/* translators: %s: 通知類別 */
				$notes[] = sprintf( __( '已透過 Email 發送%s。', 'ultimate-appointments' ), $context_label );
			} else {
				/* translators: %s: 通知類別 */
				$notes[] = sprintf( __( 'Email %s發送失敗。', 'ultimate-appointments' ), $context_label );
			}
		}

		self::log_to_order( $booking, $notes );

		if ( ! $sent ) {
			return new WP_Error( 'uappt_send_failed', implode( ' ', $notes ) );
		}

		return true;
	}

	/**
	 * 通知客人服務人員異動。換人本身（含待分派確認）是否成立由
	 * UAPPT_Booking::reassign_staff() 決定，這支只負責在管理者勾選「通知客人」時
	 * 把結果送出去——換人失敗或沒勾通知都不會走到這裡。
	 *
	 * @param array  $booking        更換後的預約紀錄。
	 * @param string $new_staff_name 新的服務人員姓名。
	 * @return true|WP_Error
	 */
	public static function send_staff_change_notice( $booking, $new_staff_name ) {
		$name = trim( (string) $booking['customer_name'] );
		if ( '' === $name && ! empty( $booking['customer_id'] ) ) {
			$user = get_user_by( 'id', $booking['customer_id'] );
			$name = $user ? $user->display_name : '';
		}

		// 這則以前是寫死的 sprintf，跟兩則提醒各走各的。改成同一個卡片建構器，
		// 客人才不會收到「提醒是卡片、換人是純文字」兩種長相。
		// KIND_STAFF_CHANGE 會把新人員姓名固定放進「服務人員」那一列——那是
		// 這則通知的重點，不適用「只有客人主動指定才顯示」的規則。
		$content = UAPPT_Card::build( $booking, UAPPT_Card::KIND_STAFF_CHANGE, $new_staff_name );

		return self::send_customer_message( $booking, $content, __( '服務人員異動通知', 'ultimate-appointments' ) );
	}

	/**
	 * 送店家的明日預約清單（一天一次）。
	 *
	 * @param string $date_ymd 目標日期 (Y-m-d)。
	 */
	protected static function maybe_send_shop_digest( $date_ymd ) {
		if ( get_option( self::SHOP_DIGEST_OPTION ) === $date_ymd ) {
			return;
		}

		$targets = UAPPT_Line::get_shop_targets();
		if ( empty( $targets ) || ! UAPPT_Line::is_available() ) {
			return;
		}

		$bookings = UAPPT_Booking::get_bookings_for_date( $date_ymd );

		// 沒有預約也送一則，店家才知道「今天真的沒約」而不是系統壞了。
		$message = self::build_shop_digest( $date_ymd, $bookings );

		foreach ( $targets as $target ) {
			UAPPT_Line::push_text( $target, $message );
		}

		update_option( self::SHOP_DIGEST_OPTION, $date_ymd );
	}

	/**
	 * 組出給店家的明日預約清單。
	 *
	 * @param string $date_ymd 日期 (Y-m-d)。
	 * @param array  $bookings 該日預約。
	 * @return string
	 */
	protected static function build_shop_digest( $date_ymd, $bookings ) {
		$lines = array(
			sprintf(
				/* translators: %s: 日期 */
				__( '【明日預約清單】%s', 'ultimate-appointments' ),
				$date_ymd
			),
			'',
		);

		if ( empty( $bookings ) ) {
			$lines[] = __( '明天沒有預約。', 'ultimate-appointments' );
			return implode( "\n", $lines );
		}

		foreach ( $bookings as $booking ) {
			$start_dt = date_create( $booking['service_start'], wp_timezone() );
			$time     = $start_dt ? wp_date( 'H:i', $start_dt->getTimestamp() ) : '';

			$name = trim( (string) $booking['customer_name'] );
			if ( '' === $name && ! empty( $booking['customer_id'] ) ) {
				$user = get_user_by( 'id', $booking['customer_id'] );
				$name = $user ? $user->display_name : '';
			}

			// 待付款（held 但已有訂單）額外標註，店家看得出這幾筆款項還沒對到，
			// 客人到店前最好先確認一下——見 get_bookings_for_date() 的說明。
			$pending_flag = UAPPT_Admin::is_awaiting_payment( $booking ) ? ' ' . __( '【待付款】', 'ultimate-appointments' ) : '';

			$lines[] = sprintf(
				'%1$s　%2$s　%3$s%4$s%5$s',
				$time,
				UAPPT_Booking::get_booking_display_name( $booking ),
				'' !== $name ? $name : __( '（未填姓名）', 'ultimate-appointments' ),
				! empty( $booking['customer_phone'] ) ? ' ' . $booking['customer_phone'] : '',
				$pending_flag
			);
		}

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %d: 預約筆數 */
			__( '共 %d 筆', 'ultimate-appointments' ),
			count( $bookings )
		);

		return implode( "\n", $lines );
	}

	/**
	 * 取得該筆預約可用的收件 email。
	 *
	 * @param array $booking 預約紀錄。
	 * @return string
	 */
	protected static function resolve_email( $booking ) {
		if ( ! empty( $booking['order_id'] ) ) {
			$order = wc_get_order( $booking['order_id'] );
			if ( $order && $order->get_billing_email() ) {
				return $order->get_billing_email();
			}
		}

		if ( ! empty( $booking['customer_id'] ) ) {
			$user = get_user_by( 'id', $booking['customer_id'] );
			if ( $user && $user->user_email ) {
				return $user->user_email;
			}
		}

		return '';
	}

	/**
	 * 寄出通知信。
	 *
	 * @param string $email         收件者。
	 * @param array  $booking       預約紀錄。
	 * @param string $message       訊息本文（與 LINE 共用同一份範本內容）。
	 * @param string $context_label 通知類別，用在信件主旨；留空沿用預設的「預約提醒」。
	 * @return bool
	 */
	protected static function send_email( $email, $booking, $message, $context_label = '' ) {
		$context_label = '' !== $context_label ? $context_label : __( '預約提醒', 'ultimate-appointments' );

		$subject = sprintf(
			/* translators: 1: 店名 2: 通知類別 */
			__( '【%1$s】%2$s', 'ultimate-appointments' ),
			get_bloginfo( 'name' ),
			$context_label
		);

		$body = nl2br( esc_html( $message ) );

		// 信件裡另外附上兩種行事曆連結，比訊息內的純網址好點。
		$body .= '<p>' . UAPPT_Calendar::render_links( $booking ) . '</p>';

		return (bool) wp_mail(
			$email,
			$subject,
			$body,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * 把發送結果寫進訂單備註，讓後台查得到「到底有沒有送、為什麼失敗」。
	 *
	 * @param array $booking 預約紀錄。
	 * @param array $notes   要記錄的訊息。
	 */
	protected static function log_to_order( $booking, $notes ) {
		if ( empty( $notes ) || empty( $booking['order_id'] ) ) {
			return;
		}

		$order = wc_get_order( $booking['order_id'] );
		if ( ! $order ) {
			return;
		}

		$order->add_order_note( implode( ' ', $notes ) );
	}
}
