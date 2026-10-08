<?php
/**
 * LINE Messaging API 推播（預約提醒用）。
 *
 * 這個站已經裝了「終極登入」（ultimate-login，前身是「社交登入 & 訂單通知」
 * wc-line-order-notify，改名不改 WCLON_* / WCAN_* class，見該外掛更新紀錄）
 * 外掛，客人的 LINE 綁定、Channel access token、店家群組 ID 都在那邊維護。這支類別刻意
 * **不重複造輪子**：token、userId、通知開關、群組 ID 一律沿用該外掛的公開 API，
 * 只有「推播」這個動作要自己實作——因為對方的 push_message() 是 private，
 * 拿不到，只好照它的作法寫一份等價的。
 *
 * 所有對該外掛的存取都包 class_exists() / method_exists()，對方停用或沒設定
 * 時 get_token() 就回傳空字串、get_shop_targets() 回傳空陣列——本外掛不再
 * 提供自己的 token／群組覆寫欄位，LINE 通知只有「終極登入」一個地方要設定，
 * 不會整組壞掉，只是暫時沒有 LINE 推播能力（提醒會自動改用 Email）。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Line {

	const API_PUSH = 'https://api.line.me/v2/bot/message/push';

	/**
	 * 既有外掛儲存客人 LINE userId 的 user meta key。
	 *
	 * 正常情況會直接讀 WCLON_Line_Login::USER_META_KEY，這個常數只是該外掛
	 * 未載入時的後備值（值相同）。
	 */
	const FALLBACK_USER_META_KEY = '_wclon_line_user_id';

	/**
	 * 取得 Channel access token。
	 *
	 * 一律沿用「終極登入」外掛已經設定好的那一組，本外掛不再提供自己的
	 * 覆寫欄位——LINE 的串接設定只有一個地方要維護，不然兩邊都能填，
	 * 改了一邊卻忘記另一邊會很難排查。該外掛沒啟用或沒設定時就是沒有
	 * token，直接回傳空字串（提醒會自動改用 Email，見 is_available()）。
	 *
	 * @return string
	 */
	public static function get_token() {
		if ( class_exists( 'WCLON_Settings' ) ) {
			$token = WCLON_Settings::get( 'channel_access_token' );
			if ( $token ) {
				return $token;
			}
		}

		return '';
	}

	/**
	 * 是否具備推播能力（有 token）。
	 *
	 * @return bool
	 */
	public static function is_available() {
		return '' !== self::get_token();
	}

	/**
	 * 取得某會員的 LINE userId。
	 *
	 * @param int $user_id WP 使用者 ID。
	 * @return string 沒綁定則回傳空字串。
	 */
	public static function get_user_line_id( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return '';
		}

		$meta_key = class_exists( 'WCLON_Line_Login' ) ? WCLON_Line_Login::USER_META_KEY : self::FALLBACK_USER_META_KEY;

		return (string) get_user_meta( $user_id, $meta_key, true );
	}

	/**
	 * 從預約紀錄解析出可推播的 LINE userId。
	 *
	 * 優先用訂單解析（既有外掛的 resolve_line_user_id() 連訪客結帳時寫在訂單
	 * meta 上的 userId 都涵蓋得到），沒有訂單才退回用會員帳號查。
	 *
	 * @param array $booking 預約紀錄。
	 * @return string
	 */
	public static function resolve_booking_line_id( $booking ) {
		if ( ! empty( $booking['order_id'] ) && class_exists( 'WCLON_Notifier' ) && method_exists( 'WCLON_Notifier', 'resolve_line_user_id' ) ) {
			$order = wc_get_order( $booking['order_id'] );
			if ( $order ) {
				$line_id = WCLON_Notifier::resolve_line_user_id( $order );
				if ( $line_id ) {
					return (string) $line_id;
				}
			}
		}

		return self::get_user_line_id( isset( $booking['customer_id'] ) ? $booking['customer_id'] : 0 );
	}

	/**
	 * 客人是否允許接收 LINE 通知。
	 *
	 * 客人可以在既有外掛的「帳號綁定」頁自行關閉 LINE 通知，這個意願一定要
	 * 尊重——無視客人明確關閉的設定去推播，最後只會換來封鎖官方帳號。
	 * 訪客（沒有會員帳號）沒有這個開關，視為允許。
	 *
	 * @param int $user_id WP 使用者 ID，訪客傳 0。
	 * @return bool
	 */
	public static function user_allows_line( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return true;
		}

		if ( class_exists( 'WCLON_Line_Login' ) && method_exists( 'WCLON_Line_Login', 'get_notify_enabled' ) ) {
			return (bool) WCLON_Line_Login::get_notify_enabled( $user_id );
		}

		return true;
	}

	/**
	 * 客人是否允許接收 Email 通知（提醒改寄 email 前要先確認）。
	 *
	 * @param int $user_id WP 使用者 ID，訪客傳 0。
	 * @return bool
	 */
	public static function user_allows_email( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return true;
		}

		if ( class_exists( 'WCLON_Notifier' ) && method_exists( 'WCLON_Notifier', 'get_email_notify_enabled' ) ) {
			return (bool) WCLON_Notifier::get_email_notify_enabled( $user_id );
		}

		return true;
	}

	/**
	 * 取得店家收提醒清單的目標 ID 清單（LINE 群組）。
	 *
	 * 沿用既有外掛「管理員群組通知」已經設定好的群組，不要求管理員再設定一次。
	 *
	 * @return array
	 */
	public static function get_shop_targets() {
		$targets = array();

		if ( class_exists( 'WCAN_Settings' ) ) {
			$group_ids = WCAN_Settings::get( 'group_ids', array() );
			if ( is_array( $group_ids ) ) {
				$targets = $group_ids;
			}
		}

		return array_values( array_unique( array_filter( $targets ) ) );
	}

	/**
	 * 推播純文字訊息。
	 *
	 * @param string $to   LINE userId 或 groupId。
	 * @param string $text 訊息內容。
	 * @return true|string 成功回傳 true，失敗回傳可讀的錯誤描述。
	 */
	public static function push_text( $to, $text ) {
		$text = (string) apply_filters( 'uappt_line_message_text', $text, $to );

		if ( '' === trim( $text ) ) {
			return __( '訊息內容是空的。', 'ultimate-appointments' );
		}

		return self::push_message(
			$to,
			array(
				'type' => 'text',
				'text' => $text,
			)
		);
	}

	/**
	 * 推播 Flex 卡片。
	 *
	 * @param string $to      LINE userId 或 groupId。
	 * @param array  $message UAPPT_Card::render_flex() 的結果。
	 * @return true|string
	 */
	public static function push_flex( $to, array $message ) {
		if ( empty( $message['contents'] ) ) {
			return __( '卡片內容是空的。', 'ultimate-appointments' );
		}
		return self::push_message( $to, $message );
	}

	/**
	 * 實際送出一則訊息。
	 *
	 * 原本這一段寫死在 push_text() 裡、訊息型別固定是 text。抽出來是為了讓
	 * Flex 走同一條路——錯誤碼翻譯、Token 取得、逾時設定這些都不該有第二份。
	 *
	 * @param string $to      收件 ID。
	 * @param array  $message 完整的 LINE 訊息物件。
	 * @return true|string
	 */
	protected static function push_message( $to, array $message ) {
		$to = trim( (string) $to );

		if ( '' === $to ) {
			return __( '沒有可推播的 LINE ID。', 'ultimate-appointments' );
		}

		$token = self::get_token();
		if ( '' === $token ) {
			return __( '尚未設定 LINE Channel Access Token（請確認「終極登入」外掛已設定，或在本外掛設定頁填入）。', 'ultimate-appointments' );
		}

		$response = wp_remote_post(
			self::API_PUSH,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => wp_json_encode(
					array(
						'to'       => $to,
						'messages' => array( $message ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return true;
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = isset( $body['message'] ) ? $body['message'] : __( '未知錯誤', 'ultimate-appointments' );

		// 把 LINE 的狀態碼翻成實際該去查什麼，否則設定錯了很難自己找出原因。
		if ( 403 === $code ) {
			$message .= __( '（客人可能尚未加官方帳號好友或已封鎖；若是群組，請確認官方帳號仍在該群組內）', 'ultimate-appointments' );
		} elseif ( 401 === $code ) {
			$message .= __( '（Access Token 無效或已過期）', 'ultimate-appointments' );
		} elseif ( 429 === $code ) {
			$message .= __( '（已達本月訊息額度上限）', 'ultimate-appointments' );
		} elseif ( 400 === $code ) {
			$message .= __( '（收件 ID 格式錯誤，或該 userId 不屬於這個 Messaging API channel——請確認提供登入的 LINE Login channel 與 Messaging API channel 在同一個 Provider 底下）', 'ultimate-appointments' );
		}

		return sprintf( 'HTTP %d - %s', $code, $message );
	}
}
